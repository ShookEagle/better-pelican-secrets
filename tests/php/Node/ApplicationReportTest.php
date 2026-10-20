<?php

require_once dirname(__DIR__) . '/Support/NodeHttpTestCase.php';

use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

final class ApplicationReportTest extends NodeHttpTestCase
{
    private function report(string $kind = 'applied'): array
    {
        return ['protocol' => 1, 'server_uuid' => $this->server->uuid, 'kind' => $kind, 'attempt_id' => $this->attemptId,
            'revision' => $this->revision, 'source_digests' => (object) [],
            'versions' => [['secret_id' => $this->secretId, 'version' => 1]], 'error_code' => $kind === 'failed' ? 'start_failed' : null];
    }

    public function test_old_report_cannot_clear_new_pending_revision(): void
    {
        $this->postNode('runtime-bundle', $this->bundleRequest())->assertOk();
        $this->app->make(SecretStore::class)->replace($this->secretId, new SecretValue('rotated-fixture'), $this->actor);
        $response = $this->postNode('application-report', $this->report());
        $response->assertOk()->assertJsonPath('applied_revision', $this->revision)->assertJsonPath('pending', true);
        $this->assertNotSame($this->revision, $response->json('desired_revision'));
    }

    public function test_reports_are_idempotent_and_metadata_only(): void
    {
        $this->postNode('runtime-bundle', $this->bundleRequest())->assertOk();
        $first = $this->postNode('application-report', $this->report());
        $first->assertOk();
        $this->assertTrue($first->headers->hasCacheControlDirective('no-store'));
        $count = DB::table('bps_application_records')->count();
        $this->postNode('application-report', $this->report())->assertOk();
        $this->assertSame($count, DB::table('bps_application_records')->count());
        $this->assertStringNotContainsString('fixture-secret', json_encode(DB::table('bps_application_records')->get()));
    }

    public function test_unissued_attempt_and_arbitrary_error_are_rejected(): void
    {
        $this->postNode('application-report', $this->report())->assertStatus(409);
        $this->postNode('runtime-bundle', $this->bundleRequest())->assertOk();
        $bad = $this->report('failed');
        $bad['error_code'] = 'fixture-secret';
        $this->postNode('application-report', $bad)->assertStatus(422);
        $bad = $this->report();
        $bad['source_digests'] = [];
        $this->postNode('application-report', $bad)->assertStatus(422);
    }
}
