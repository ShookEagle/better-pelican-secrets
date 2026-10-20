<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\FileTemplateActions;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Plans\ApplicationStatusService;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ManagedFile;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use ShookEagle\BetterPelicanSecrets\Templates\MigrationService;

final class MigrationServiceTest extends PluginTestCase
{
    private string $source = '{"key":"legacy-value"}';

    private string $id;

    private bool $exists = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor->assignRole(Role::getRootAdmin());
        $this->id = app(SecretStore::class)->create($this->server, 'API_KEY', '', new SecretValue('fixture-secret'), $this->actor)->secretId;
        Http::fake(function ($request) {
            $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . $this->server->node->daemon_token_id . ')'];
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/files/contents')) {
                return $this->exists ? Http::response($this->source, 200, $headers) : Http::response('', 404, $headers);
            }
            if (str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/files/write')) {
                $this->source = $request->body();
                $this->exists = true;

                return Http::response('', 204, $headers);
            }
            throw new RuntimeException('Migration requested an unexpected endpoint.');
        });
    }

    public function test_remote_write_survives_failed_plan_commit_and_is_reported_pending(): void
    {
        $fail = true;
        ManagedFile::saving(function ($file) use (&$fail) {
            if ($fail) {
                $fail = false;
                throw new RuntimeException('Synthetic database failure');
            }
        });
        $replacement = '{"key":{{secret:API_KEY}}}';
        $references = [['secret_id' => $this->id, 'mode' => 'json-string']];
        try {
            app(MigrationService::class)->migrate($this->actor, $this->server, hash('sha256', $this->source), $replacement, $this->file(), $references);
            $this->fail('Failed reference plan was reported as saved');
        } catch (DomainException $error) {
            $this->assertSame('source_saved_plan_failed', $error->getMessage());
        }
        $this->assertSame($replacement, $this->source);
        $failure = DB::table('bps_setup_failures')->first();
        $this->assertSame($this->file()->source, $failure->source);
        $status = app(ApplicationStatusService::class)->forServer($this->server);
        $this->assertTrue($status->pending);
        $this->assertSame('template_invalid', $status->lastErrorCode);
        app(MigrationService::class)->migrate($this->actor, $this->server, hash('sha256', $this->source), $replacement, $this->file(), $references);
        $this->assertSame(0, DB::table('bps_setup_failures')->count());
    }

    public function test_new_file_requires_a_current_absence_receipt(): void
    {
        $this->exists = false;
        $draft = app(FileTemplateActions::class)->open($this->actor, $this->server, $this->file()->source);
        $this->assertSame('absent', $draft['digest']);
        $this->assertSame('', $draft['content']);
        $references = [['secret_id' => $this->id, 'mode' => 'json-string']];
        $replacement = '{"key":{{secret:API_KEY}}}';
        $this->exists = true;
        try {
            app(MigrationService::class)->migrate($this->actor, $this->server, 'absent', $replacement, $this->file(), $references);
            $this->fail('Newly created file was overwritten using an old absence receipt');
        } catch (DomainException $error) {
            $this->assertSame('source_conflict', $error->getMessage());
        }
        $this->exists = false;
        app(MigrationService::class)->migrate($this->actor, $this->server, 'absent', $replacement, $this->file(), $references);
        $this->assertTrue($this->exists);
        $this->assertSame($replacement, $this->source);
    }

    private function file(): FilePlan
    {
        return new FilePlan('33333333-3333-4333-8333-333333333333', 'configs/plugin.json', '/game/configs/plugin.json', 'json', 'json-string',
            [['secret_id' => $this->id, 'name' => 'API_KEY', 'mode' => 'json-string', 'count' => 1]]);
    }

    public function test_migration_requires_confirmation_and_current_digest(): void
    {
        $service = app(MigrationService::class);
        $replacement = '{"key":{{secret:API_KEY}}}';
        $before = $this->source;
        $references = [['secret_id' => $this->id, 'mode' => 'json-string']];
        foreach ([
            [hash('sha256', $before), []],
            [hash('sha256', 'outdated'), $references],
        ] as [$digest, $confirmed]) {
            try {
                $service->migrate($this->actor, $this->server, $digest, $replacement, $this->file(), $confirmed);
                $this->fail('Unconfirmed or stale migration succeeded');
            } catch (DomainException) {
                $this->assertSame($before, $this->source);
            }
        }
        $plan = $service->migrate($this->actor, $this->server, hash('sha256', $before), $replacement, $this->file(), $references);
        $this->assertSame($replacement, $this->source);
        $this->assertSame('1', $plan->revision);
        $this->assertSame(hash('sha256', $replacement), ManagedFile::findOrFail($this->file()->fileId)->source_digest);
        $this->assertStringNotContainsString('fixture-secret', ManagedFile::all()->toJson());
        Http::assertNotSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/power'));
    }
}
