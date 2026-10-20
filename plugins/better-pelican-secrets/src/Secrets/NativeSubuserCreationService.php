<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Enums\SubuserPermission;
use App\Events\Server\SubUserAdded;
use App\Exceptions\Service\Subuser\ServerSubuserExistsException;
use App\Exceptions\Service\Subuser\UserIsServerOwnerException;
use App\Models\Server;
use App\Models\Subuser;
use App\Models\User;
use App\Services\Subusers\SubuserCreationService;
use App\Services\Users\UserCreationService;
use Illuminate\Support\Facades\DB;

/** Pinned native creation flow with explicit custom-right delegation. */
final class NativeSubuserCreationService extends SubuserCreationService
{
    public function handle(Server $server, string $email, array $permissions): Subuser
    {
        return DB::transaction(function () use ($server, $email, $permissions) {
            $candidate = new Subuser(['server_id' => $server->id, 'permissions' => $permissions]);
            app(NativeGrantGuard::class)->savingSubuser($candidate);
            $user = User::withoutGlobalScopes()->where('email', $email)->first();
            if (!$user) {
                $user = app(UserCreationService::class)->handle(['email' => $email, 'root_admin' => false]);
            } else {
                throw_if($server->owner_id === $user->id, new UserIsServerOwnerException(trans('exceptions.subusers.user_is_owner')));
                throw_if($server->subusers()->where('user_id', $user->id)->exists(), new ServerSubuserExistsException(trans('exceptions.subusers.subuser_exists')));
            }
            $cleaned = $this->clean($server, $permissions);
            $subuser = Subuser::withoutGlobalScopes()->create([
                'user_id' => $user->id, 'server_id' => $server->id, 'permissions' => $cleaned,
            ]);
            event(new SubUserAdded($subuser));

            return $subuser;
        });
    }

    private function clean(Server $server, array $permissions): array
    {
        $custom = array_map(fn ($operation) => $operation->nativeSubuserName(), SecretOperation::cases());

        return collect($permissions)->unique()->filter(fn ($permission) => in_array($permission, $custom, true) || $permission === SubuserPermission::WebsocketConnect->value || user()?->can($permission, $server))
            ->sort()->values()->all();
    }
}
