<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\EggVariable;
use App\Models\Node;
use App\Models\Role;
use App\Models\ServerTransfer;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\GetUserPermissionsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Node\NodeCapabilityService;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

final class NodeCapabilityTest extends PluginTestCase
{
    private function fixtureSecret(): string
    {
        $this->actor->assignRole(Role::getRootAdmin());
        EggVariable::factory()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'RCON_PASSWORD', 'default_value' => '', 'rules' => 'nullable|string']);

        return $this->app->make(SecretStore::class)->create($this->server, 'RCON', '', new SecretValue('fixture-secret'), $this->actor)->secretId;
    }

    private function makeStock(): void
    {
        $this->app->make(NodeCapabilityService::class)->invalidate($this->server->node);
        Http::fake(['*' => Http::response(['version' => '1.0.0-rc.1'], 200, ['User-Agent' => 'Pelican Wings/v1.0.0-rc.1'])]);
    }

    public function test_stock_node_cannot_enable_bindings(): void
    {
        $id = $this->fixtureSecret();
        $this->makeStock();
        try {
            $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $id);
            $this->fail('Stock node accepted a protected binding');
        } catch (DomainException $exception) {
            $this->assertSame('node_incompatible', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('bps_startup_bindings')->count());
    }

    public function test_root_power_actions_do_not_bypass_compatibility(): void
    {
        $id = $this->fixtureSecret();
        $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $id);
        $this->makeStock();
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('node_incompatible');
        $this->app->make(DaemonServerRepository::class)->setServer($this->server)->power('start');
    }

    public function test_protected_server_cannot_move_to_stock_node(): void
    {
        $id = $this->fixtureSecret();
        $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $id);
        $target = Node::factory()->create(['scheme' => 'https']);
        Http::fake(['*' => Http::response(['version' => '1.0.0-rc.1'])]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('node_incompatible');
        $this->server->update(['node_id' => $target->id]);
    }

    public function test_transfer_record_cannot_target_stock_node(): void
    {
        $id = $this->fixtureSecret();
        $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $id);
        $target = Node::factory()->create(['scheme' => 'https']);
        Http::fake(['*' => Http::response(['version' => '1.0.0-rc.1'])]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('node_incompatible');
        ServerTransfer::query()->create(['server_id' => $this->server->id, 'old_node' => $this->server->node_id, 'new_node' => $target->id]);
    }

    public function test_websocket_wildcard_cannot_keep_start_permission_on_stock_node(): void
    {
        $id = $this->fixtureSecret();
        $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $id);
        $this->makeStock();
        $permissions = $this->app->make(GetUserPermissionsService::class)->handle($this->server, $this->actor);
        $this->assertNotContains('*', $permissions);
        $this->assertNotContains('control.start', $permissions);
        $this->assertNotContains('control.restart', $permissions);
        $this->assertContains('control.stop', $permissions);
        $this->assertContains('control.console', $permissions);
    }
}
