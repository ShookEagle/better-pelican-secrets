<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Models\Role;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class NativeGrantGuard
{
    public function __construct(private SecretAuthorizer $authorizer, private ExplicitNativeGrantReader $native) {}

    public function authorizeGlobalChange(?User $actor, array $operations): void
    {
        if (!$operations) {
            return;
        }
        if (!$actor) {
            $this->deny();
        }
        if ($this->authorizer->isRoot($actor)) {
            return;
        }
        $held = $this->native->globalOperations($actor);
        foreach ([SecretOperation::ManageAccess, ...$operations] as $operation) {
            if (!$operation instanceof SecretOperation || !in_array($operation, $held, true)) {
                $this->deny();
            }
        }
    }

    public function authorizeRoleAssignment(?User $actor, array $oldIds, array $newIds): void
    {
        $changed = [...array_diff($oldIds, $newIds), ...array_diff($newIds, $oldIds)];
        $operations = [];
        foreach (Role::query()->whereIn('id', $changed)->with('permissions')->get() as $role) {
            foreach (SecretOperation::cases() as $operation) {
                if ($role->permissions->contains('name', $operation->nativeRoleName())) {
                    $operations[] = $operation;
                }
            }
        }
        $this->authorizeGlobalChange($actor, $operations);
    }

    public function authorizeRolePermissions(?User $actor, ?Role $role, array $newNames): void
    {
        $oldNames = $role?->permissions()->pluck('name')->all() ?? [];
        $changed = [...array_diff($oldNames, $newNames), ...array_diff($newNames, $oldNames)];
        $operations = array_values(array_filter(SecretOperation::cases(),
            fn ($operation) => in_array($operation->nativeRoleName(), $changed, true)));
        $this->authorizeGlobalChange($actor, $operations);
    }

    public function savingSubuser(Subuser $subuser): void
    {
        if (!$subuser->isDirty('permissions')) {
            return;
        }
        $old = json_decode($subuser->getRawOriginal('permissions') ?? '[]', true) ?? [];
        $new = $subuser->permissions ?? [];
        $changed = [...array_diff($old, $new), ...array_diff($new, $old)];
        $operations = array_values(array_filter(SecretOperation::cases(),
            fn ($operation) => in_array($operation->nativeSubuserName(), $changed, true)));
        if (!$operations) {
            return;
        }
        $actor = auth()->user();
        if (!$actor instanceof User) {
            $this->deny();
        }
        $scope = new SecretScope($subuser->server_id);
        $this->authorizer->authorize($actor, SecretOperation::ManageAccess, $scope);
        foreach ($operations as $operation) {
            $this->authorizer->authorize($actor, $operation, $scope);
        }
    }

    private function deny(): never
    {
        throw new AuthorizationException('Secret access denied.');
    }
}
