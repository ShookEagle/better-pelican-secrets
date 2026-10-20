<?php

require_once __DIR__ . '/PluginTestCase.php';

use App\Models\EggVariable;
use App\Models\Node;
use App\Models\Role;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

abstract class NodeHttpTestCase extends PluginTestCase
{
    protected string $secretId;

    protected string $revision;

    protected string $attemptId = '44444444-4444-4444-8444-444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor->assignRole(Role::getRootAdmin());
        $secret = $this->app->make(SecretStore::class)->create($this->server, 'RCON', '', new SecretValue('fixture-secret'), $this->actor);
        $this->secretId = $secret->secretId;
        EggVariable::factory()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'RCON_PASSWORD', 'default_value' => '', 'rules' => 'nullable|string']);
        $this->revision = $this->app->make(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $secret->secretId)->revision;
    }

    protected function postNode(string $endpoint, array|string $body, ?Node $node = null, bool $authenticated = true, bool $secure = true): TestResponse
    {
        $node ??= $this->server->node;
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($authenticated) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $node->daemon_token_id . '.' . $node->daemon_token;
        }
        $request = Request::create(($secure ? 'https' : 'http') . '://localhost/api/remote/servers/' . $this->server->uuid . '/secrets/' . $endpoint,
            'POST', [], [], [], $headers, is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
        $kernel = $this->app->make(HttpKernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }

    protected function bundleRequest(?string $revision = null): array
    {
        return ['protocol' => 1, 'expected_revision' => $revision ?? $this->revision, 'attempt_id' => $this->attemptId];
    }
}
