<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Models\Server;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretVersion;
use Throwable;

/** Internal persistence boundary. Public callers must authorize and check bindings first. */
final class SecretStore
{
    public function __construct(private Encrypter $encrypter, private AuditRecorder $audit) {}

    public function create(Server $server, string $name, string $description, #[\SensitiveParameter] SecretValue $value, User $actor): SecretMetadata
    {
        if (!preg_match('/\A[A-Z][A-Z0-9_]{0,63}\z/D', $name)) {
            throw new DomainException('Invalid secret name.');
        }
        $this->validateDescription($description);
        try {
            return DB::transaction(function () use ($server, $name, $description, $value, $actor) {
                $secret = Secret::query()->create([
                    'id' => Str::uuid()->toString(), 'server_id' => $server->id, 'name' => $name,
                    'description' => $description, 'current_version' => 1, 'created_by' => $actor->id,
                ]);
                $this->writeVersion($secret, 1, $value, $actor);
                $this->audit->record($actor, 'secret.create', $this->auditMetadata($secret));

                return $this->toMetadata($secret);
            }, 3);
        } catch (Throwable) {
            throw new DomainException('Secret creation failed.');
        }
    }

    public function replace(string $secretId, #[\SensitiveParameter] SecretValue $value, User $actor): SecretVersionRef
    {
        try {
            $reference = DB::transaction(function () use ($secretId, $value, $actor) {
                $secret = Secret::query()->lockForUpdate()->findOrFail($secretId);
                $version = $secret->current_version + 1;
                $this->writeVersion($secret, $version, $value, $actor);
                $secret->update(['current_version' => $version]);
                $this->audit->record($actor, 'secret.replace', $this->auditMetadata($secret));

                return new SecretVersionRef($secret->id, $version);
            }, 3);
            DB::afterCommit(static function () use ($secretId) {
                try {
                    app(PlanService::class)->markSecretChanged($secretId);
                } catch (Throwable) {
                    // current()/snapshot() reconcile authoritative versions before any delivery.
                }
            });

            return $reference;
        } catch (Throwable) {
            throw new DomainException('Secret replacement failed.');
        }
    }

    public function updateDescription(string $secretId, string $description, User $actor): void
    {
        $this->validateDescription($description);
        try {
            DB::transaction(function () use ($secretId, $description, $actor) {
                $secret = Secret::query()->lockForUpdate()->findOrFail($secretId);
                $secret->update(['description' => $description]);
                $this->audit->record($actor, 'secret.description', $this->auditMetadata($secret));
            }, 3);
        } catch (Throwable) {
            throw new DomainException('Secret description update failed.');
        }
    }

    public function metadata(string $secretId): SecretMetadata
    {
        try {
            return $this->toMetadata(Secret::query()->findOrFail($secretId));
        } catch (Throwable) {
            throw new DomainException('Secret metadata unavailable.');
        }
    }

    /** Runtime/internal access. The public reveal action separately authorizes and audits. */
    public function reveal(SecretVersionRef $version): SecretValue
    {
        try {
            $row = SecretVersion::query()->where('secret_id', $version->secretId)->where('version', $version->version)->firstOrFail();

            return new SecretValue($this->encrypter->decrypt($row->encrypted_value, false));
        } catch (Throwable) {
            throw new DomainException('Secret value unavailable.');
        }
    }

    /** Binding-aware deletion is supplied by the plan service before routes are exposed. */
    public function delete(string $secretId, User $actor): void
    {
        try {
            DB::transaction(function () use ($secretId, $actor) {
                $secret = Secret::query()->lockForUpdate()->findOrFail($secretId);
                $this->audit->record($actor, 'secret.delete', $this->auditMetadata($secret));
                $secret->delete();
            }, 3);
        } catch (Throwable) {
            throw new DomainException('Secret deletion failed.');
        }
    }

    private function writeVersion(Secret $secret, int $version, #[\SensitiveParameter] SecretValue $value, User $actor): void
    {
        SecretVersion::query()->create([
            'secret_id' => $secret->id, 'version' => $version,
            'encrypted_value' => $this->encrypter->encrypt($value->bytes(), false),
            'created_by' => $actor->id, 'created_at' => now(),
        ]);
    }

    private function toMetadata(Secret $secret): SecretMetadata
    {
        return new SecretMetadata($secret->id, $secret->server_id, $secret->name, $secret->description,
            $secret->current_version, $secret->created_at->toIso8601String(), $secret->updated_at->toIso8601String());
    }

    private function auditMetadata(Secret $secret): array
    {
        return ['server_id' => $secret->server_id, 'secret_id' => $secret->id, 'version' => $secret->current_version];
    }

    private function validateDescription(string $description): void
    {
        if (!mb_check_encoding($description, 'UTF-8') || str_contains($description, "\0") || strlen($description) > 4096) {
            throw new DomainException('Invalid secret description.');
        }
    }
}
