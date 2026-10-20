<?php

require_once dirname(__DIR__) . '/Support/NodeHttpTestCase.php';

use App\Models\EggVariable;
use App\Models\Node;
use App\Services\Servers\ServerConfigurationStructureService;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Auth;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

final class RuntimeBundleTest extends NodeHttpTestCase
{
    public function test_wrong_node_and_browser_cannot_fetch_bundle(): void
    {
        $wrongNode = Node::factory()->create();
        $this->postNode('runtime-bundle', $this->bundleRequest(), $wrongNode)->assertForbidden();
        Auth::login($this->actor);
        $this->postNode('runtime-bundle', $this->bundleRequest(), authenticated: false)->assertForbidden();
    }

    public function test_stale_revision_returns_conflict(): void
    {
        $response = $this->postNode('runtime-bundle', $this->bundleRequest('0'))->assertStatus(409);
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
    }

    public function test_bundle_is_no_store_and_values_are_minimal(): void
    {
        $extra = $this->app->make(SecretStore::class)->create($this->server, 'UNUSED', '', new SecretValue('unused-fixture-secret'), $this->actor);
        $response = $this->postNode('runtime-bundle', $this->bundleRequest());
        $response->assertOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->assertSame([$this->secretId => 'fixture-secret'], $response->json('values'));
        $this->assertSame($this->revision, $response->json('revision'));
        $this->assertSame($this->attemptId, $response->json('attempt_id'));
        $this->assertStringNotContainsString($extra->secretId, $response->getContent());
    }

    public function test_native_configuration_contains_only_reference_plan(): void
    {
        $configuration = $this->app->make(ServerConfigurationStructureService::class)->handle($this->server);
        $this->assertSame($this->revision, $configuration['secrets']['revision']);
        $this->assertArrayNotHasKey('RCON_PASSWORD', $configuration['environment']);
        $this->assertStringNotContainsString('fixture-secret', json_encode($configuration));
        $this->assertArrayNotHasKey('values', $configuration['secrets']);
    }

    public function test_bundle_budget_stops_before_unneeded_secret_reads(): void
    {
        config(['better-pelican-secrets.limits.bundle_bytes' => 4096]);
        $large = str_repeat('x', 8192);
        $this->app->make(SecretStore::class)->replace($this->secretId, new SecretValue($large), $this->actor);
        $second = $this->app->make(SecretStore::class)->create($this->server, 'SECOND', '', new SecretValue($large), $this->actor);
        EggVariable::factory()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'SECOND_KEY', 'default_value' => '', 'rules' => 'nullable|string']);
        $plan = $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'SECOND_KEY', $second->secretId);
        $this->revision = $plan->revision;
        $real = $this->app->make(Illuminate\Contracts\Encryption\Encrypter::class);
        $counter = new class($real->getKey(), config('app.cipher')) extends Encrypter
        {
            public int $reads = 0;

            public function decrypt($payload, $unserialize = true)
            {
                $value = parent::decrypt($payload, $unserialize);
                if (is_string($value) && strlen($value) === 8192 && ++$this->reads > 1) {
                    throw new RuntimeException('Transport budget did not stop further secret reads');
                }

                return $value;
            }
        };
        $this->app->instance(Illuminate\Contracts\Encryption\Encrypter::class, $counter);
        $response = $this->postNode('runtime-bundle', $this->bundleRequest());
        $response->assertStatus(503)->assertJsonPath('error', 'limit_exceeded');
        $this->assertSame(1, $counter->reads);
        $this->assertStringNotContainsString($large, $response->getContent());
    }

    public function test_http_and_malformed_or_oversize_requests_fail_without_values(): void
    {
        $this->postNode('runtime-bundle', $this->bundleRequest(), secure: false)->assertForbidden();
        $this->postNode('runtime-bundle', str_repeat(' ', 4097))->assertStatus(413);
        $this->postNode('runtime-bundle', '{"protocol":1,"protocol":1,"expected_revision":"1","attempt_id":"' . $this->attemptId . '"}')->assertStatus(422);
        $this->postNode('runtime-bundle', ['protocol' => 1, 'expected_revision' => 1, 'attempt_id' => $this->attemptId])->assertStatus(422);
    }
}
