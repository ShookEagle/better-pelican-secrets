<?php

namespace ShookEagle\BetterPelicanSecrets\Plans;

use App\Models\EggVariable;
use App\Models\Server;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ShookEagle\BetterPelicanSecrets\Plans\Models\DesiredPlanRecord;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ManagedFile;
use ShookEagle\BetterPelicanSecrets\Plans\Models\StartupBinding;
use ShookEagle\BetterPelicanSecrets\Secrets\AuditRecorder;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretGrant;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateSourceService;

final class ServerLifecycleService
{
    public function __construct(private PlanService $plans, private RuntimeReferences $runtime) {}

    public function invalidate(Server $server, string $reason): void
    {
        if (!in_array($reason, ['restore', 'reinstall', 'egg', 'image', 'transfer'], true)) {
            throw new DomainException('protocol_invalid');
        }
        if (ManagedFile::where('server_id', $server->id)->exists() || StartupBinding::where('server_id', $server->id)->exists()) {
            $this->plans->rebuild($server);
        }
    }

    public function assertStartable(Server $server): void
    {
        if (DB::table('bps_setup_failures')->where('server_id', $server->id)->exists()) {
            throw new DomainException('template_invalid');
        }
        $known = EggVariable::where('egg_id', $server->egg_id)->pluck('env_variable')->all();
        foreach (StartupBinding::where('server_id', $server->id)->pluck('env') as $env) {
            if (!in_array($env, $known, true)) {
                throw new DomainException('protocol_invalid');
            }
        }
    }

    /** Called from native deletion; ordinary model FKs also enforce the same scope. */
    public function delete(Server $server): void
    {
        DB::transaction(function () use ($server) {
            $count = Secret::where('server_id', $server->id)->count();
            if ($count) {
                $audit = app(AuditRecorder::class);
                $metadata = ['server_id' => $server->id, 'count' => $count];
                if (auth()->user()) {
                    $audit->record(auth()->user(), 'server.delete', $metadata);
                } else {
                    $audit->recordHost('server.delete', $metadata);
                }
            }
            SecretGrant::where('server_id', $server->id)->delete();
            ManagedFile::where('server_id', $server->id)->delete();
            StartupBinding::where('server_id', $server->id)->delete();
            Secret::where('server_id', $server->id)->delete();
            // Metadata-only audit intentionally has no server FK and survives deletion.
        }, 3);
    }

    /** Trusted host recovery only; it neither edits sources nor retires applied refs. */
    public function retireIncompleteSetup(User $actor, int $serverId, string $failureId, string $expectedDigest): void
    {
        if (!app(SecretAuthorizer::class)->isRoot($actor)) {
            throw new AuthorizationException('Secret access denied.');
        }
        DB::transaction(function () use ($actor, $serverId, $failureId, $expectedDigest) {
            $server = Server::query()->lockForUpdate()->findOrFail($serverId);
            $failure = DB::table('bps_setup_failures')->where('server_id', $serverId)->where('file_id', $failureId)->first();
            if (!$failure || !$failure->source || ManagedFile::where('server_id', $serverId)
                ->where(fn ($query) => $query->where('id', $failureId)->orWhere('source', $failure->source))->exists()) {
                throw new DomainException('template_invalid');
            }
            $source = app(TemplateSourceService::class)->readPath($actor, $server, $failure->source, true);
            $digest = $source === null ? 'absent' : hash('sha256', $source);
            if (!hash_equals($digest, $expectedDigest)) {
                throw new DomainException('source_conflict');
            }
            DB::table('bps_setup_failures')->where('server_id', $serverId)->where('file_id', $failureId)->delete();
            app(AuditRecorder::class)->record($actor, 'setup.retire', ['server_id' => $serverId, 'file_id' => $failureId]);
        }, 3);
    }

    public function assertIntegrationCanDisable(): void
    {
        // Native uninstall(deleteFiles: true) re-enters its delete guard after
        // successful migration rollback. A fully absent schema is already retired;
        // a partial schema continues to fail closed.
        $tables = ['bps_secrets', 'bps_secret_versions', 'bps_secret_grants', 'bps_managed_files',
            'bps_startup_bindings', 'bps_desired_plan_records', 'bps_application_records', 'bps_source_heads',
            'bps_setup_failures', 'bps_node_retirements'];
        if (!array_any($tables, fn ($table) => Schema::hasTable($table))) {
            return;
        }
        if (ManagedFile::exists() || StartupBinding::exists() || DB::table('bps_setup_failures')->exists()) {
            throw new DomainException('secret_in_use');
        }
        foreach (Server::query()->get() as $server) {
            $latest = DesiredPlanRecord::where('server_id', $server->id)->orderByDesc('id')->first();
            if (!$latest) {
                continue;
            }
            $plan = $this->plans->current($server);
            $applied = collect($this->runtime->applied($server))->firstWhere('node_id', $server->node_id);
            if ($plan->versions || $this->runtime->versions($server) || !$applied
                || $applied->revision !== $plan->revision || $applied->payload['versions']) {
                throw new DomainException('secret_in_use');
            }
        }
    }
}
