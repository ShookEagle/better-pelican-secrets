<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Templates\PreviewService;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateDocument;

final class PreviewServiceTest extends PluginTestCase
{
    public function test_preview_never_reads_secret_store(): void
    {
        $this->app->bind(SecretStore::class, fn () => throw new LogicException('Preview attempted to resolve the real secret store'));
        $document = new TemplateDocument('{"key":{{secret:KEY}}}', 'json', 'json-string', [
            ['secret_id' => '22222222-2222-4222-8222-222222222222', 'name' => 'KEY', 'mode' => 'json-string', 'count' => 1],
        ]);
        $preview = $this->app->make(PreviewService::class)->preview($this->actor, $this->server->id, $document);
        $this->assertSame('DUMMY_VALUE', json_decode($preview, true, flags: JSON_THROW_ON_ERROR)['key']);
        $this->assertStringNotContainsString('fixture-secret', $preview);
        $this->assertSame($preview, $this->app->make(PreviewService::class)->preview($this->actor, $this->server->id, $document));
    }

    public function test_preview_requires_normal_server_file_access(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->app->make(PreviewService::class)->preview(User::factory()->create(), $this->server->id, new TemplateDocument('example', 'text', 'raw', []));
    }
}
