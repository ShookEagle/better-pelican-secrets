<?php

namespace ShookEagle\BetterPelicanSecrets\Filament\Shared;

use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Node\DesiredPlan;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\ManagedVariableGuard;
use ShookEagle\BetterPelicanSecrets\Plans\Models\StartupBinding;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation as Op;

final class StartupBindingActions
{
    public function variables(User $actor, Server $server): array
    {
        app(SecretAuthorizer::class)->assertMember($actor, $server->id);
        if (!$actor->can('startup.read', $server) || !$actor->can('startup.update', $server)) {
            throw new AuthorizationException('Startup access denied.');
        }

        return EggVariable::where('egg_id', $server->egg_id)->orderBy('sort')->get()->map(function ($variable) use ($actor, $server) {
            $row = ServerVariable::where('server_id', $server->id)->where('variable_id', $variable->id)->first();
            $value = $row ? $row->variable_value : $variable->default_value;

            $binding = StartupBinding::where('server_id', $server->id)->where('env', $variable->env_variable)->first();

            return ['env' => $variable->env_variable, 'name' => $variable->name,
                'bound' => $binding !== null,
                'can_bind' => !$binding || app(SecretActions::class)->can($actor, Op::Bind, $server, $binding->secret_id),
                'migration_required' => $value !== '', 'digest' => hash('sha256', $value)];
        })->all();
    }

    public function bind(User $actor, Server $server, string $env, string $secretId): DesiredPlan
    {
        return app(BindingService::class)->bindStartup($actor, $server, $env, $secretId);
    }

    public function migrate(User $actor, Server $server, string $env, string $secretId, string $expectedDigest, bool $confirmed): DesiredPlan
    {
        return DB::transaction(function () use ($actor, $server, $env, $secretId, $expectedDigest, $confirmed) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            app(SecretActions::class)->authorize($actor, Op::Bind, $server, $secretId);
            if (!$confirmed || !$actor->can('startup.read', $server) || !$actor->can('startup.update', $server)) {
                throw new DomainException('migration_confirmation_required');
            }
            $variable = EggVariable::where('egg_id', $server->egg_id)->where('env_variable', $env)->first() ?? throw new DomainException('startup_variable_unknown');
            $row = ServerVariable::where('server_id', $server->id)->where('variable_id', $variable->id)->lockForUpdate()->first();
            $value = $row ? $row->variable_value : $variable->default_value;
            if (!hash_equals(hash('sha256', $value), $expectedDigest)) {
                throw new DomainException('source_conflict');
            }
            app(ManagedVariableGuard::class)->clearForMigration($server, $env);

            return $this->bind($actor, $server, $env, $secretId);
        }, 3);
    }

    public function unbind(User $actor, Server $server, string $env): DesiredPlan
    {
        return app(BindingService::class)->unbindStartup($actor, $server, $env);
    }

    public function indicator(ServerVariable $variable): ?string
    {
        $env = $variable->variable?->env_variable;
        $binding = StartupBinding::where('server_id', $variable->server_id)->where('env', $env)->first();
        if (!$binding) {
            return null;
        }
        $name = Secret::where('server_id', $variable->server_id)->whereKey($binding->secret_id)->value('name');

        return 'Bound to secret: ' . ($name ?? 'Missing secret');
    }
}
