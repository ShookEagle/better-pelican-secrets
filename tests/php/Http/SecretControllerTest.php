<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Models\ApiKey;
use App\Models\Role;
use App\Models\Server;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use ShookEagle\BetterPelicanSecrets\Secrets\GrantService;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretAuditEvent;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation as Op;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

final class SecretControllerTest extends PluginTestCase
{
    protected bool $isolatedDatabase = true;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = app(SecretStore::class)->create($this->server, 'RCON', '', new SecretValue('fixture-secret'), $this->actor)->secretId;
    }

    private function grant(Op ...$operations): void
    {
        $root = User::factory()->create();
        $root->assignRole(Role::getRootAdmin());
        app(GrantService::class)->grant($root, $this->actor, new SecretScope($this->server->id), $operations);
    }

    private function request(string $method, string $suffix = '', array $body = [], bool $authenticated = true, ?ApiKey $key = null): TestResponse
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($key) {
            app('auth')->forgetGuards();
            $headers['HTTP_AUTHORIZATION'] = 'Bearer ' . $key->identifier . $key->token;
        } elseif ($authenticated) {
            Sanctum::actingAs($this->actor);
        }
        $request = Request::create('https://localhost/api/client/servers/' . $this->server->uuid . '/secrets' . $suffix,
            $method, [], [], [], $headers, json_encode($body));
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }

    public function test_list_and_reveal_require_distinct_explicit_grants(): void
    {
        $this->request('GET')->assertForbidden();
        $this->grant(Op::ListSecrets);
        $this->request('GET')->assertOk()->assertDontSee('fixture-secret')->assertJsonPath('data.0.name', 'RCON');
        $this->request('POST', '/' . $this->id . '/reveal')->assertForbidden()->assertDontSee('fixture-secret');
        $this->grant(Op::Reveal);
        $response = $this->request('POST', '/' . $this->id . '/reveal')->assertOk()->assertJsonPath('value', 'fixture-secret');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(1, SecretAuditEvent::where('operation', 'secret.reveal')->count());
        $this->assertStringNotContainsString('fixture-secret', SecretAuditEvent::all()->toJson());
    }

    public function test_blank_preserves_and_explicit_empty_replaces(): void
    {
        $this->grant(Op::Update, Op::Reveal);
        $this->request('PATCH', '/' . $this->id, ['value' => ''])->assertOk()->assertDontSee('fixture-secret');
        $this->assertSame(1, app(SecretStore::class)->metadata($this->id)->currentVersion);
        $this->request('POST', '/' . $this->id . '/empty')->assertOk();
        $this->request('POST', '/' . $this->id . '/reveal')->assertJsonPath('value', '');
    }

    public function test_grant_form_cannot_delegate_unheld_reveal(): void
    {
        $this->grant(Op::ManageAccess);
        $this->request('POST', '/' . $this->id . '/grants', ['user_id' => $this->actor->id, 'operations' => ['reveal']])->assertForbidden();
        $this->request('POST', '/' . $this->id . '/reveal')->assertForbidden();
    }

    public function test_cross_server_substitution_is_denied(): void
    {
        $this->grant(Op::ListSecrets, Op::Reveal);
        $other = Server::factory()->create();
        $id = app(SecretStore::class)->create($other, 'OTHER', '', new SecretValue('other-secret'), $this->actor)->secretId;
        $this->request('POST', '/' . $id . '/reveal')->assertForbidden()->assertDontSee('other-secret');
    }

    public function test_create_generate_description_and_delete_return_only_receipts(): void
    {
        $this->grant(Op::Create, Op::Update, Op::Delete, Op::Reveal);
        $created = $this->request('POST', '', ['name' => 'API_KEY', 'description' => 'Initial', 'mode' => 'generate'])
            ->assertCreated()->assertJsonMissingPath('value');
        $id = $created->json('data.id');
        $first = $this->request('POST', '/' . $id . '/reveal')->assertOk()->json('value');
        $this->assertSame(43, strlen($first));
        $this->assertStringNotContainsString($first, $created->getContent());
        $this->request('PATCH', '/' . $id, ['description' => 'New description'])->assertOk()->assertJsonPath('data.description', 'New description');
        $this->request('POST', '/' . $id . '/generate')->assertOk()->assertDontSee($first);
        $second = $this->request('POST', '/' . $id . '/reveal')->assertOk()->json('value');
        $this->assertNotSame($first, $second);
        $this->request('DELETE', '/' . $id)->assertOk()->assertJsonPath('deleted', true);
        $this->request('POST', '/' . $id . '/reveal')->assertForbidden();
    }

    public function test_invalid_input_is_sanitized_and_space_bytes_are_preserved(): void
    {
        $this->grant(Op::Update, Op::Reveal);
        $this->request('PATCH', '/' . $this->id, ['value' => '  new-value  '])->assertOk()->assertDontSee('new-value');
        $this->request('POST', '/' . $this->id . '/reveal')->assertJsonPath('value', '  new-value  ');
        $this->request('PATCH', '/' . $this->id, ['value' => null])->assertUnprocessable()->assertDontSee('new-value');
        $this->request('PATCH', '/' . $this->id, ['value' => "do-not-log\0invalid"])->assertUnprocessable()->assertDontSee('do-not-log');
        $this->request('PATCH', '/' . $this->id, ['value' => 'input', 'unknown' => 'do-not-log'])->assertUnprocessable()->assertDontSee('do-not-log');
    }

    public function test_real_native_client_keys_obey_grants_key_type_and_ip_restrictions(): void
    {
        $key = ApiKey::factory()->create(['user_id' => $this->actor->id,
            'key_type' => ApiKey::TYPE_ACCOUNT,
            'identifier' => ApiKey::generateTokenIdentifier(ApiKey::TYPE_ACCOUNT)]);
        $this->request('GET', key: $key)->assertForbidden();
        $this->grant(Op::ListSecrets);
        $this->request('GET', key: $key)->assertOk()->assertDontSee('fixture-secret');
        $this->request('POST', '/' . $this->id . '/reveal', key: $key)->assertForbidden();
        $key->update(['allowed_ips' => ['192.0.2.100/32']]);
        $this->request('GET', key: $key->fresh())->assertForbidden();
        $key->update(['allowed_ips' => [], 'key_type' => ApiKey::TYPE_APPLICATION]);
        $this->request('GET', key: $key->fresh())->assertForbidden();
    }

    public function test_unauthenticated_client_request_is_rejected(): void
    {
        $this->request('GET', authenticated: false)->assertUnauthorized();
    }
}
