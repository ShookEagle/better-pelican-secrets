<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretGrant;
use Spatie\Permission\PermissionRegistrar;

final class GrantService
{
    public function __construct(private SecretAuthorizer $authorizer, private AuditRecorder $audit) {}

    public function grant(User $actor, User $recipient, SecretScope $scope, array $operations): void
    {
        $this->mutate($actor, $recipient, $scope, $operations, false);
    }

    public function revoke(User $actor, User $recipient, SecretScope $scope, array $operations): void
    {
        $this->mutate($actor, $recipient, $scope, $operations, true);
    }

    private function mutate(User $actor, User $recipient, SecretScope $scope, array $operations, bool $revoke): void
    {
        foreach ($operations as $operation) {
            if (!$operation instanceof SecretOperation) {
                throw new DomainException('Invalid secret operation.');
            }
        }
        $operations = array_values(array_unique($operations, SORT_REGULAR));
        DB::transaction(function () use ($actor, $recipient, $scope, $operations, $revoke) {
            $this->authorizer->authorize($actor, SecretOperation::ManageAccess, $scope);
            $this->authorizer->assertMember($recipient, $scope->serverId);
            foreach ($operations as $operation) {
                $this->authorizer->authorize($actor, $operation, $scope);
                $key = ['user_id' => $recipient->id, 'server_id' => $scope->serverId,
                    'secret_id' => $scope->secretId ?? '', 'operation' => $operation->value];
                if ($revoke) {
                    SecretGrant::query()->where($key)->delete();
                } else {
                    SecretGrant::query()->firstOrCreate($key, ['created_by' => $actor->id, 'created_at' => now()]);
                }
            }
            $metadata = ['server_id' => $scope->serverId, 'recipient_id' => $recipient->id,
                'operations' => array_map(fn ($operation) => $operation->value, $operations)];
            if ($scope->secretId !== null) {
                $metadata['secret_id'] = $scope->secretId;
            }
            $this->audit->record($actor, $revoke ? 'grant.revoke' : 'grant.grant', $metadata);
        }, 3);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        $recipient->unsetRelation('roles')->unsetRelation('permissions');
    }
}
