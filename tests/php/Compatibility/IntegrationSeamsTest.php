<?php

use App\Extensions\Features\FeatureService;
use App\Models\Plugin;
use App\Models\Server;
use App\Services\Helpers\PluginService;
use App\Services\Servers\EnvironmentService;
use App\Services\Servers\ServerConfigurationStructureService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use ShookEagle\BetterPelicanSecrets\BetterPelicanSecretsPlugin;
use ShookEagle\BetterPelicanSecrets\Providers\BetterPelicanSecretsProvider;

final class IntegrationSeamsTest extends TestCase
{
    private bool $booted = false;

    private function bootApplication(): Application
    {
        $this->booted = true;
        $app = require dirname(__DIR__, 3) . '/.work/panel/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function tearDown(): void
    {
        if ($this->booted) {
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    public function test_plugin_class_is_discoverable(): void
    {
        $this->assertTrue(class_exists(BetterPelicanSecretsPlugin::class));
        $this->assertSame('better-pelican-secrets', (new BetterPelicanSecretsPlugin())->getId());
    }

    public function test_provider_registers_without_native_test_loader(): void
    {
        $this->assertTrue(class_exists(BetterPelicanSecretsProvider::class));
        $app = $this->bootApplication();

        $app->register(BetterPelicanSecretsProvider::class);
        $this->assertTrue($app->providerIsLoaded(BetterPelicanSecretsProvider::class));
        $app->flush();
    }

    public function test_lifecycle_service_can_veto_before_native_mutation(): void
    {
        $app = $this->bootApplication();

        $app->bind(PluginService::class, fn ($app) => new class($app) extends PluginService
        {
            public function disablePlugin(string|Plugin $plugin): void
            {
                throw new DomainException('guard-probe');
            }

            public function uninstallPlugin(Plugin $plugin, bool $deleteFiles = false): void
            {
                throw new DomainException('guard-probe');
            }
        });
        foreach (['disablePlugin', 'uninstallPlugin'] as $operation) {
            try {
                $app->make(PluginService::class)->{$operation}(new Plugin());
                $this->fail('Native destructive operation escaped the lifecycle guard.');
            } catch (DomainException $exception) {
                $this->assertSame('guard-probe', $exception->getMessage());
            }
        }
        $app->flush();
    }

    public function test_configuration_service_supports_decorator_binding(): void
    {
        $app = $this->bootApplication();

        $app->bind(ServerConfigurationStructureService::class, function ($app) {
            return new class($app->make(EnvironmentService::class), $app->make(FeatureService::class)) extends ServerConfigurationStructureService
            {
                public function handle(Server $server, array $override = []): array
                {
                    return ['secrets' => ['protocol' => 1]];
                }
            };
        });
        $result = $app->make(ServerConfigurationStructureService::class)->handle(new Server());
        $this->assertSame(['protocol' => 1], $result['secrets']);
        $app->flush();
    }
}
