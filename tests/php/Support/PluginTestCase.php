<?php

use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\TestCase;
use ShookEagle\BetterPelicanSecrets\Providers\BetterPelicanSecretsProvider;

abstract class PluginTestCase extends TestCase
{
    protected Application $app;

    protected User $actor;

    protected Server $server;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = require dirname(__DIR__, 3) . '/.work/panel/bootstrap/app.php';
        $this->app->make(Kernel::class)->bootstrap();
        $this->app->register(BetterPelicanSecretsProvider::class);
        $migrations = dirname(__DIR__, 3) . '/plugins/better-pelican-secrets/database/migrations';
        if (is_dir($migrations)) {
            Artisan::call('migrate', ['--path' => $migrations, '--realpath' => true, '--force' => true]);
        }
        DB::beginTransaction();
        $this->actor = User::factory()->create();
        $this->server = Server::factory()->create(['owner_id' => $this->actor->id]);
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        restore_error_handler();
        restore_exception_handler();
        $this->app->flush();
        parent::tearDown();
    }
}
