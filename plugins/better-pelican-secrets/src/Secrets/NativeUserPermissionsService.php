<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Models\Server;
use App\Models\User;
use App\Services\Servers\GetUserPermissionsService;
use Illuminate\Auth\Access\AuthorizationException;
use ShookEagle\BetterPelicanSecrets\Node\NodeCapabilityService;

final class NativeUserPermissionsService extends GetUserPermissionsService
{
    public function handle(Server $server, User $user): array
    {
        $permissions = parent::handle($server, $user);
        foreach (SecretOperation::cases() as $operation) {
            try {
                app(SecretAuthorizer::class)->authorize($user, $operation, new SecretScope($server->id));
                $permissions[] = $operation->nativeSubuserName();
            } catch (AuthorizationException) {
                // Native ordinary permissions remain available; no custom authority synthesized.
            }
        }

        return app(NodeCapabilityService::class)->filterSocketPermissions($server, array_values(array_unique($permissions)));
    }
}
