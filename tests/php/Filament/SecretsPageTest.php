<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Illuminate\View\Component;
use Livewire\Livewire;
use ShookEagle\BetterPelicanSecrets\Filament\Server\Pages\Secrets;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\SecretActions;
use ShookEagle\BetterPelicanSecrets\Secrets\GrantService;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretAuditEvent;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretGrant;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation as Op;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use Spatie\Permission\Models\Permission;

final class SecretsPageTest extends PluginTestCase
{
    protected bool $isolatedDatabase = true;

    private string $secretId;

    protected function setUp(): void
    {
        parent::setUp();
        Component::flushCache();
        Component::forgetFactory();
        $this->secretId = app(SecretStore::class)->create($this->server, 'RCON', 'Game password', new SecretValue('fixture-secret'), $this->actor)->secretId;
        auth()->login($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('server'));
        Filament::setTenant($this->server, true);
    }

    private function grant(Op ...$operations): void
    {
        $root = User::factory()->create();
        $root->assignRole(Role::getRootAdmin());
        app(GrantService::class)->grant($root, $this->actor, new SecretScope($this->server->id), $operations);
    }

    public function test_owner_page_does_not_bypass_grants(): void
    {
        $this->assertFalse(Secrets::canAccess());
        $this->expectException(AuthorizationException::class);
        app(SecretActions::class)->listing($this->actor, $this->server);
    }

    public function test_direct_livewire_action_rechecks_permission(): void
    {
        $this->grant(Op::ListSecrets);
        $component = Livewire::test(Secrets::class)->assertSee('RCON')->assertDontSee('fixture-secret');
        $component->call('replaceValue', $this->secretId, 'stolen-replacement')->assertForbidden();
        $this->assertSame(1, app(SecretStore::class)->metadata($this->secretId)->currentVersion);
    }

    public function test_blank_update_preserves_value(): void
    {
        $this->grant(Op::ListSecrets, Op::Update);
        Livewire::test(Secrets::class)
            ->assertDontSee('fixture-secret')
            ->call('replaceValue', $this->secretId, '')
            ->assertDontSee('fixture-secret');
        $this->assertSame(1, app(SecretStore::class)->metadata($this->secretId)->currentVersion);
    }

    public function test_reveal_is_separate_audited_action(): void
    {
        $this->grant(Op::ListSecrets, Op::Reveal);
        $component = Livewire::test(Secrets::class)->assertDontSee('fixture-secret');
        $component->call('revealValue', $this->secretId)->assertDispatched('bps-reveal', value: 'fixture-secret');
        $this->assertStringNotContainsString('fixture-secret', json_encode($component->snapshot, JSON_THROW_ON_ERROR));
        $component->call('$refresh')->assertDontSee('fixture-secret');
        $this->assertSame(1, SecretAuditEvent::where('operation', 'secret.reveal')->count());
    }

    public function test_direct_access_action_cannot_delegate_reveal(): void
    {
        $this->grant(Op::ListSecrets, Op::ManageAccess);
        Livewire::test(Secrets::class)->call('changeAccess', $this->secretId, $this->actor->id, ['reveal'])->assertForbidden();
    }

    public function test_revocation_takes_effect_on_next_component_request(): void
    {
        $this->grant(Op::ListSecrets, Op::Reveal);
        $component = Livewire::test(Secrets::class)->assertDontSee('fixture-secret');
        SecretGrant::where('user_id', $this->actor->id)->where('operation', 'reveal')->delete();
        $component->call('revealValue', $this->secretId)->assertForbidden();
    }

    public function test_explicit_global_admin_scope_does_not_require_server_membership(): void
    {
        $admin = User::factory()->create();
        $role = Role::findOrCreate('Secret list administrator', 'web');
        $role->givePermissionTo(Permission::findOrCreate('list secrets', 'web'));
        $admin->assignRole($role);
        $this->assertSame([$this->secretId], array_column(app(SecretActions::class)->adminListing($admin), 'id'));
        auth()->login($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->assertTrue(ShookEagle\BetterPelicanSecrets\Filament\Admin\Pages\Secrets::canAccess());
        Livewire::test(ShookEagle\BetterPelicanSecrets\Filament\Admin\Pages\Secrets::class)
            ->assertSee('RCON')->assertDontSee('fixture-secret')
            ->set('selectedServer', $this->server->id)
            ->assertSee('RCON')->call('revealValue', $this->secretId)->assertForbidden();
    }

    public function test_admin_rows_include_status_and_audit_stays_metadata_only(): void
    {
        $this->grant(Op::ListSecrets, Op::ManageAccess);
        $rows = app(SecretActions::class)->adminListing($this->actor);
        $this->assertSame('0', $rows[0]['status']->desiredRevision);
        $overview = app(SecretActions::class)->overview($this->actor, $this->server);
        $this->assertNotEmpty($overview['audit']);
        $this->assertSame('secret.create', collect($overview['audit'])->firstWhere('operation', 'secret.create')['operation']);
        $this->assertStringNotContainsString('fixture-secret', json_encode($overview));
    }

    public function test_actual_livewire_reveal_response_is_no_store(): void
    {
        $this->grant(Op::ListSecrets, Op::Reveal);
        $component = Livewire::test(Secrets::class);
        $request = Request::create('https://localhost' . app('livewire')->getUpdateUri(), 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'],
            json_encode(['components' => [['snapshot' => json_encode($component->snapshot), 'updates' => [],
                'calls' => [['path' => '', 'method' => 'revealValue', 'params' => [$this->secretId]]]]]]));
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $response = TestResponse::fromBaseResponse($response);
        $response->assertOk()->assertJsonPath('components.0.effects.dispatches.0.params.value', 'fixture-secret');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_admin_list_respects_scope(): void
    {
        $this->grant(Op::ListSecrets);
        $other = Server::factory()->create();
        app(SecretStore::class)->create($other, 'PRIVATE', '', new SecretValue('other-fixture-secret'), $other->user);
        $rows = app(SecretActions::class)->adminListing($this->actor);
        $this->assertSame([$this->server->id], array_column($rows, 'server_id'));
        $this->assertStringNotContainsString('fixture-secret', json_encode($rows));
    }
}
