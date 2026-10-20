<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Models\Role;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretGrant;

final class SecretAuthorizer
{
    public function __construct(private ExplicitNativeGrantReader $native) {}

    public function authorize(User $actor, SecretOperation $operation, SecretScope $scope): void
    {
        $this->assertScope($scope);
        if ($operation === SecretOperation::Create && $scope->secretId !== null) {
            $this->deny();
        }
        $this->assertMember($actor, $scope->serverId);
        if ($this->isRoot($actor) || in_array($operation, $this->native->operations($actor, $scope->serverId), true)) {
            return;
        }
        if (SecretGrant::query()->where('user_id', $actor->id)->where('server_id', $scope->serverId)
            ->where('operation', $operation->value)->whereIn('secret_id', array_unique(['', $scope->secretId ?? '']))->exists()) {
            return;
        }
        $this->deny();
    }

    public function assertScope(SecretScope $scope): void
    {
        if ($scope->serverId < 1 || !Server::query()->whereKey($scope->serverId)->exists()
            || ($scope->secretId !== null && !Secret::query()->whereKey($scope->secretId)->where('server_id', $scope->serverId)->exists())) {
            $this->deny();
        }
    }

    public function isRoot(User $actor): bool
    {
        return $actor->roles()->where('name', Role::ROOT_ADMIN)->exists();
    }

    public function assertMember(User $actor, int $serverId): void
    {
        $fresh = User::query()->find($actor->id);
        if (!$fresh || !Server::query()->whereKey($serverId)->exists()) {
            $this->deny();
        }
        if ($this->isRoot($fresh) || Server::query()->whereKey($serverId)->where('owner_id', $fresh->id)->exists()
            || Subuser::query()->where('user_id', $fresh->id)->where('server_id', $serverId)->exists()
            || $fresh->accessibleServers()->where('servers.id', $serverId)->exists()) {
            return;
        }
        $this->deny();
    }

    private function deny(): never
    {
        throw new AuthorizationException('Secret access denied.');
    }
}
