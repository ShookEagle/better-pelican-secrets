<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Models\User;
use DomainException;
use Illuminate\Support\Str;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretAuditEvent;

final class AuditRecorder
{
    public function record(User|int $actor, string $operation, #[\SensitiveParameter] array $metadata): void
    {
        if (!in_array($operation, ['secret.create', 'secret.replace', 'secret.delete', 'secret.reveal', 'secret.description', 'grant.grant', 'grant.revoke', 'binding.file.save', 'binding.file.remove', 'binding.startup.bind', 'binding.startup.unbind'], true)) {
            throw new DomainException('Invalid audit operation.');
        }
        foreach ($metadata as $key => $value) {
            $valid = match ($key) {
                'secret_id', 'file_id' => is_string($value) && Str::isUuid($value),
                'operations' => is_array($value) && count($value) <= 7 && array_all($value, fn ($operation) => is_string($operation) && SecretOperation::tryFrom($operation) !== null),
                'server_id', 'version', 'recipient_id' => is_int($value) && $value > 0,
                'revision' => is_string($value) && preg_match('/\A(0|[1-9][0-9]{0,19})\z/', $value) && (strlen($value) < 20 || strcmp($value, '18446744073709551615') <= 0),
                'env' => is_string($value) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]{0,127}\z/', $value),
                default => false,
            };
            if (!$valid) {
                throw new DomainException('Invalid audit metadata.');
            }
        }
        $actorId = $actor instanceof User ? $actor->id : $actor;
        if (!is_int($actorId) || $actorId < 1) {
            throw new DomainException('Invalid audit actor.');
        }
        SecretAuditEvent::query()->create([
            'actor_id' => $actorId,
            'operation' => $operation,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }
}
