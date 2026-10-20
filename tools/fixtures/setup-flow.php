<?php

use App\Models\EggVariable;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\FileTemplateActions;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\StartupBindingActions;
use ShookEagle\BetterPelicanSecrets\Node\NodeCapabilityService;
use ShookEagle\BetterPelicanSecrets\Plans\PlanService;
use ShookEagle\BetterPelicanSecrets\Providers\BetterPelicanSecretsProvider;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

require '/workspace/tests/php/bootstrap.php';
$app = require '/workspace/.work/panel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
try {
    if (!$app->environment('testing') || config('database.connections.sqlite.database') !== ':memory:' || !is_dir('/fixture-data')) {
        throw new RuntimeException('Only the disposable setup fixture is permitted.');
    }
    $app->register(BetterPelicanSecretsProvider::class);
    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('migrate', ['--path' => '/workspace/plugins/better-pelican-secrets/database/migrations', '--realpath' => true, '--force' => true]);
    $actor = User::factory()->create();
    $actor->assignRole(Role::getRootAdmin());
    $server = Server::factory()->create(['owner_id' => $actor->id]);
    $server->node->update(['scheme' => 'https']);
    Http::fake(function ($request) use ($server) {
        $headers = ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . $server->node->daemon_token_id . ')'];
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/api/system') {
            return Http::response(['protocol' => 1, 'wings_base_commit' => NodeCapabilityService::WINGS_BASE,
                'panel_base_version' => NodeCapabilityService::PANEL_VERSION], 200, $headers);
        }
        parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
        $file = $query['file'] ?? '';
        if (!in_array($file, ['config.json', 'config.yaml', '.env', 'config.txt'], true)) {
            throw new RuntimeException('Unexpected setup fixture path.');
        }
        $target = '/fixture-data/' . $file;
        if (str_ends_with($path, '/files/contents')) {
            return is_file($target) ? Http::response(file_get_contents($target), 200, $headers) : Http::response('', 404, $headers);
        }
        if (str_ends_with($path, '/files/write')) {
            file_put_contents($target, $request->body());
            chmod($target, 0644);

            return Http::response('', 204, $headers);
        }
        throw new RuntimeException('Unexpected setup fixture endpoint.');
    });
    app(NodeCapabilityService::class)->assertCompatible($server->node);
    $secret = app(SecretStore::class)->create($server, 'API_KEY', '', new SecretValue('fixture-secret'), $actor);
    $actions = app(FileTemplateActions::class);
    foreach ([
        ['config.json', 'json', 'json-string', '{"key":"legacy-value"}', '{"key":{{secret:API_KEY}}}'],
        ['config.yaml', 'yaml', 'yaml-string', 'key: legacy-value', 'key: {{secret:API_KEY}}'],
        ['.env', 'dotenv', 'dotenv', 'KEY=legacy-value', 'KEY={{secret:API_KEY}}'],
        ['config.txt', 'text', 'raw', 'key=legacy-value', 'key={{secret:API_KEY}}'],
    ] as [$path, $format, $mode, $legacy, $source]) {
        file_put_contents('/fixture-data/' . $path, $legacy);
        chmod('/fixture-data/' . $path, 0644);
        $draft = $actions->open($actor, $server, $path);
        $draft = [...$draft, 'destination' => '/game/' . $path, 'format' => $format, 'mode' => $mode,
            'content' => $source, 'format_confirmed' => true, 'readonly_confirmed' => true, 'migration_confirmed' => true];
        $prepared = $actions->prepareDraft($actor, $server, $draft);
        if (str_contains($prepared['preview'], 'fixture-secret') || !str_contains($prepared['preview'], 'DUMMY_VALUE')) {
            throw new RuntimeException('Preview resolved a value.');
        }
        $draft['references'] = array_map(fn ($entry) => ['secret_id' => $entry['secret_id'], 'mode' => $entry['mode']], $prepared['file']->manifest);
        $actions->saveDraft($actor, $server, $draft);
    }
    EggVariable::factory()->create(['egg_id' => $server->egg_id, 'env_variable' => 'BPS_TOKEN',
        'default_value' => 'legacy-startup', 'rules' => ['nullable', 'string']]);
    $startup = app(StartupBindingActions::class);
    $startup->migrate($actor, $server, 'BPS_TOKEN', $secret->secretId, hash('sha256', 'legacy-startup'), true);
    $plan = app(PlanService::class)->current($server);
    $node = $server->node;
    $request = Request::create('https://localhost/api/remote/servers/' . $server->uuid . '/secrets/runtime-bundle', 'POST', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $node->daemon_token_id . '.' . $node->daemon_token],
        json_encode(['protocol' => 1, 'expected_revision' => $plan->revision, 'attempt_id' => Str::uuid()->toString()]));
    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($request);
    if ($response->getStatusCode() !== 200) {
        throw new RuntimeException('Synthetic node bundle request failed.');
    }
    $bundle = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);
    $kernel->terminate($request, $response);
    // Intentional private synthetic runtime IPC to the node probe, never an ordinary saved artifact.
    echo json_encode(['plan' => $plan->toWire(), 'bundle' => $bundle], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Disposable setup fixture failed.\n");
    exit(1);
}
