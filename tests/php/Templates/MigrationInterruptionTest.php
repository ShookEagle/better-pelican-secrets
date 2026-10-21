<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Plans\ApplicationStatusService;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Plans\ServerLifecycleService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use ShookEagle\BetterPelicanSecrets\Templates\MigrationService;

final class MigrationInterruptionTest extends PluginTestCase
{
    protected bool $isolatedDatabase = true;

    public function test_worker_exit_after_remote_write_leaves_durable_setup_guard(): void
    {
        $this->actor->assignRole(Role::getRootAdmin());
        $secret = app(SecretStore::class)->create($this->server, 'API_KEY', '', new SecretValue('fixture-secret'), $this->actor);
        $database = tempnam(sys_get_temp_dir(), 'bps-migration-db-');
        $source = tempnam(sys_get_temp_dir(), 'bps-migration-source-');
        $replacement = '{"key":{{secret:API_KEY}}}';
        file_put_contents($source, '{"key":"legacy-value"}');
        DB::statement('VACUUM INTO ?', [$database]);
        DB::disconnect('sqlite');
        config(['database.connections.sqlite.database' => $database]);
        DB::purge('sqlite');
        try {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                Http::fake(function ($request) use ($source) {
                    $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . $this->server->node->daemon_token_id . ')'];
                    if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/files/write')) {
                        file_put_contents($source, $request->body());
                        // Termination bypasses every catch/finally in MigrationService.
                        exit(73);
                    }

                    return Http::response(file_get_contents($source), 200, $headers);
                });
                $file = new FilePlan('33333333-3333-4333-8333-333333333333', 'config.json', '/game/config.json', 'json', 'json-string',
                    [['secret_id' => $secret->secretId, 'name' => 'API_KEY', 'mode' => 'json-string', 'count' => 1]]);
                app(MigrationService::class)->migrate($this->actor, $this->server, hash('sha256', file_get_contents($source)),
                    $replacement, $file, [['secret_id' => $secret->secretId, 'mode' => 'json-string']]);
                exit(74);
            }
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(73, pcntl_wexitstatus($status));
            $this->assertSame($replacement, file_get_contents($source));
            $this->assertSame(0, DB::table('bps_managed_files')->count());
            $this->assertSame(1, DB::table('bps_setup_failures')->where('server_id', $this->server->id)->count());
            $this->assertNotSame('0', app(PlanService::class)->current($this->server)->revision);
            $this->assertTrue(app(ApplicationStatusService::class)->forServer($this->server)->pending);
            $this->expectExceptionMessage('template_invalid');
            app(ServerLifecycleService::class)->assertStartable($this->server);
        } finally {
            DB::disconnect('sqlite');
            unlink($database);
            unlink($source);
        }
    }
}
