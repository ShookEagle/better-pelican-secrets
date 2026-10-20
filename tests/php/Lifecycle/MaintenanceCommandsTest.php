<?php

require_once dirname(__DIR__) . '/Support/NodeHttpTestCase.php';

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretAuditEvent;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretVersion;

final class MaintenanceCommandsTest extends NodeHttpTestCase
{
    public function test_root_maintenance_commands_print_only_counts(): void
    {
        foreach (['secrets:purge-versions', 'secrets:reencrypt'] as $command) {
            $this->assertSame(0, Artisan::call($command, ['actor' => $this->actor->id, 'server' => $this->server->id]));
            $this->assertStringNotContainsString('fixture-secret', Artisan::output());
            $this->assertStringNotContainsString('base64:', Artisan::output());
        }
        $this->assertSame(1, SecretVersion::count());
    }

    public function test_ordinary_actor_cannot_run_root_maintenance(): void
    {
        $ordinary = User::factory()->create();
        foreach (['secrets:purge-versions', 'secrets:reencrypt'] as $command) {
            $this->assertSame(1, Artisan::call($command, ['actor' => $ordinary->id, 'server' => $this->server->id]));
            $this->assertStringNotContainsString('fixture-secret', Artisan::output());
        }
    }

    public function test_host_audit_pruning_uses_configured_default_and_keeps_recent_metadata(): void
    {
        $this->assertSame(90, config('better-pelican-secrets.audit_retention_days'));
        $attributes = ['actor_id' => $this->actor->id, 'operation' => 'secret.create',
            'metadata' => ['server_id' => $this->server->id, 'secret_id' => $this->secretId, 'version' => 1]];
        $old = SecretAuditEvent::create([...$attributes, 'created_at' => now()->subDays(91)]);
        $recent = SecretAuditEvent::create([...$attributes, 'created_at' => now()->subDays(89)]);
        $this->assertSame(0, Artisan::call('secrets:prune-audit'));
        $this->assertFalse(SecretAuditEvent::whereKey($old->id)->exists());
        $this->assertTrue(SecretAuditEvent::whereKey($recent->id)->exists());
        $this->assertSame('host', SecretAuditEvent::where('operation', 'audit.prune')->firstOrFail()->metadata['origin']);
    }

    public function test_abandoned_setup_requires_root_confirmation_and_current_source_digest(): void
    {
        $id = '33333333-3333-4333-8333-333333333333';
        $source = '{{secret:RCON}}';
        DB::table('bps_setup_failures')->insert(['server_id' => $this->server->id, 'file_id' => $id, 'source' => 'config.txt', 'error_code' => 'template_invalid', 'updated_at' => now()]);
        Http::fake(fn () => Http::response($source, 200, ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . $this->server->node->daemon_token_id . ')']));
        $args = ['actor' => $this->actor->id, 'server' => $this->server->id, 'failure' => $id, 'digest' => hash('sha256', $source)];
        $this->assertSame(1, Artisan::call('secrets:retire-setup', $args));
        $this->assertSame(1, Artisan::call('secrets:retire-setup', [...$args, 'digest' => str_repeat('0', 64), '--confirm' => true]));
        $this->assertSame(1, DB::table('bps_setup_failures')->count());
        $this->assertSame(0, Artisan::call('secrets:retire-setup', [...$args, '--confirm' => true]));
        $this->assertSame(0, DB::table('bps_setup_failures')->count());
        $this->assertSame(1, DB::table('bps_startup_bindings')->count());
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
    }
}
