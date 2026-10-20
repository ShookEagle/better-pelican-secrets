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
        if (!in_array($operation, ['secret.create', 'secret.replace', 'secret.delete', 'secret.reveal', 'secret.description'], true)) {
            throw new DomainException('Invalid audit operation.');
        }
        foreach ($metadata as $key => $value) {
            $valid = match ($key) {
                'secret_id' => is_string($value) && Str::isUuid($value),
                'server_id', 'version' => is_int($value) && $value > 0,
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
