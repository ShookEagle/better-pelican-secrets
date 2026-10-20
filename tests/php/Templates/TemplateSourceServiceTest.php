<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\Role;
use App\Models\Subuser;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateSourceService;

final class TemplateSourceServiceTest extends PluginTestCase
{
    private string $fileId = '33333333-3333-4333-8333-333333333333';

    private string $source = '{"key":{{secret:KEY}}}';

    private string $secretId;

    private ?int $declaredLength = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor->assignRole(Role::getRootAdmin());
        $secret = $this->app->make(SecretStore::class)->create($this->server, 'KEY', '', new SecretValue('fixture-secret'), $this->actor);
        $this->secretId = $secret->secretId;
        $file = new FilePlan($this->fileId, 'configs/plugin.json', '/game/configs/plugin.json', 'json', 'json-string', [
            ['secret_id' => $this->secretId, 'name' => 'KEY', 'mode' => 'json-string', 'count' => 1],
        ]);
        $this->app->make(BindingService::class)->saveFile($this->actor, $this->server, $file, $this->source);
        $this->actor->syncRoles([]);
        $member = User::factory()->create();
        Subuser::query()->create(['user_id' => $member->id, 'server_id' => $this->server->id, 'permissions' => ['file.read-content', 'file.update']]);
        $this->actor = $member;
        Http::fake(function ($request) {
            $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . $this->server->node->daemon_token_id . ')'];
            $path = parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/files/contents')) {
                return Http::response($this->source, 200, [...$headers, 'Content-Length' => $this->declaredLength ?? strlen($this->source)]);
            }
            if (str_ends_with($path, '/files/write')) {
                $this->source = $request->body();

                return Http::response('', 204, $headers);
            }
            throw new RuntimeException('Editor requested a non-source endpoint.');
        });
    }

    public function test_editor_never_fetches_rendered_file(): void
    {
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        $source = $this->app->make(TemplateSourceService::class)->read($this->actor, $this->server, $this->fileId);
        $this->assertStringContainsString('{{secret:KEY}}', $source);
        $this->assertStringNotContainsString('fixture-secret', $source);
        $this->assertFalse((bool) array_filter($queries, fn ($sql) => str_contains($sql, 'bps_secret_versions')));
        Http::assertSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/files/contents') && $request['file'] === 'configs/plugin.json');
        $this->expectException(AuthorizationException::class);
        $this->app->make(SecretAuthorizer::class)->authorize($this->actor, SecretOperation::Reveal, new SecretScope($this->server->id, $this->secretId));
    }

    public function test_stale_save_returns_conflict(): void
    {
        $digest = hash('sha256', $this->source);
        $this->source = '{"changed":true,"key":{{secret:KEY}}}';
        try {
            $this->app->make(TemplateSourceService::class)->save($this->actor, $this->server, $this->fileId, '{"key":{{secret:KEY}},"new":1}', $digest);
            $this->fail('Stale editor save overwrote current source');
        } catch (DomainException $error) {
            $this->assertSame('source_conflict', $error->getMessage());
        }
        $this->assertStringContainsString('"changed":true', $this->source);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_unapproved_reference_does_not_render(): void
    {
        $before = $this->source;
        try {
            $this->app->make(TemplateSourceService::class)->save($this->actor, $this->server, $this->fileId, '{"key":{{secret:OTHER}}}', hash('sha256', $before));
            $this->fail('Unapproved reference accepted');
        } catch (DomainException $error) {
            $this->assertSame('manifest_mismatch', $error->getMessage());
        }
        $this->assertSame($before, $this->source);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_surrounding_text_can_be_saved_without_secret_rights(): void
    {
        $updated = '{"key":{{secret:KEY}},"ordinary":2}';
        $this->app->make(TemplateSourceService::class)->save($this->actor, $this->server, $this->fileId, $updated, hash('sha256', $this->source));
        $this->assertSame($updated, $this->source);
        $this->assertSame(hash('sha256', $updated), DB::table('bps_managed_files')->value('source_digest'));
        $this->assertStringNotContainsString('fixture-secret', json_encode(DB::table('bps_managed_files')->get()));
    }

    public function test_ordinary_file_update_permission_is_required(): void
    {
        $viewer = User::factory()->create();
        Subuser::query()->create(['user_id' => $viewer->id, 'server_id' => $this->server->id, 'permissions' => ['file.read-content']]);
        $this->expectException(AuthorizationException::class);
        $this->app->make(TemplateSourceService::class)->save($viewer, $this->server, $this->fileId, $this->source, hash('sha256', $this->source));
    }

    public function test_stream_is_bounded_even_when_content_length_is_wrong(): void
    {
        config(['better-pelican-secrets.limits.template_bytes' => 128]);
        $this->source = str_repeat('x', 129);
        $this->declaredLength = 1;
        try {
            $this->app->make(TemplateSourceService::class)->read($this->actor, $this->server, $this->fileId);
            $this->fail('Oversized source accepted');
        } catch (DomainException $error) {
            $this->assertSame('limit_exceeded', $error->getMessage());
        }
    }
}
