<?php

require_once dirname(__DIR__) . '/Support/NodeHttpTestCase.php';

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Plans\RuntimeReferences;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretVersion;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use ShookEagle\BetterPelicanSecrets\Secrets\VersionRetentionService;

final class VersionRetentionTest extends NodeHttpTestCase
{
    private function applyCurrent(): void
    {
        $plan = app(PlanService::class)->current($this->server);
        $this->postNode('runtime-bundle', $this->bundleRequest($plan->revision))->assertOk();
        $this->postNode('application-report', ['protocol' => 1, 'server_uuid' => $this->server->uuid, 'kind' => 'applied',
            'attempt_id' => $this->attemptId, 'revision' => $plan->revision, 'source_digests' => (object) [],
            'versions' => $plan->toWire()['versions'], 'error_code' => null])->assertOk();
    }

    public function test_purge_keeps_desired_and_applied_versions(): void
    {
        $this->applyCurrent();
        foreach ([2, 3, 4] as $version) {
            app(SecretStore::class)->replace($this->secretId, new SecretValue('fixture-version-' . $version), $this->actor);
            app(PlanService::class)->current($this->server);
        }
        $this->assertSame(2, app(VersionRetentionService::class)->purgeUnreferenced($this->actor, $this->server->id));
        $this->assertSame([1, 4], SecretVersion::where('secret_id', $this->secretId)->orderBy('version')->pluck('version')->all());
    }

    public function test_unbind_does_not_allow_deleting_last_applied_secret(): void
    {
        $this->applyCurrent();
        app(BindingService::class)->unbindStartup($this->actor, $this->server, 'RCON_PASSWORD');
        $this->expectException(DomainException::class);
        app(BindingService::class)->deleteSecret($this->actor, $this->server, $this->secretId);
    }

    public function test_unresolved_issued_versions_survive_until_new_application(): void
    {
        $this->applyCurrent();
        app(SecretStore::class)->replace($this->secretId, new SecretValue('fixture-version-2'), $this->actor);
        $plan = app(PlanService::class)->current($this->server);
        $this->attemptId = '55555555-5555-4555-8555-555555555555';
        $this->postNode('runtime-bundle', $this->bundleRequest($plan->revision))->assertOk();
        app(SecretStore::class)->replace($this->secretId, new SecretValue('fixture-version-3'), $this->actor);
        $this->assertSame(0, app(VersionRetentionService::class)->purgeUnreferenced($this->actor, $this->server->id));
        $this->assertSame([1, 2, 3], SecretVersion::where('secret_id', $this->secretId)->orderBy('version')->pluck('version')->all());
    }

    public function test_reencrypt_uses_previous_keys_without_changing_logical_versions(): void
    {
        $original = app(Illuminate\Contracts\Encryption\Encrypter::class);
        $new = new Encrypter(random_bytes(32), config('app.cipher'));
        $new->previousKeys([$original->getKey()]);
        app()->instance(Illuminate\Contracts\Encryption\Encrypter::class, $new);
        $before = SecretVersion::where('secret_id', $this->secretId)->firstOrFail()->encrypted_value;
        $count = app(VersionRetentionService::class)->reencrypt($this->actor, $this->server->id);
        $this->assertSame(1, $count);
        $after = SecretVersion::where('secret_id', $this->secretId)->firstOrFail()->encrypted_value;
        $this->assertNotSame($before, $after);
        $withoutPrevious = new Encrypter($new->getKey(), config('app.cipher'));
        $this->assertSame('fixture-secret', $withoutPrevious->decrypt($after, false));
        $this->assertSame(1, app(SecretStore::class)->metadata($this->secretId)->currentVersion);
    }

    public function test_purge_is_root_only(): void
    {
        $ordinary = User::factory()->create();
        $this->expectException(AuthorizationException::class);
        app(VersionRetentionService::class)->purgeUnreferenced($ordinary, $this->server->id);
    }

    public function test_empty_application_allows_delete_and_removes_secret_specific_grants(): void
    {
        $this->applyCurrent();
        $plan = app(BindingService::class)->unbindStartup($this->actor, $this->server, 'RCON_PASSWORD');
        $this->attemptId = '55555555-5555-4555-8555-555555555555';
        $this->applyCurrent();
        DB::table('bps_secret_grants')->insert(['user_id' => $this->actor->id, 'server_id' => $this->server->id,
            'secret_id' => $this->secretId, 'operation' => 'list', 'created_by' => $this->actor->id, 'created_at' => now()]);
        app(BindingService::class)->deleteSecret($this->actor, $this->server, $this->secretId);
        $this->assertSame(0, DB::table('bps_secret_grants')->where('secret_id', $this->secretId)->count());
        $this->assertSame(0, SecretVersion::where('secret_id', $this->secretId)->count());
    }

    public function test_source_node_versions_require_explicit_native_transfer_retirement(): void
    {
        $this->applyCurrent();
        $references = app(RuntimeReferences::class);
        $nodeId = $this->server->node_id;
        $this->server->node_id = $nodeId + 1000;
        $this->assertTrue($references->usesSecret($this->server, $this->secretId));
        $references->retireSource($this->server, $nodeId);
        $this->assertFalse($references->usesSecret($this->server, $this->secretId));
    }

    public function test_failed_issued_attempt_does_not_permanently_pin_a_version(): void
    {
        $this->applyCurrent();
        app(SecretStore::class)->replace($this->secretId, new SecretValue('fixture-version-2'), $this->actor);
        $plan = app(PlanService::class)->current($this->server);
        $this->attemptId = '55555555-5555-4555-8555-555555555555';
        $this->postNode('runtime-bundle', $this->bundleRequest($plan->revision))->assertOk();
        $this->postNode('application-report', ['protocol' => 1, 'server_uuid' => $this->server->uuid, 'kind' => 'failed', 'attempt_id' => $this->attemptId,
            'revision' => $plan->revision, 'source_digests' => (object) [], 'versions' => $plan->toWire()['versions'], 'error_code' => 'start_failed'])->assertOk();
        app(SecretStore::class)->replace($this->secretId, new SecretValue('fixture-version-3'), $this->actor);
        $this->assertSame(1, app(VersionRetentionService::class)->purgeUnreferenced($this->actor, $this->server->id));
        $this->assertSame([1, 3], SecretVersion::where('secret_id', $this->secretId)->orderBy('version')->pluck('version')->all());
    }
}
