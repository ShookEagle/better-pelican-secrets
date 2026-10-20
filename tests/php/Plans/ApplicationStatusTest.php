<?php

require_once dirname(__DIR__) . '/Support/NodeHttpTestCase.php';

use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Plans\ApplicationStatusService;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateSourceService;

final class ApplicationStatusTest extends NodeHttpTestCase
{
    private string $fileId = '33333333-3333-4333-8333-333333333333';

    private string $digest;

    private int $observationSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $source = '{"key":{{secret:RCON}}}';
        $file = new FilePlan($this->fileId, 'configs/plugin.json', '/game/configs/plugin.json', 'json', 'json-string', [
            ['secret_id' => $this->secretId, 'name' => 'RCON', 'mode' => 'json-string', 'count' => 1],
        ]);
        $this->revision = $this->app->make(BindingService::class)->saveFile($this->actor, $this->server, $file, $source)->revision;
        $this->digest = hash('sha256', $source);
    }

    private function report(string $kind = 'applied', ?string $digest = null, int $version = 1): array
    {
        $report = ['protocol' => 1, 'server_uuid' => $this->server->uuid, 'kind' => $kind, 'attempt_id' => $this->attemptId,
            'revision' => $this->revision, 'source_digests' => (object) [$this->fileId => $digest ?? $this->digest],
            'versions' => [['secret_id' => $this->secretId, 'version' => $version]], 'error_code' => null];
        if ($kind === 'source-dirty') {
            $report['observation_sequence'] = (string) ++$this->observationSequence;
        }

        return $report;
    }

    private function apply(): void
    {
        $this->postNode('runtime-bundle', $this->bundleRequest())->assertOk();
        $this->postNode('application-report', $this->report())->assertOk();
    }

    public function test_source_change_remains_pending_after_old_report(): void
    {
        $this->apply();
        $this->postNode('application-report', $this->report('source-dirty', str_repeat('b', 64)))->assertOk();
        $this->postNode('application-report', $this->report())->assertOk();
        $status = $this->app->make(ApplicationStatusService::class)->forServer($this->server);
        $this->assertTrue($status->pending);
        $this->assertTrue($status->dirty);
        $this->assertSame($this->attemptId, $status->lastAttemptId);
        $this->app->make(SecretStore::class)->replace($this->secretId, new SecretValue('rotated-fixture'), $this->actor);
        $this->postNode('application-report', $this->report('source-dirty'))->assertOk();
        $status = $this->app->make(ApplicationStatusService::class)->forServer($this->server);
        $this->assertTrue($status->pending);
        $this->assertFalse($status->dirty);
        $this->assertNotSame($status->desiredRevision, $status->appliedRevision);
    }

    public function test_returning_to_original_source_clears_dirty_after_a_b_a(): void
    {
        $this->apply();
        $this->postNode('application-report', $this->report('source-dirty'))->assertOk()->assertJsonPath('dirty', false);
        $this->postNode('application-report', $this->report('source-dirty', str_repeat('b', 64)))->assertOk()->assertJsonPath('dirty', true);
        $this->postNode('application-report', $this->report('source-dirty'))->assertOk()->assertJsonPath('dirty', false)->assertJsonPath('pending', false);
    }

    public function test_late_applied_and_dirty_reports_cannot_replace_newer_start(): void
    {
        $old = $this->report();
        $this->postNode('runtime-bundle', $this->bundleRequest())->assertOk();
        $this->app->make(SecretStore::class)->replace($this->secretId, new SecretValue('rotated-fixture'), $this->actor);
        $this->revision = $this->app->make(PlanService::class)->current($this->server)->revision;
        $this->attemptId = '55555555-5555-4555-8555-555555555555';
        $this->postNode('runtime-bundle', $this->bundleRequest())->assertOk();
        $this->postNode('application-report', $this->report(version: 2))->assertOk();
        $this->postNode('application-report', $old)->assertOk()->assertJsonPath('applied_revision', $this->revision)->assertJsonPath('pending', false);
        $old['kind'] = 'source-dirty';
        $old['observation_sequence'] = '1';
        $old['source_digests'] = (object) [$this->fileId => str_repeat('b', 64)];
        $this->postNode('application-report', $old)->assertOk()->assertJsonPath('dirty', false);
        $status = $this->app->make(ApplicationStatusService::class)->forServer($this->server);
        $this->assertSame($this->attemptId, $status->lastAttemptId);
    }

    public function test_invalid_source_observation_marks_pending_without_advancing_applied(): void
    {
        $this->apply();
        $dirty = $this->report('source-dirty');
        $dirty['source_digests'] = (object) [];
        $this->postNode('application-report', $dirty)->assertOk();
        $status = $this->app->make(ApplicationStatusService::class)->forServer($this->server);
        $this->assertTrue($status->dirty);
        $this->assertTrue($status->pending);
        $this->assertSame('template_invalid', $status->lastErrorCode);
        $this->assertStringNotContainsString('fixture-secret', json_encode(DB::table('bps_application_records')->get()));
    }

    public function test_unconfigured_server_has_no_pending_secret_changes(): void
    {
        $server = Server::factory()->create(['owner_id' => $this->actor->id]);
        $status = $this->app->make(ApplicationStatusService::class)->forServer($server);
        $this->assertSame('0', $status->desiredRevision);
        $this->assertNull($status->appliedRevision);
        $this->assertFalse($status->pending);
    }

    public function test_stale_source_observation_cannot_clear_a_newer_dirty_state(): void
    {
        $this->apply();
        $stale = $this->report('source-dirty');
        $this->postNode('application-report', $stale)->assertOk();
        $this->postNode('application-report', $this->report('source-dirty', str_repeat('b', 64)))->assertOk()->assertJsonPath('dirty', true);
        $this->postNode('application-report', $stale)->assertOk()->assertJsonPath('dirty', true)->assertJsonPath('pending', true);
    }

    public function test_reused_counter_with_changed_payload_is_rejected(): void
    {
        $this->apply();
        $report = $this->report('source-dirty');
        $this->postNode('application-report', $report)->assertOk();
        $report['source_digests'] = (object) [$this->fileId => str_repeat('b', 64)];
        $this->postNode('application-report', $report)->assertStatus(422);
        $status = $this->app->make(ApplicationStatusService::class)->forServer($this->server);
        $this->assertFalse($status->dirty);
    }

    public function test_fresh_unchanged_observations_do_not_grow_immutable_history(): void
    {
        $this->apply();
        $this->postNode('application-report', $this->report('source-dirty'))->assertOk();
        $count = DB::table('bps_application_records')->count();
        $this->postNode('application-report', $this->report('source-dirty'))->assertOk();
        $this->assertSame($count, DB::table('bps_application_records')->count());
    }

    public function test_panel_source_save_survives_retry_but_reconciles_fresh_observation(): void
    {
        $this->apply();
        $report = $this->report('source-dirty');
        $this->postNode('application-report', $report)->assertOk();
        $source = '{"key":{{secret:RCON}}}';
        Http::fake(function ($request) use (&$source) {
            $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . $this->server->node->daemon_token_id . ')'];
            if ($request->method() === 'POST') {
                $source = $request->body();

                return Http::response('', 204, $headers);
            }

            return Http::response($source, 200, [...$headers, 'Content-Length' => strlen($source)]);
        });
        $this->app->make(TemplateSourceService::class)
            ->save($this->actor, $this->server, $this->fileId, '{"key":{{secret:RCON}},"changed":1}', $this->digest);
        $this->postNode('application-report', $report)->assertOk()->assertJsonPath('dirty', true);
        // An external writer can restore A between node polls. A fresh poll
        // must reconcile that state even though the node never observed B.
        $source = '{"key":{{secret:RCON}}}';
        $this->postNode('application-report', $this->report('source-dirty'))->assertOk()->assertJsonPath('dirty', false);
    }
}
