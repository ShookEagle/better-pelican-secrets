<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use ShookEagle\BetterPelicanSecrets\Node\NodeCapabilityService;
use ShookEagle\BetterPelicanSecrets\Providers\BetterPelicanSecretsProvider;

abstract class PluginTestCase extends TestCase
{
    protected Application $app;

    protected bool $isolatedDatabase = false;

    protected User $actor;

    protected Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = require dirname(__DIR__, 3) . '/.work/panel/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        $this->app->register(BetterPelicanSecretsProvider::class);
        if ($this->isolatedDatabase) {
            config(['database.connections.sqlite.database' => ':memory:']);
            DB::purge('sqlite');
            Artisan::call('migrate', ['--force' => true]);
        }
        $migrations = dirname(__DIR__, 3) . '/plugins/better-pelican-secrets/database/migrations';
        if (is_dir($migrations)) {
            Artisan::call('migrate', ['--path' => $migrations, '--realpath' => true, '--force' => true]);
        }
        if (!$this->isolatedDatabase) {
            DB::beginTransaction();
        }
        $this->actor = User::factory()->create();
        $this->server = Server::factory()->create(['owner_id' => $this->actor->id]);
        if (class_exists(NodeCapabilityService::class)) {
            $node = $this->server->node;
            $node->update(['scheme' => 'https']);
            Http::fake(['*' => Http::response([
                'protocol' => 1,
                'wings_base_commit' => NodeCapabilityService::WINGS_BASE,
                'panel_base_version' => NodeCapabilityService::PANEL_VERSION,
            ], 200)]);
            if (!$this->app->make(NodeCapabilityService::class)->compatible($node, true)) {
                throw new RuntimeException('Authenticated capability fixture did not verify');
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->isolatedDatabase) {
            DB::disconnect('sqlite');
        } else {
            DB::rollBack();
        }
        restore_error_handler();
        restore_exception_handler();
        $this->app->flush();
        parent::tearDown();
    }
}
