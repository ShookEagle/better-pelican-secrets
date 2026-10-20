<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Http\Controllers\Api\Application\Users\UserController;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;

final class NativeRoleApiGuard
{
    public function handle(Request $request, Closure $next): mixed
    {
        $route = $request->route();
        if ($route && in_array($route->getActionName(), [UserController::class . '@assignRoles', UserController::class . '@removeRoles'], true)) {
            $roles = $request->input('roles', []);
            if (!is_array($roles) || count($roles) > 1000) {
                throw new AuthorizationException('Secret access denied.');
            }
            foreach ($roles as $role) {
                if (!is_int($role) && !(is_string($role) && ctype_digit($role))) {
                    throw new AuthorizationException('Secret access denied.');
                }
            }
            app(NativeGrantGuard::class)->authorizeRoleAssignment($request->user(), [], $roles);
        }

        return $next($request);
    }
}
