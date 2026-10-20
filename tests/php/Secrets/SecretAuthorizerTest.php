<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\Role;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Secrets\ExplicitNativeGrantReader;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use Spatie\Permission\Models\Permission;

final class SecretAuthorizerTest extends PluginTestCase
{
    public function test_explicit_permission_matrix(): void
    {
        $store = $this->app->make(SecretStore::class);
        $one = $store->create($this->server, 'KEY', '', new SecretValue('fixture-secret'), $this->actor);
        $two = $store->create($this->server, 'OTHER', '', new SecretValue('other'), $this->actor);
        $scope = new SecretScope($this->server->id, $one->secretId);
        $root = User::factory()->create();
        $root->assignRole(Role::getRootAdmin());
        $admin = User::factory()->create();
        $role = Role::findOrCreate('Ordinary admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('update server', 'web'));
        $admin->assignRole($role);
        $subuser = User::factory()->create();
        Subuser::query()->create(['user_id' => $subuser->id, 'server_id' => $this->server->id, 'permissions' => ['file.*', 'user.update']]);
        $global = User::factory()->create();
        Subuser::query()->create(['user_id' => $global->id, 'server_id' => $this->server->id, 'permissions' => []]);
        $globalRole = Role::findOrCreate('Explicit secret role', 'web');
        $globalRole->givePermissionTo(Permission::findOrCreate('reveal secrets', 'web'));
        $global->assignRole($globalRole);
        $serverGrantee = $this->member();
        $secretGrantee = $this->member();
        $this->seedGrant($serverGrantee, '', 'reveal');
        $this->seedGrant($secretGrantee, $one->secretId, 'reveal');
        $authorizer = $this->app->make(SecretAuthorizer::class);
        foreach ([
            [$root, $scope, true],
            [$admin, $scope, false],
            [$this->actor, $scope, false],
            [$subuser, $scope, false],
            [$global, $scope, true],
            [$serverGrantee, $scope, true],
            [$secretGrantee, $scope, true],
            [$secretGrantee, new SecretScope($this->server->id, $two->secretId), false],
            [$root, new SecretScope($this->server->id + 999, $one->secretId), false],
        ] as [$actor, $target, $allowed]) {
            try {
                $authorizer->authorize($actor, SecretOperation::Reveal, $target);
                $this->assertTrue($allowed, 'Unexpected authority for actor ' . $actor->id);
            } catch (AuthorizationException $exception) {
                $this->assertFalse($allowed, 'Unexpected denial for actor ' . $actor->id);
                $this->assertSame('Secret access denied.', $exception->getMessage());
            }
        }
        foreach (SecretOperation::cases() as $operation) {
            $authorizer->authorize($root, $operation, new SecretScope($this->server->id, null));
            $this->addToAssertionCount(1);
        }
    }

    public function test_native_explicit_subuser_grants_work_without_owner_wildcards(): void
    {
        $member = $this->member();
        DB::table('subusers')->where('user_id', $member->id)->update(['permissions' => json_encode(['secrets.reveal', '*', 'secrets.*'])]);
        $reader = $this->app->make(ExplicitNativeGrantReader::class);
        $this->assertSame([SecretOperation::Reveal], $reader->operations($member, $this->server->id));
        $this->app->make(SecretAuthorizer::class)->authorize($member, SecretOperation::Reveal, new SecretScope($this->server->id, null));
        $this->assertSame([], $reader->operations($this->actor, $this->server->id));
    }

    public function test_direct_grant_to_owner_needs_no_subuser_row(): void
    {
        $this->seedGrant($this->actor, '', 'list');
        $this->app->make(SecretAuthorizer::class)->authorize($this->actor, SecretOperation::ListSecrets, new SecretScope($this->server->id, null));
        $this->assertSame(0, Subuser::query()->where('user_id', $this->actor->id)->count());
    }

    public function test_removed_server_membership_blocks_existing_grant(): void
    {
        $member = $this->member();
        $this->seedGrant($member, '', 'reveal');
        DB::table('subusers')->where('user_id', $member->id)->delete();
        $this->expectException(AuthorizationException::class);
        $this->app->make(SecretAuthorizer::class)->authorize($member, SecretOperation::Reveal, new SecretScope($this->server->id, null));
    }

    public function test_create_cannot_be_scoped_to_existing_secret(): void
    {
        $one = $this->app->make(SecretStore::class)->create($this->server, 'KEY', '', new SecretValue('fixture-secret'), $this->actor);
        $root = User::factory()->create();
        $root->assignRole(Role::getRootAdmin());
        $this->expectException(AuthorizationException::class);
        $this->app->make(SecretAuthorizer::class)->authorize($root, SecretOperation::Create, new SecretScope($this->server->id, $one->secretId));
    }

    public function test_provider_registers_native_permission_choices(): void
    {
        $this->assertContains('secrets.reveal', Subuser::allPermissionKeys());
        $this->assertContains('manage-access', Role::getPermissionList()['secrets']);
    }

    private function member(): User
    {
        $member = User::factory()->create();
        Subuser::query()->create(['user_id' => $member->id, 'server_id' => $this->server->id, 'permissions' => []]);

        return $member;
    }

    private function seedGrant(User $user, string $secret, string $operation): void
    {
        DB::table('bps_secret_grants')->insert([
            'user_id' => $user->id, 'server_id' => $this->server->id, 'secret_id' => $secret,
            'operation' => $operation, 'created_by' => $this->actor->id, 'created_at' => now(),
        ]);
    }
}
