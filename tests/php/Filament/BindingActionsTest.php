<?php

require_once dirname(__DIR__) . '/Support/PluginTestCase.php';

use App\Filament\Server\Pages\Startup;
use App\Models\EggVariable;
use App\Models\Role;
use App\Models\ServerVariable;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\View\Component;
use Livewire\Livewire;
use ShookEagle\BetterPelicanSecrets\Filament\Server\Pages\Secrets;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\FileTemplateActions;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\StartupBindingActions;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Secrets\GrantService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation as Op;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;

final class BindingActionsTest extends PluginTestCase
{
    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        Component::flushCache();
        Component::forgetFactory();
        $this->id = app(SecretStore::class)->create($this->server, 'API_KEY', '', new SecretValue('fixture-secret'), $this->actor)->secretId;
        auth()->login($this->actor);
        Filament::setCurrentPanel(Filament::getPanel('server'));
        Filament::setTenant($this->server, true);
    }

    private function root(): User
    {
        $user = User::factory()->create();
        $user->assignRole(Role::getRootAdmin());

        return $user;
    }

    public function test_picker_offers_only_bindable_secrets(): void
    {
        $other = app(SecretStore::class)->create($this->server, 'OTHER', '', new SecretValue('other-fixture'), $this->actor);
        $root = $this->root();
        app(GrantService::class)->grant($root, $this->actor, new SecretScope($this->server->id), [Op::ListSecrets]);
        app(GrantService::class)->grant($root, $this->actor, new SecretScope($this->server->id, $this->id), [Op::Bind]);
        $picker = app(FileTemplateActions::class)->picker($this->actor, $this->server);
        $this->assertSame([$this->id], array_column($picker, 'id'));
        $this->assertStringNotContainsString('fixture-secret', json_encode($picker));
        $this->expectException(AuthorizationException::class);
        app(FileTemplateActions::class)->insert($this->actor, $this->server, '{"key":"old"}', 8, 11, $other->secretId, 'json-string', 'json');
    }

    public function test_format_suggestion_requires_confirmation(): void
    {
        $root = $this->root();
        $actions = app(FileTemplateActions::class);
        $this->assertSame(['format' => 'json', 'mode' => 'json-string'], $actions->suggest('configs/plugin.json'));
        $file = new FilePlan('33333333-3333-4333-8333-333333333333', 'configs/plugin.json', '/game/configs/plugin.json', 'json', 'json-string',
            [['secret_id' => $this->id, 'name' => 'API_KEY', 'mode' => 'json-string', 'count' => 1]]);
        $this->expectException(DomainException::class);
        $actions->prepare($root, $this->server, $file, '{"key":{{secret:API_KEY}}}', false);
    }

    public function test_json_yaml_dotenv_and_text_setup(): void
    {
        $root = $this->root();
        $actions = app(FileTemplateActions::class);
        foreach ([
            ['json', 'json-string', '{"key":{{secret:API_KEY}}}', '{"key":"DUMMY_VALUE"}'],
            ['yaml', 'yaml-string', 'key: {{secret:API_KEY}}', 'key: "DUMMY_VALUE"'],
            ['dotenv', 'dotenv', 'KEY={{secret:API_KEY}}', 'KEY="DUMMY_VALUE"'],
            ['text', 'raw', 'password {{secret:API_KEY}}', 'password DUMMY_VALUE'],
        ] as [$format, $mode, $source, $expected]) {
            $manifest = $actions->manifest($root, $this->server, $source, $mode);
            $file = new FilePlan('33333333-3333-4333-8333-333333333333', 'config.txt', '/game/config.txt', $format, $mode, $manifest);
            $prepared = $actions->prepare($root, $this->server, $file, $source, true);
            $this->assertSame($expected, $prepared['preview']);
            $this->assertStringNotContainsString('fixture-secret', $prepared['preview']);
            $this->assertSame($this->id, $prepared['file']->manifest[0]['secret_id']);
        }
    }

    public function test_json_insertion_replaces_whole_string_value_and_rejects_keys(): void
    {
        $root = $this->root();
        $actions = app(FileTemplateActions::class);
        $source = '{"key":"old value","other":1}';
        $this->assertSame('{"key":{{secret:API_KEY|json-string}},"other":1}',
            $actions->insert($root, $this->server, $source, 8, 17, $this->id, 'json-string', 'json'));
        $this->expectException(DomainException::class);
        $actions->insert($root, $this->server, $source, 2, 4, $this->id, 'json-string', 'json');
    }

    public function test_startup_migration_requires_confirmation_and_preserves_no_legacy_value(): void
    {
        $this->actor->assignRole(Role::getRootAdmin());
        EggVariable::factory()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'RCON_PASSWORD',
            'default_value' => 'legacy-value', 'rules' => ['nullable', 'string']]);
        $actions = app(StartupBindingActions::class);
        $variables = $actions->variables($this->actor, $this->server);
        $this->assertStringNotContainsString('legacy-value', json_encode($variables));
        foreach ([[false, hash('sha256', 'legacy-value')], [true, hash('sha256', 'outdated')]] as [$confirmed, $digest]) {
            try {
                $actions->migrate($this->actor, $this->server, 'RCON_PASSWORD', $this->id, $digest, $confirmed);
                $this->fail('Unconfirmed/stale startup migration succeeded');
            } catch (DomainException) {
                $this->assertSame(0, ServerVariable::where('server_id', $this->server->id)->count());
            }
        }
        $actions->migrate($this->actor, $this->server, 'RCON_PASSWORD', $this->id, $variables[0]['digest'], true);
        $this->assertSame('', ServerVariable::where('server_id', $this->server->id)->value('variable_value'));
    }

    public function test_livewire_preview_is_dummy_only_and_requires_format_confirmation(): void
    {
        $this->actor->assignRole(Role::getRootAdmin());
        $draft = ['id' => '33333333-3333-4333-8333-333333333333', 'source' => 'config.json',
            'destination' => '/home/container/config.json', 'format' => 'json', 'mode' => 'json-string',
            'content' => '{"key":{{secret:API_KEY}}}', 'format_confirmed' => true];
        $component = Livewire::test(Secrets::class);
        $component->call('previewTemplate', $draft)->assertDispatched('bps-template-preview',
            preview: '{"key":"DUMMY_VALUE"}')->assertDontSee('fixture-secret');
        $draft['format_confirmed'] = false;
        $component->call('previewTemplate', $draft)->assertNotDispatched('bps-template-preview');
        Http::assertNotSent(fn ($request) => str_ends_with(parse_url($request->url(), PHP_URL_PATH), '/files/write'));
    }

    public function test_oversized_open_path_is_rejected_before_node_request(): void
    {
        Http::fake(fn () => Http::response('ordinary-source', 200,
            ['User-Agent' => 'Pelican Wings/v1.0.0 (id:' . $this->server->node->daemon_token_id . ')']));
        try {
            app(FileTemplateActions::class)->open($this->root(), $this->server, str_repeat('a', 4097));
            $this->fail('Oversized source path was accepted');
        } catch (DomainException) {
            Http::assertNothingSent();
        }
    }

    public function test_bound_startup_form_cannot_write_plain_value(): void
    {
        $root = $this->root();
        $this->actor->assignRole(Role::getRootAdmin());
        $variable = EggVariable::factory()->create(['egg_id' => $this->server->egg_id, 'env_variable' => 'RCON_PASSWORD',
            'default_value' => '', 'user_viewable' => true, 'user_editable' => true, 'rules' => ['nullable', 'string']]);
        $row = ServerVariable::create(['server_id' => $this->server->id, 'variable_id' => $variable->id, 'variable_value' => '']);
        app(BindingService::class)->bindStartup($this->actor, $this->server, 'RCON_PASSWORD', $this->id);
        $this->assertSame('Bound to secret: API_KEY', app(StartupBindingActions::class)->indicator($row));
        Livewire::test(Startup::class)->assertSee('Bound to secret: API_KEY')->assertDontSee('fixture-secret');
        app(Startup::class)->update('do-not-store-plain', $row);
        $this->assertSame('', $row->fresh()->variable_value);
        $this->assertStringNotContainsString('fixture-secret', json_encode(app(StartupBindingActions::class)->variables($this->actor, $this->server)));
    }
}
