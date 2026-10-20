<?php

require_once dirname(__DIR__) . '/Support/NodeHttpTestCase.php';

use App\Enums\ServerState;
use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Egg;
use App\Models\Node;
use App\Models\Subuser;
use App\Models\User;
use App\Repositories\Daemon\DaemonBackupRepository;
use App\Services\Servers\ServerConfigurationStructureService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Plans\ServerLifecycleService;
use ShookEagle\BetterPelicanSecrets\Secrets\GrantService;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretAuditEvent;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretGrant;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretVersion;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation as Op;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

final class ServerLifecycleTest extends NodeHttpTestCase
{
    public function test_restore_does_not_restore_credentials_or_grants(): void
    {
        $member = User::factory()->create();
        Subuser::create(['server_id' => $this->server->id, 'user_id' => $member->id, 'permissions' => []]);
        app(GrantService::class)->grant($this->actor, $member, new SecretScope($this->server->id), [Op::ListSecrets]);
        app(SecretStore::class)->replace($this->secretId, new SecretValue('rotated-fixture'), $this->actor);
        $deleted = app(SecretStore::class)->create($this->server, 'DELETED', '', new SecretValue('deleted-fixture'), $this->actor);
        app(BindingService::class)->deleteSecret($this->actor, $this->server, $deleted->secretId);
        $grants = SecretGrant::where('server_id', $this->server->id)->get()->toArray();
        $versions = SecretVersion::get()->toArray();
        $backup = Backup::factory()->create(['server_id' => $this->server->id, 'backup_host_id' => BackupHost::factory()->create()->id]);
        Http::fake(fn () => Http::response('', 204, ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . $this->server->node->daemon_token_id . ')']));
        $this->server->update(['status' => ServerState::RestoringBackup]);
        app(DaemonBackupRepository::class)->setServer($this->server)->restore($backup);
        $this->assertSame($grants, SecretGrant::where('server_id', $this->server->id)->get()->toArray());
        $this->assertSame($versions, SecretVersion::get()->toArray());
        $this->assertFalse(Secret::whereKey($deleted->secretId)->exists());
        $this->assertSame(2, app(SecretStore::class)->metadata($this->secretId)->currentVersion);
        Http::assertSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/restore')
            && !str_contains($request->body(), 'fixture'));
        $this->assertNotSame($this->revision, app(PlanService::class)->current($this->server)->revision);
    }

    public function test_transfer_requires_compatible_destination(): void
    {
        $bad = Node::factory()->create(['scheme' => 'https']);
        Http::fake(fn () => Http::response(['protocol' => 0], 200));
        $this->expectException(DomainException::class);
        $this->server->update(['node_id' => $bad->id]);
    }

    public function test_image_and_egg_changes_invalidate_and_unknown_startup_requires_rebinding(): void
    {
        $this->server->update(['image' => 'example.test/new-game:fixture']);
        $this->assertNotSame($this->revision, app(PlanService::class)->current($this->server)->revision);
        $this->server->update(['egg_id' => Egg::factory()->create()->id]);
        try {
            app(ServerConfigurationStructureService::class)->handle($this->server);
            $this->fail('Unknown bound variable reached node configuration');
        } catch (DomainException) {
            $this->assertSame(1, DB::table('bps_startup_bindings')->where('server_id', $this->server->id)->count());
        }
        app(BindingService::class)->unbindStartup($this->actor, $this->server, 'RCON_PASSWORD');
        $this->assertSame([], app(PlanService::class)->current($this->server)->startup);
    }

    public function test_incomplete_setup_vetoes_configuration_and_bundle_delivery(): void
    {
        DB::table('bps_setup_failures')->insert(['server_id' => $this->server->id, 'file_id' => '33333333-3333-4333-8333-333333333333',
            'source' => 'config.json', 'error_code' => 'template_invalid', 'updated_at' => now()]);
        $this->postNode('runtime-bundle', $this->bundleRequest())->assertStatus(503)->assertDontSee('fixture-secret');
        $this->expectException(DomainException::class);
        app(ServerConfigurationStructureService::class)->handle($this->server);
    }

    public function test_server_deletion_removes_plugin_data_but_keeps_audit(): void
    {
        $before = SecretAuditEvent::count();
        app(ServerLifecycleService::class)->delete($this->server);
        $this->server->delete();
        $this->assertSame(0, Secret::where('server_id', $this->server->id)->count());
        $this->assertSame(0, SecretVersion::count());
        $this->assertSame(0, SecretGrant::where('server_id', $this->server->id)->count());
        $this->assertGreaterThanOrEqual($before, SecretAuditEvent::count());
    }
}
