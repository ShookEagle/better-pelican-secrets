<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Http\Controllers\Api\Application\Users\UserController;
use App\Models\Role;
use App\Models\Subuser;
use App\Models\User;
use App\Services\Servers\GetUserPermissionsService;
use App\Services\Subusers\SubuserCreationService;
use App\Services\Subusers\SubuserUpdateService;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Component;
use ShookEagle\BetterPelicanSecrets\Secrets\GrantService;
use ShookEagle\BetterPelicanSecrets\Secrets\NativeGrantGuard;
use ShookEagle\BetterPelicanSecrets\Secrets\NativeRoleApiGuard;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use Spatie\Permission\Models\Permission;

final class GrantServiceTest extends PluginTestCase
{
    private function root(): User
    {
        $root = User::factory()->create();
        $root->assignRole(Role::getRootAdmin());

        return $root;
    }

    private function recipient(): User
    {
        $recipient = User::factory()->create();
        Subuser::query()->create(['user_id' => $recipient->id, 'server_id' => $this->server->id, 'permissions' => []]);

        return $recipient;
    }

    public function test_manager_cannot_grant_unheld_reveal(): void
    {
        $scope = new SecretScope($this->server->id, null);
        $service = $this->app->make(GrantService::class);
        $service->grant($this->root(), $this->actor, $scope, [SecretOperation::ManageAccess]);
        $this->expectException(AuthorizationException::class);
        $service->grant($this->actor, $this->recipient(), $scope, [SecretOperation::Reveal]);
    }

    public function test_revocation_affects_next_request(): void
    {
        $scope = new SecretScope($this->server->id, null);
        $service = $this->app->make(GrantService::class);
        $recipient = $this->recipient();
        $root = $this->root();
        $service->grant($root, $recipient, $scope, [SecretOperation::Reveal]);
        $authorizer = $this->app->make(SecretAuthorizer::class);
        $authorizer->authorize($recipient, SecretOperation::Reveal, $scope);
        $service->revoke($root, $recipient, $scope, [SecretOperation::Reveal]);
        $this->expectException(AuthorizationException::class);
        $authorizer->authorize($recipient, SecretOperation::Reveal, $scope);
    }

    public function test_secret_manager_cannot_expand_to_server_scope(): void
    {
        $secret = $this->app->make(SecretStore::class)->create($this->server, 'KEY', '', new SecretValue('fixture-secret'), $this->actor);
        $scope = new SecretScope($this->server->id, $secret->secretId);
        $service = $this->app->make(GrantService::class);
        $service->grant($this->root(), $this->actor, $scope, [SecretOperation::ManageAccess, SecretOperation::Reveal]);
        $recipient = $this->recipient();
        $service->grant($this->actor, $recipient, $scope, [SecretOperation::Reveal]);
        $this->app->make(SecretAuthorizer::class)->authorize($recipient, SecretOperation::Reveal, $scope);
        $this->expectException(AuthorizationException::class);
        $service->grant($this->actor, $recipient, new SecretScope($this->server->id, null), [SecretOperation::Reveal]);
    }

    public function test_broader_additive_grants_survive_narrow_revocation(): void
    {
        $secret = $this->app->make(SecretStore::class)->create($this->server, 'KEY', '', new SecretValue('fixture-secret'), $this->actor);
        $service = $this->app->make(GrantService::class);
        $root = $this->root();
        $recipient = $this->recipient();
        $wide = new SecretScope($this->server->id, null);
        $narrow = new SecretScope($this->server->id, $secret->secretId);
        $service->grant($root, $recipient, $wide, [SecretOperation::Reveal]);
        $service->grant($root, $recipient, $narrow, [SecretOperation::Reveal]);
        $service->revoke($root, $recipient, $narrow, [SecretOperation::Reveal]);
        $this->app->make(SecretAuthorizer::class)->authorize($recipient, SecretOperation::Reveal, $narrow);
        $this->assertSame(1, DB::table('bps_secret_grants')->where('user_id', $recipient->id)->count());
    }

    public function test_outsider_cannot_receive_access(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->app->make(GrantService::class)->grant($this->root(), User::factory()->create(), new SecretScope($this->server->id, null), [SecretOperation::Reveal]);
    }

    public function test_native_subuser_change_cannot_delegate_unheld_rights(): void
    {
        $recipient = $this->recipient();
        Auth::login($this->actor); // Native owner wildcard must not authorize secrets.
        $subuser = Subuser::query()->where('user_id', $recipient->id)->firstOrFail();
        try {
            $subuser->update(['permissions' => ['file.read', 'secrets.reveal']]);
            $this->fail('Native subuser mutation bypassed secret delegation');
        } catch (AuthorizationException) {
            $this->assertSame([], $subuser->fresh()->permissions);
        }
    }

    public function test_native_global_guard_requires_global_authority(): void
    {
        $guard = $this->app->make(NativeGrantGuard::class);
        $this->expectException(AuthorizationException::class);
        $guard->authorizeGlobalChange($this->actor, [SecretOperation::Reveal]);
    }

    public function test_native_role_assignment_guard_includes_manage_access(): void
    {
        $role = Role::findOrCreate('Secret readers', 'web');
        $role->givePermissionTo(Permission::findOrCreate('reveal secrets', 'web'));
        $guard = $this->app->make(NativeGrantGuard::class);
        $this->expectException(AuthorizationException::class);
        $guard->authorizeRoleAssignment($this->actor, [], [$role->id]);
    }

    public function test_native_role_form_runs_guard_before_dehydrating(): void
    {
        Auth::login($this->actor);
        $component = new class extends Component implements HasSchemas
        {
            use InteractsWithSchemas;

            public array $data = ['secrets_list' => ['reveal secrets'], 'name' => 'New role', 'guard_name' => 'web'];
        };
        $schema = RoleResource::form(Schema::make($component)->statePath('data')->model(Role::class));
        $fields = $schema->getFlatFields(withHidden: true);
        foreach ($fields as $field) {
            if ($field->getName() === 'secrets_list') {
                $this->expectException(AuthorizationException::class);
                $field->callBeforeStateDehydrated();

                return;
            }
        }
        $this->fail('Missing registered native secret choices');
    }

    public function test_application_role_api_runs_guard_before_controller(): void
    {
        $role = Role::findOrCreate('Secret API readers', 'web');
        $role->givePermissionTo(Permission::findOrCreate('reveal secrets', 'web'));
        $request = Request::create('/api/application/users/1/roles/assign', 'PATCH', ['roles' => [$role->id]]);
        $request->setUserResolver(fn () => $this->actor);
        $route = new Route('PATCH', 'fixture', ['controller' => UserController::class . '@assignRoles', 'uses' => UserController::class . '@assignRoles']);
        $request->setRouteResolver(fn () => $route);
        $this->expectException(AuthorizationException::class);
        $this->app->make(NativeRoleApiGuard::class)->handle($request, function () {
            $this->fail('Controller ran before the delegation guard');
        });
    }

    public function test_native_update_can_delegate_held_plugin_grants(): void
    {
        Queue::fake();
        $manager = $this->recipient();
        DB::table('subusers')->where('user_id', $manager->id)->update(['permissions' => json_encode(['user.update'])]);
        $this->app->make(GrantService::class)->grant($this->root(), $manager, new SecretScope($this->server->id), [SecretOperation::ManageAccess, SecretOperation::Reveal]);
        Auth::login($manager);
        $recipient = $this->recipient();
        $subuser = Subuser::query()->where('user_id', $recipient->id)->firstOrFail();
        $this->app->make(SubuserUpdateService::class)->handle($subuser, $this->server, ['secrets.reveal']);
        $this->assertContains('secrets.reveal', $subuser->fresh()->permissions);
    }

    public function test_native_creation_can_delegate_held_plugin_grants(): void
    {
        Queue::fake();
        $manager = $this->recipient();
        DB::table('subusers')->where('user_id', $manager->id)->update(['permissions' => json_encode(['user.create'])]);
        $this->app->make(GrantService::class)->grant($this->root(), $manager, new SecretScope($this->server->id), [SecretOperation::ManageAccess, SecretOperation::Reveal]);
        Auth::login($manager);
        $recipient = User::factory()->create();
        $subuser = $this->app->make(SubuserCreationService::class)->handle($this->server, $recipient->email, ['secrets.reveal']);
        $this->assertContains('secrets.reveal', $subuser->permissions);
    }

    public function test_native_api_assignable_permissions_include_explicit_grants(): void
    {
        $manager = $this->recipient();
        $this->app->make(GrantService::class)->grant($this->root(), $manager, new SecretScope($this->server->id), [SecretOperation::ManageAccess, SecretOperation::Reveal]);
        $permissions = $this->app->make(GetUserPermissionsService::class)->handle($this->server, $manager);
        $this->assertContains('secrets.reveal', $permissions);
    }

    public function test_grant_audit_contains_only_validated_metadata(): void
    {
        $scope = new SecretScope($this->server->id, null);
        $root = $this->root();
        $recipient = $this->recipient();
        $service = $this->app->make(GrantService::class);
        $service->grant($root, $recipient, $scope, [SecretOperation::Reveal, SecretOperation::Reveal]);
        $service->grant($root, $recipient, $scope, [SecretOperation::Reveal]);
        $this->assertSame(1, DB::table('bps_secret_grants')->where('user_id', $recipient->id)->count());
        $this->assertSame(2, DB::table('bps_secret_audit_events')->where('operation', 'grant.grant')->count());
        $this->assertStringNotContainsString('fixture-secret', json_encode(DB::table('bps_secret_audit_events')->get()));
    }
}
