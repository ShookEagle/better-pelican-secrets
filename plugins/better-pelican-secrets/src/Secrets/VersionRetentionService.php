<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Plans\RuntimeReferences;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretVersion;

final class VersionRetentionService
{
    public function __construct(private SecretAuthorizer $auth, private PlanService $plans, private RuntimeReferences $runtime, private AuditRecorder $audit) {}

    public function reencrypt(User $actor, int $serverId): int
    {
        if (!$this->auth->isRoot($actor)) {
            throw new AuthorizationException('Secret access denied.');
        }
        try {
            return DB::transaction(function () use ($actor, $serverId) {
                Server::query()->lockForUpdate()->findOrFail($serverId);
                $encrypter = app(Encrypter::class);
                $count = 0;
                foreach (Secret::where('server_id', $serverId)->lockForUpdate()->get(['id']) as $secret) {
                    foreach (SecretVersion::where('secret_id', $secret->id)->lockForUpdate()->get() as $version) {
                        $value = new SecretValue($encrypter->decrypt($version->encrypted_value, false));
                        // Physical key rotation preserves the immutable logical version.
                        DB::table('bps_secret_versions')->where('id', $version->id)
                            ->update(['encrypted_value' => $encrypter->encrypt($value->bytes(), false)]);
                        $count++;
                    }
                }
                $this->audit->record($actor, 'version.reencrypt', ['server_id' => $serverId, 'count' => $count]);

                return $count;
            }, 3);
        } catch (\Throwable) {
            throw new \DomainException('Secret re-encryption failed.');
        }
    }

    /** Trusted host scheduler/CLI only. No public route exposes this operation. */
    public function pruneAudit(): int
    {
        $days = config('better-pelican-secrets.audit_retention_days', 90);
        if (!is_int($days) || $days < 1 || $days > 36500) {
            throw new \DomainException('Invalid audit retention policy.');
        }

        return DB::transaction(function () use ($days) {
            $count = Models\SecretAuditEvent::where('created_at', '<', now()->subDays($days))->delete();
            $this->audit->recordHost('audit.prune', ['count' => $count]);

            return $count;
        }, 3);
    }

    public function purgeUnreferenced(User $actor, int $serverId): int
    {
        if (!$this->auth->isRoot($actor)) {
            throw new AuthorizationException('Secret access denied.');
        }

        return DB::transaction(function () use ($actor, $serverId) {
            $server = Server::query()->lockForUpdate()->findOrFail($serverId);
            $plan = $this->plans->current($server);
            $keep = $plan->toWire()['versions'];
            foreach (Secret::where('server_id', $serverId)->lockForUpdate()->get() as $secret) {
                $keep[] = ['secret_id' => $secret->id, 'version' => $secret->current_version];
            }
            $keep = [...$keep, ...$this->runtime->versions($server)];
            $keys = array_fill_keys(array_map(fn ($ref) => $ref['secret_id'] . ':' . $ref['version'], $keep), true);
            $ids = [];
            foreach (SecretVersion::whereIn('secret_id', Secret::where('server_id', $serverId)->select('id'))->get(['id', 'secret_id', 'version']) as $version) {
                if (!isset($keys[$version->secret_id . ':' . $version->version])) {
                    $ids[] = $version->id;
                }
            }
            $removed = $ids ? SecretVersion::whereIn('id', $ids)->delete() : 0;
            $this->audit->record($actor, 'version.purge', ['server_id' => $serverId, 'count' => $removed]);

            return $removed;
        }, 3);
    }
}
