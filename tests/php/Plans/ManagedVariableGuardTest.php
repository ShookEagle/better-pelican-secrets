<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Filament\Server\Pages\Startup;
use App\Http\Controllers\Api\Application\Servers\StartupController as ApplicationStartupController;
use App\Http\Controllers\Api\Client\Servers\StartupController as ClientStartupController;
use App\Http\Requests\Api\Application\Servers\UpdateServerStartupRequest;
use App\Http\Requests\Api\Client\Servers\Startup\UpdateStartupVariableRequest;
use App\Models\EggVariable;
use App\Models\Role;
use App\Models\ServerVariable;
use App\Services\Servers\StartupModificationService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\ManagedVariableGuard;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

final class ManagedVariableGuardTest extends PluginTestCase
{
    private ServerVariable $variable;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor->assignRole(Role::getRootAdmin());
        Auth::login($this->actor);
        $eggVariable = EggVariable::factory()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'RCON_PASSWORD',
            'default_value' => '', 'user_viewable' => 1, 'user_editable' => 1, 'rules' => 'nullable|string']);
        $this->variable = ServerVariable::query()->create(['server_id' => $this->server->id, 'variable_id' => $eggVariable->id, 'variable_value' => '']);
        $secret = $this->app->make(SecretStore::class)->create($this->server, 'RCON', '', new SecretValue('fixture-secret'), $this->actor);
        $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $secret->secretId);
    }

    public function test_model_level_write_is_blocked_even_for_root(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('startup_variable_managed');
        $this->variable->update(['variable_value' => 'attempted-fixture']);
    }

    public function test_client_startup_api_cannot_store_a_bound_value(): void
    {
        $request = UpdateStartupVariableRequest::create('/fixture', 'PUT', ['key' => 'RCON_PASSWORD', 'value' => 'attempted-fixture']);
        $request->setContainer($this->app)->setRedirector($this->app->make('redirect'));
        $error = null;
        try {
            $this->app->make(ClientStartupController::class)->update($request, $this->server);
        } catch (Throwable $exception) {
            $error = $exception;
        }
        $this->assertSame('', $this->variable->fresh()->variable_value);
        $this->assertInstanceOf(DomainException::class, $error);
        $this->assertSame('startup_variable_managed', $error->getMessage());
    }

    public function test_application_startup_api_cannot_store_a_bound_value(): void
    {
        $data = ['environment' => ['RCON_PASSWORD' => 'attempted-fixture'], 'skip_scripts' => false, 'egg' => $this->server->egg_id];
        $request = UpdateServerStartupRequest::create('/fixture', 'PATCH', $data);
        $request->setContainer($this->app);
        $request->setValidator($this->app->make('validator')->make($data, ['environment' => 'array', 'skip_scripts' => 'boolean', 'egg' => 'integer']));
        try {
            $this->app->make(ApplicationStartupController::class)->index($request, $this->server);
            $this->fail('Application API changed bound startup value');
        } catch (DomainException $exception) {
            $this->assertSame('startup_variable_managed', $exception->getMessage());
        }
        $this->assertSame('', $this->variable->fresh()->variable_value);
    }

    public function test_native_startup_livewire_action_cannot_store_a_bound_value(): void
    {
        Filament::setTenant($this->server);
        $page = $this->app->make(Startup::class);
        $page->update('attempted-fixture', $this->variable);
        $this->assertSame('', $this->variable->fresh()->variable_value);
        $this->assertStringContainsString('startup_variable_managed', json_encode(session('filament.notifications')));
    }

    public function test_unbound_startup_variables_remain_editable(): void
    {
        $ordinary = EggVariable::factory()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'PUBLIC_SETTING',
            'default_value' => 'old', 'user_viewable' => 1, 'user_editable' => 1, 'rules' => 'nullable|string']);
        $this->app->make(StartupModificationService::class)->handle($this->server, ['environment' => ['PUBLIC_SETTING' => 'new']]);
        $this->assertSame('new', ServerVariable::query()->where('variable_id', $ordinary->id)->firstOrFail()->variable_value);
        $this->assertSame('', $this->variable->fresh()->variable_value);
    }

    public function test_bound_ordinary_row_cannot_be_deleted(): void
    {
        $this->expectException(DomainException::class);
        $this->variable->delete();
    }

    public function test_migration_clear_bypass_cannot_escape_its_transaction(): void
    {
        $guard = $this->app->make(ManagedVariableGuard::class);
        DB::transaction(fn () => $guard->clearForMigration($this->server, 'RCON_PASSWORD'));
        $this->expectException(DomainException::class);
        $this->variable->update(['variable_value' => 'attempted-fixture']);
    }
}
