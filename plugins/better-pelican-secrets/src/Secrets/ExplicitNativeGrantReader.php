<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Models\Subuser;
use App\Models\User;

final class ExplicitNativeGrantReader
{
    /** @return list<SecretOperation> */
    public function operations(User $actor, int $serverId): array
    {
        $raw = Subuser::query()->where('user_id', $actor->id)->where('server_id', $serverId)->value('permissions') ?? [];
        $global = $this->globalOperations($actor);

        return array_values(array_filter(SecretOperation::cases(),
            fn (SecretOperation $operation) => in_array($operation, $global, true) || in_array($operation->nativeSubuserName(), $raw, true)));
    }

    /** Always query raw relationships: no can(), wildcard synthesis, or stale role collection. */
    public function globalOperations(User $actor): array
    {
        $permissions = $actor->roles()->with('permissions')->get()
            ->flatMap(fn ($role) => $role->permissions->pluck('name'))
            ->merge($actor->permissions()->pluck('name'))->all();

        return array_values(array_filter(SecretOperation::cases(),
            fn (SecretOperation $operation) => in_array($operation->nativeRoleName(), $permissions, true)));
    }
}
