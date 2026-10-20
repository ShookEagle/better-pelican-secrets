<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\EggVariable;
use App\Models\Role;
use App\Models\ServerVariable;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Secrets\GrantService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

final class BindingServiceTest extends PluginTestCase
{
    private function root(): User
    {
        $root = User::factory()->create();
        $root->assignRole(Role::getRootAdmin());

        return $root;
    }

    private function secret(string $name = 'KEY')
    {
        return $this->app->make(SecretStore::class)->create($this->server, $name, '', new SecretValue('fixture-secret'), $this->actor);
    }

    private function eggVariable(): EggVariable
    {
        return EggVariable::factory()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'RCON_PASSWORD',
            'default_value' => '', 'user_viewable' => 1, 'user_editable' => 1, 'rules' => 'nullable|string']);
    }

    public function test_bind_requires_all_secret_and_destination_rights(): void
    {
        $one = $this->secret('ONE');
        $two = $this->secret('TWO');
        $member = User::factory()->create();
        Subuser::query()->create(['user_id' => $member->id, 'server_id' => $this->server->id, 'permissions' => ['file.update']]);
        $grants = $this->app->make(GrantService::class);
        $root = $this->root();
        $grants->grant($root, $member, new SecretScope($this->server->id, $one->secretId), [SecretOperation::Bind]);
        $file = new FilePlan('33333333-3333-4333-8333-333333333333', 'configs/plugin.cfg', '/game/configs/plugin.cfg', 'text', 'raw', [
            ['secret_id' => $one->secretId, 'name' => 'ONE', 'mode' => 'raw', 'count' => 1],
            ['secret_id' => $two->secretId, 'name' => 'TWO', 'mode' => 'raw', 'count' => 1],
        ]);
        $service = $this->app->make(BindingService::class);
        try {
            $service->saveFile($member, $this->server, $file, '{{secret:ONE}} {{secret:TWO}}');
            $this->fail('Missing bind permission accepted');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('bps_managed_files')->count());
        }
        $grants->grant($root, $member, new SecretScope($this->server->id, $two->secretId), [SecretOperation::Bind]);
        $saved = $service->saveFile($member, $this->server, $file, '{{secret:ONE}} {{secret:TWO}}');
        $this->assertCount(2, $saved->versions);
        DB::table('subusers')->where('user_id', $member->id)->update(['permissions' => '[]']);
        Context::flush();
        $member = $member->fresh();
        $this->expectException(AuthorizationException::class);
        $service->saveFile($member, $this->server, $file, '{{secret:ONE}} {{secret:TWO}}');
    }

    public function test_bound_variable_never_stores_value(): void
    {
        Http::fake();
        $secret = $this->secret();
        $this->eggVariable();
        $this->app->make(GrantService::class)->grant($this->root(), $this->actor, new SecretScope($this->server->id), [SecretOperation::Bind]);
        $plan = $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $secret->secretId);
        $this->assertSame('RCON_PASSWORD', $plan->startup[0]->env);
        $this->assertSame($secret->secretId, $plan->startup[0]->secretId);
        $this->assertStringNotContainsString('fixture-secret', json_encode($plan));
        $this->assertStringNotContainsString('fixture-secret', json_encode(DB::table('server_variables')->get()));
        $this->assertStringNotContainsString('fixture-secret', json_encode(DB::table('bps_desired_plan_records')->get()));
        Http::assertNothingSent();
    }

    public function test_bound_secret_cannot_be_deleted(): void
    {
        $secret = $this->secret();
        $this->eggVariable();
        $root = $this->root();
        $service = $this->app->make(BindingService::class);
        $service->bindStartup($root, $this->server, 'RCON_PASSWORD', $secret->secretId);
        try {
            $service->deleteSecret($root, $this->server, $secret->secretId);
            $this->fail('Bound secret deleted');
        } catch (DomainException $exception) {
            $this->assertSame('secret_in_use', $exception->getMessage());
        }
        $service->unbindStartup($root, $this->server, 'RCON_PASSWORD');
        $service->deleteSecret($root, $this->server, $secret->secretId);
        $this->assertSame(0, DB::table('bps_secret_versions')->count());
    }

    public function test_legacy_plaintext_requires_explicit_migration(): void
    {
        $secret = $this->secret();
        $variable = $this->eggVariable();
        ServerVariable::query()->create(['server_id' => $this->server->id, 'variable_id' => $variable->id, 'variable_value' => 'legacy-fixture']);
        try {
            $this->app->make(BindingService::class)->bindStartup($this->root(), $this->server, 'RCON_PASSWORD', $secret->secretId);
            $this->fail('Existing value silently migrated');
        } catch (DomainException $exception) {
            $this->assertSame('startup_migration_required', $exception->getMessage());
        }
        $this->assertSame('legacy-fixture', ServerVariable::query()->firstOrFail()->variable_value);
        $this->assertSame(0, DB::table('bps_startup_bindings')->count());
    }

    public function test_template_binding_persists_references_and_digest_only(): void
    {
        Http::fake();
        $secret = $this->secret();
        $source = '{"apiKey":{{secret:KEY}}}';
        $file = new FilePlan('33333333-3333-4333-8333-333333333333', 'configs/plugin.json', '/game/configs/plugin.json', 'json', 'json-string', [
            ['secret_id' => $secret->secretId, 'name' => 'KEY', 'mode' => 'json-string', 'count' => 1],
        ]);
        $plan = $this->app->make(BindingService::class)->saveFile($this->root(), $this->server, $file, $source);
        $row = DB::table('bps_managed_files')->first();
        $this->assertSame(hash('sha256', $source), $row->source_digest);
        $this->assertStringNotContainsString('fixture-secret', json_encode($row));
        $this->assertStringNotContainsString('fixture-secret', json_encode($plan));
        $this->assertSame(1, $plan->versions[0]->version);
        Http::assertNothingSent();
    }
}
