<?php

namespace ShookEagle\BetterPelicanSecrets\Plans;

use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Node\DesiredPlan;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Node\NodeCapabilityService;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ManagedFile;
use ShookEagle\BetterPelicanSecrets\Plans\Models\StartupBinding;
use ShookEagle\BetterPelicanSecrets\Secrets\AuditRecorder;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateCompiler;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateDocument;

final class BindingService
{
    public function __construct(private SecretAuthorizer $authorizer, private PlanService $plans,
        private TemplateCompiler $compiler, private AuditRecorder $audit) {}

    public function saveFile(User $actor, Server $server, FilePlan $file, string $templateSource): DesiredPlan
    {
        return DB::transaction(function () use ($actor, $server, $file, $templateSource) {
            $server = $this->lockServer($actor, $server, 'file.update');
            $this->preflightFile($actor, $server, $file, $templateSource);
            $attributes = ['server_id' => $server->id, 'source' => $file->source, 'destination' => $file->destination,
                'source_key' => hash('sha256', $file->source), 'destination_key' => hash('sha256', $file->destination),
                'format' => $file->format, 'default_mode' => $file->defaultMode, 'manifest' => $file->manifest,
                'source_digest' => hash('sha256', $templateSource)];
            ManagedFile::query()->updateOrCreate(['id' => $file->fileId], $attributes);
            $plan = $this->plans->rebuild($server);
            $this->audit->record($actor, 'binding.file.save', ['server_id' => $server->id, 'file_id' => $file->fileId, 'revision' => $plan->revision]);

            return $plan;
        }, 3);
    }

    /** Validate every local constraint and capability before changing a remote source. */
    public function preflightFile(User $actor, Server $server, FilePlan $file, string $templateSource): void
    {
        $this->lockServer($actor, $server, 'file.update');
        $existing = ManagedFile::query()->find($file->fileId);
        if ($existing && $existing->server_id !== $server->id) {
            throw new AuthorizationException('Secret access denied.');
        }
        $compiled = $this->compiler->compile(new TemplateDocument($templateSource, $file->format, $file->defaultMode, $file->manifest));
        $this->authorizeManifest($actor, $server, $file->manifest);
        $this->authorizeManifest($actor, $server, $existing?->manifest ?? []);
        app(NodeCapabilityService::class)->assertCompatible($server->node);
        $dummy = [];
        foreach ($file->manifest as $entry) {
            $dummy[$entry['secret_id']] = 'DUMMY_VALUE';
        }
        $this->compiler->render($compiled, $dummy);
        $this->plans->validateFile($server, $file);
    }

    public function bindStartup(User $actor, Server $server, string $env, string $secretId): DesiredPlan
    {
        return DB::transaction(function () use ($actor, $server, $env, $secretId) {
            $server = $this->lockServer($actor, $server, 'startup.update');
            $this->authorizer->authorize($actor, SecretOperation::Bind, new SecretScope($server->id, $secretId));
            $variable = EggVariable::query()->where('egg_id', $server->egg_id)->where('env_variable', $env)->first();
            if (!$variable || $variable->env_variable !== $env) {
                throw new DomainException('startup_variable_unknown');
            }
            $existing = StartupBinding::query()->where('server_id', $server->id)->where('env', $env)->first();
            if ($existing) {
                $this->authorizer->authorize($actor, SecretOperation::Bind, new SecretScope($server->id, $existing->secret_id));
            }
            $ordinary = ServerVariable::query()->where('server_id', $server->id)->where('variable_id', $variable->id)->first();
            if (($ordinary ? $ordinary->variable_value : $variable->default_value) !== '') {
                throw new DomainException('startup_migration_required');
            }
            app(NodeCapabilityService::class)->assertCompatible($server->node);
            StartupBinding::query()->updateOrCreate(['server_id' => $server->id, 'env' => $env], ['secret_id' => $secretId]);
            $plan = $this->plans->rebuild($server);
            $this->audit->record($actor, 'binding.startup.bind', ['server_id' => $server->id, 'secret_id' => $secretId, 'env' => $env, 'revision' => $plan->revision]);

            return $plan;
        }, 3);
    }

    public function removeFile(User $actor, Server $server, string $fileId): DesiredPlan
    {
        return DB::transaction(function () use ($actor, $server, $fileId) {
            $server = $this->lockServer($actor, $server, 'file.update');
            $file = ManagedFile::query()->where('server_id', $server->id)->findOrFail($fileId);
            $this->authorizeManifest($actor, $server, $file->manifest);
            $file->delete();
            $plan = $this->plans->rebuild($server);
            $this->audit->record($actor, 'binding.file.remove', ['server_id' => $server->id, 'file_id' => $fileId, 'revision' => $plan->revision]);

            return $plan;
        }, 3);
    }

    public function unbindStartup(User $actor, Server $server, string $env): DesiredPlan
    {
        return DB::transaction(function () use ($actor, $server, $env) {
            $server = $this->lockServer($actor, $server, 'startup.update');
            $binding = StartupBinding::query()->where('server_id', $server->id)->where('env', $env)->firstOrFail();
            $this->authorizer->authorize($actor, SecretOperation::Bind, new SecretScope($server->id, $binding->secret_id));
            $binding->delete();
            $plan = $this->plans->rebuild($server);
            $this->audit->record($actor, 'binding.startup.unbind', ['server_id' => $server->id, 'env' => $env, 'revision' => $plan->revision]);

            return $plan;
        }, 3);
    }

    public function deleteSecret(User $actor, Server $server, string $secretId, bool $admin = false): void
    {
        DB::transaction(function () use ($actor, $server, $secretId, $admin) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            $authorize = $admin ? 'authorizeAdmin' : 'authorize';
            $this->authorizer->$authorize($actor, SecretOperation::Delete, new SecretScope($server->id, $secretId));
            if (StartupBinding::query()->where('server_id', $server->id)->where('secret_id', $secretId)->exists()) {
                throw new DomainException('secret_in_use');
            }
            foreach (ManagedFile::query()->where('server_id', $server->id)->get() as $file) {
                foreach ($file->manifest as $entry) {
                    if ($entry['secret_id'] === $secretId) {
                        throw new DomainException('secret_in_use');
                    }
                }
            }
            app(SecretStore::class)->delete($secretId, $actor);
        }, 3);
    }

    private function lockServer(User $actor, Server $server, string $ordinaryPermission): Server
    {
        $this->authorizer->assertMember($actor, $server->id);
        $server = Server::query()->lockForUpdate()->findOrFail($server->id);
        if (!$actor->can($ordinaryPermission, $server)) {
            throw new AuthorizationException('File or startup access denied.');
        }

        return $server;
    }

    private function authorizeManifest(User $actor, Server $server, array $manifest): void
    {
        foreach ($manifest as $entry) {
            $this->authorizer->authorize($actor, SecretOperation::Bind, new SecretScope($server->id, $entry['secret_id']));
            $secret = Secret::query()->where('server_id', $server->id)->findOrFail($entry['secret_id']);
            if ($secret->name !== $entry['name']) {
                throw new DomainException('manifest_mismatch');
            }
        }
    }
}
