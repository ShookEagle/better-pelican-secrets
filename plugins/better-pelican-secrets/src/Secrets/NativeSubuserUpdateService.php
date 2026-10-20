<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Jobs\RevokeSftpAccessJob;
use App\Models\Server;
use App\Models\Subuser;
use App\Services\Subusers\SubuserUpdateService;

/** Preserve the native activity/revocation flow while checking secret deltas explicitly. */
final class NativeSubuserUpdateService extends SubuserUpdateService
{
    public function handle(Subuser $subuser, Server $server, array $permissions): void
    {
        $candidate = clone $subuser;
        $candidate->permissions = $permissions;
        app(NativeGrantGuard::class)->savingSubuser($candidate);
        $custom = array_map(fn ($operation) => $operation->nativeSubuserName(), SecretOperation::cases());
        $cleaned = collect($permissions)->unique()->filter(fn ($permission) => in_array($permission, $custom, true) || $permission === SubuserPermission::WebsocketConnect->value || user()?->can($permission, $server))
            ->sort()->values()->all();
        $current = $subuser->permissions ?? [];
        sort($current);
        $log = Activity::event('server:subuser.update')->subject($subuser->user)
            ->property(['email' => $subuser->user->email, 'old' => $current, 'new' => $cleaned, 'revoked' => true]);
        if ($cleaned !== $current) {
            $log->transaction(function () use ($subuser, $cleaned, $server) {
                $subuser->update(['permissions' => $cleaned]);
                RevokeSftpAccessJob::dispatch($subuser->user->uuid, $server);
            });
        }
        $log->reset();
    }
}
