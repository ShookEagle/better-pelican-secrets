<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\EggVariable;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\Models\DesiredPlanRecord;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

final class PlanServiceTest extends PluginTestCase
{
    protected bool $isolatedDatabase = true;

    private function bindFixture()
    {
        $this->actor->assignRole(Role::getRootAdmin());
        $secret = $this->app->make(SecretStore::class)->create($this->server, 'KEY', '', new SecretValue('fixture-secret'), $this->actor);
        EggVariable::factory()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'RCON_PASSWORD', 'default_value' => '', 'rules' => 'nullable|string']);
        $plan = $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $secret->secretId);

        return [$secret, $plan];
    }

    public function test_rotation_pins_one_consistent_revision(): void
    {
        Http::fake();
        [$secret, $before] = $this->bindFixture();
        $store = $this->app->make(SecretStore::class);
        $new = $store->replace($secret->secretId, new SecretValue('rotated-fixture'), $this->actor);
        $storedAfterCommit = DesiredPlanRecord::query()->orderByDesc('id')->firstOrFail();
        $this->assertNotSame($before->revision, $storedAfterCommit->revision, 'Rotation did not refresh after commit');
        $after = $this->app->make(PlanService::class)->current($this->server);
        $this->assertNotSame($before->revision, $after->revision);
        $this->assertSame($new->version, $after->versions[0]->version);
        $this->assertStringNotContainsString('fixture-secret', json_encode($after));
        $this->assertStringNotContainsString('rotated-fixture', json_encode($after));
        $first = DesiredPlanRecord::query()->orderBy('id')->firstOrFail();
        $this->assertSame(1, $first->payload['versions'][0]['version']);
        Http::assertNothingSent();
    }

    public function test_revision_above_js_safe_integer_remains_exact(): void
    {
        [, $before] = $this->bindFixture();
        $payload = $before->toWire();
        $payload['revision'] = '9007199254740993';
        DB::table('bps_desired_plan_records')->update(['revision' => '9007199254740993', 'payload' => json_encode($payload)]);
        $after = $this->app->make(BindingService::class)->unbindStartup($this->actor, $this->server, 'RCON_PASSWORD');
        $this->assertSame('9007199254740994', $after->revision);
    }

    public function test_stale_snapshot_is_rejected(): void
    {
        [$secret, $before] = $this->bindFixture();
        $this->app->make(SecretStore::class)->replace($secret->secretId, new SecretValue('rotated-fixture'), $this->actor);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('revision_conflict');
        $this->app->make(PlanService::class)->snapshot($this->server, $before->revision);
    }

    public function test_missed_after_commit_refresh_cannot_deliver_old_version(): void
    {
        [$secret, $before] = $this->bindFixture();
        $fail = true;
        $attempted = false;
        DesiredPlanRecord::creating(function () use (&$fail, &$attempted) {
            $attempted = true;
            if ($fail) {
                throw new RuntimeException('simulated refresh failure');
            }
        });
        $this->app->make(SecretStore::class)->replace($secret->secretId, new SecretValue('rotated-fixture'), $this->actor);
        $this->assertTrue($attempted, 'After-commit refresh was never attempted');
        $fail = false;
        $current = $this->app->make(PlanService::class)->current($this->server);
        $this->assertNotSame($before->revision, $current->revision);
        $this->assertSame(2, $current->versions[0]->version);
    }
}
