<?php

namespace ShookEagle\BetterPelicanSecrets\Filament\Shared;

use App\Models\Server;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use ShookEagle\BetterPelicanSecrets\Secrets\ExplicitNativeGrantReader;
use ShookEagle\BetterPelicanSecrets\Secrets\GrantService;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretGrant;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation as Op;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;

final class GrantActions
{
    public function __construct(private SecretActions $actions, private GrantService $grants) {}

    public function listing(User $actor, Server $server, ?string $id, bool $admin = false): array
    {
        $this->actions->authorize($actor, Op::ManageAccess, $server, $id, $admin);
        $rows = [];
        // Only server members plus explicitly global secret administrators have applicable access.
        foreach (User::query()->orderBy('username')->get() as $user) {
            try {
                app(SecretAuthorizer::class)->assertMember($user, $server->id);
                $member = true;
            } catch (AuthorizationException) {
                $member = false;
            }
            $native = app(ExplicitNativeGrantReader::class)->operations($user, $server->id);
            if (!$member) {
                $native = app(ExplicitNativeGrantReader::class)->globalOperations($user);
            }
            $root = app(SecretAuthorizer::class)->isRoot($user);
            $inherited = SecretGrant::where('server_id', $server->id)->where('user_id', $user->id)->where('secret_id', '')->pluck('operation')->all();
            $direct = $id === null ? $inherited : SecretGrant::where('server_id', $server->id)->where('user_id', $user->id)->where('secret_id', $id)->pluck('operation')->all();
            if ($member || $native || $root) {
                $rows[] = ['user_id' => $user->id, 'username' => $user->username, 'direct' => $direct,
                    'inherited' => $id === null ? [] : $inherited, 'native' => array_map(fn ($op) => $op->value, $native),
                    'root' => $root, 'member' => $member];
            }
        }

        return ['users' => $rows, 'delegable' => array_values(array_map(fn ($op) => $op->value,
            array_filter(Op::cases(), fn ($op) => ($id === null || $op !== Op::Create) && $this->actions->can($actor, $op, $server, $id, $admin))))];
    }

    public function change(User $actor, Server $server, ?string $id, int $userId, array $operations, bool $revoke = false, bool $admin = false): void
    {
        $this->actions->authorize($actor, Op::ManageAccess, $server, $id, $admin);
        if (!$operations || count($operations) > 7 || !array_all($operations, fn ($op) => is_string($op) && Op::tryFrom($op) !== null)) {
            throw new DomainException('Invalid secret operations.');
        }
        $recipient = User::find($userId) ?? throw new DomainException('Invalid recipient.');
        $scope = new SecretScope($server->id, $id);
        $ops = array_map(fn ($op) => Op::from($op), $operations);
        if ($revoke) {
            $this->grants->revoke($actor, $recipient, $scope, $ops, $admin);
        } else {
            $this->grants->grant($actor, $recipient, $scope, $ops, $admin);
        }
    }
}
