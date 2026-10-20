<?php

namespace ShookEagle\BetterPelicanSecrets\Providers;

use App\Enums\TablerIcon;
use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Components\Forms\Fields\StartupVariable;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Models\ServerVariable;
use App\Models\Subuser;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use App\Services\Servers\GetUserPermissionsService;
use App\Services\Servers\ServerConfigurationStructureService;
use App\Services\Servers\StartupModificationService;
use App\Services\Subusers\SubuserCreationService;
use App\Services\Subusers\SubuserUpdateService;
use Filament\Schemas\Schema;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use ShookEagle\BetterPelicanSecrets\Http\SecretResponse;
use ShookEagle\BetterPelicanSecrets\Node\CompatibleDaemonServerRepository;
use ShookEagle\BetterPelicanSecrets\Node\NodeCapabilityService;
use ShookEagle\BetterPelicanSecrets\Node\ReferenceConfigurationService;
use ShookEagle\BetterPelicanSecrets\Plans\ManagedStartupModificationService;
use ShookEagle\BetterPelicanSecrets\Plans\ManagedVariableGuard;
use ShookEagle\BetterPelicanSecrets\Secrets\NativeGrantGuard;
use ShookEagle\BetterPelicanSecrets\Secrets\NativeRoleApiGuard;
use ShookEagle\BetterPelicanSecrets\Secrets\NativeSubuserCreationService;
use ShookEagle\BetterPelicanSecrets\Secrets\NativeSubuserUpdateService;
use ShookEagle\BetterPelicanSecrets\Secrets\NativeUserPermissionsService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateCompiler;

final class BetterPelicanSecretsProvider extends ServiceProvider
{
    private static bool $formsRegistered = false;

    public function register(): void
    {
        $config = dirname(__DIR__, 2) . '/config/secrets.php';
        $this->mergeConfigFrom($config, 'better-pelican-secrets');
        $this->publishes([$config => config_path('better-pelican-secrets.php')], 'better-pelican-secrets-config');
        $this->app->bind(TemplateCompiler::class,
            fn () => new TemplateCompiler(config('better-pelican-secrets.limits', [])));
        $this->app->bind(DaemonServerRepository::class, CompatibleDaemonServerRepository::class);
        $this->app->bind(ServerConfigurationStructureService::class, ReferenceConfigurationService::class);

        $this->app->singleton(ManagedVariableGuard::class);
        $this->app->bind(StartupModificationService::class, ManagedStartupModificationService::class);
        $this->app->bind(SubuserCreationService::class, NativeSubuserCreationService::class);
        $this->app->bind(SubuserUpdateService::class, NativeSubuserUpdateService::class);
        $this->app->bind(GetUserPermissionsService::class, NativeUserPermissionsService::class);
    }

    public function boot(): void
    {
        Server::updating(fn (Server $server) => app(NodeCapabilityService::class)->guardServerMove($server));
        ServerTransfer::creating(fn (ServerTransfer $transfer) => app(NodeCapabilityService::class)->guardTransfer($transfer));
        $this->loadRoutesFrom(dirname(__DIR__, 2) . '/routes/node.php');
        $this->loadRoutesFrom(dirname(__DIR__, 2) . '/routes/client.php');
        $this->loadViewsFrom(dirname(__DIR__, 2) . '/resources/views', 'better-pelican-secrets');
        $this->loadTranslationsFrom(dirname(__DIR__, 2) . '/lang', 'better-pelican-secrets');
        Livewire::addPersistentMiddleware([SecretResponse::class]);

        ServerVariable::saving(fn (ServerVariable $variable) => app(ManagedVariableGuard::class)->assertModelWritable($variable));
        ServerVariable::deleting(fn (ServerVariable $variable) => app(ManagedVariableGuard::class)->assertModelWritable($variable, true));
        $operations = array_column(SecretOperation::cases(), 'value');
        Subuser::registerCustomPermissions('secrets', $operations, icon: TablerIcon::Key, hidden: false);
        Role::registerCustomPermissions(['secrets' => $operations]);
        Subuser::saving(fn (Subuser $subuser) => app(NativeGrantGuard::class)->savingSubuser($subuser));
        $this->app['router']->pushMiddlewareToGroup('application-api', NativeRoleApiGuard::class);
        StartupVariable::configureUsing(
            fn ($field) => $field->view('better-pelican-secrets::filament.shared.startup-variable'));
        if (self::$formsRegistered) {
            return;
        }
        self::$formsRegistered = true;
        RoleResource::modifyForm(function (Schema $schema): Schema {
            foreach ($schema->getFlatFields(withHidden: true) as $field) {
                if ($field->getName() === 'secrets_list') {
                    $field->beforeStateDehydrated(function (?array $state, ?Role $record) {
                        app(NativeGrantGuard::class)->authorizeRolePermissions(auth()->user(), $record, $state ?? []);
                    });
                }
            }

            return $schema;
        });
        UserResource::modifyForm(function (Schema $schema): Schema {
            foreach ($schema->getFlatFields(withHidden: true) as $field) {
                if ($field->getName() === 'roles') {
                    $field->beforeStateDehydrated(function (?array $state, ?User $record) {
                        app(NativeGrantGuard::class)->authorizeRoleAssignment(auth()->user(), $record?->roles()->pluck('roles.id')->all() ?? [], $state ?? []);
                    });
                    $field->saveRelationshipsUsing(function (User $record, array $state) {
                        app(NativeGrantGuard::class)->authorizeRoleAssignment(auth()->user(), $record->roles()->pluck('roles.id')->all(), $state);
                        $record->syncRoles(collect($state)->map(fn ($id) => Role::findById($id)));
                    });
                }
            }

            return $schema;
        });
    }
}
