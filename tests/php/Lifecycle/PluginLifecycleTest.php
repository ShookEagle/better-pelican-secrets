<?php

require_once dirname(__DIR__) . '/Support/NodeHttpTestCase.php';

use App\Models\Plugin;
use App\Services\Helpers\PluginService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ShookEagle\BetterPelicanSecrets\Integration\GuardedPluginService;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\ServerLifecycleService;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretAuditEvent;

final class PluginLifecycleTest extends NodeHttpTestCase
{
    protected bool $isolatedDatabase = true;

    public function test_bound_plugin_cannot_disable_or_uninstall(): void
    {
        $this->assertSame(GuardedPluginService::class, app(PluginService::class)::class);
        $plugin = Plugin::findOrFail('better-pelican-secrets');
        $path = plugin_path($plugin->id, 'plugin.json');
        $hash = hash_file('sha256', $path);
        foreach (['disablePlugin', 'uninstallPlugin', 'deletePlugin', 'rollbackPluginMigrations'] as $method) {
            try {
                app(PluginService::class)->$method($plugin);
                $this->fail('Active integration escaped ' . $method);
            } catch (DomainException) {
                $this->assertSame($hash, hash_file('sha256', $path));
                $this->assertTrue(Schema::hasTable('bps_secret_versions'));
            }
        }
    }

    public function test_unbinding_requires_retirement_before_disable(): void
    {
        app(BindingService::class)->unbindStartup($this->actor, $this->server, 'RCON_PASSWORD');
        $this->expectException(DomainException::class);
        app(ServerLifecycleService::class)->assertIntegrationCanDisable();
    }

    public function test_empty_application_retires_integration_and_rollback_preserves_audit(): void
    {
        $plan = app(BindingService::class)->unbindStartup($this->actor, $this->server, 'RCON_PASSWORD');
        $this->postNode('runtime-bundle', $this->bundleRequest($plan->revision))->assertOk();
        $this->postNode('application-report', ['protocol' => 1, 'server_uuid' => $this->server->uuid, 'kind' => 'applied',
            'attempt_id' => $this->attemptId, 'revision' => $plan->revision, 'source_digests' => (object) [],
            'versions' => [], 'error_code' => null])->assertOk();
        app(ServerLifecycleService::class)->assertIntegrationCanDisable();
        $before = SecretAuditEvent::count();
        app(PluginService::class)->rollbackPluginMigrations(Plugin::findOrFail('better-pelican-secrets'));
        $this->assertFalse(Schema::hasTable('bps_secret_versions'));
        $this->assertTrue(Schema::hasTable('bps_secret_audit_events'));
        $this->assertSame($before, SecretAuditEvent::count());
        app(ServerLifecycleService::class)->assertIntegrationCanDisable();
        app(PluginService::class)->runPluginMigrations(Plugin::findOrFail('better-pelican-secrets'));
        $this->assertTrue(Schema::hasTable('bps_secret_versions'));
        $this->assertSame($before, SecretAuditEvent::count());
    }

    public function test_incomplete_setup_blocks_disable_even_without_a_binding(): void
    {
        app(BindingService::class)->unbindStartup($this->actor, $this->server, 'RCON_PASSWORD');
        DB::table('bps_setup_failures')->insert(['server_id' => $this->server->id,
            'file_id' => '33333333-3333-4333-8333-333333333333', 'source' => 'config.json', 'error_code' => 'template_invalid', 'updated_at' => now()]);
        $this->expectException(DomainException::class);
        app(ServerLifecycleService::class)->assertIntegrationCanDisable();
    }
}
