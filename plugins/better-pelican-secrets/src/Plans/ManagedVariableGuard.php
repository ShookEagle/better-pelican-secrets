<?php

namespace ShookEagle\BetterPelicanSecrets\Plans;

use App\Models\EggVariable;
use App\Models\Server;
use App\Models\ServerVariable;
use DomainException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Plans\Models\StartupBinding;

final class ManagedVariableGuard
{
    private ?string $clearing = null;

    public function assertWritable(Server $server, string $env): void
    {
        if (StartupBinding::query()->where('server_id', $server->id)->where('env', $env)->exists()) {
            throw new DomainException('startup_variable_managed');
        }
    }

    public function assertModelWritable(ServerVariable $variable, bool $deleting = false): void
    {
        $identities = [[$variable->server_id, $variable->variable_id]];
        if ($variable->exists) {
            $identities[] = [$variable->getRawOriginal('server_id'), $variable->getRawOriginal('variable_id')];
        }
        foreach ($identities as [$serverId, $variableId]) {
            $env = EggVariable::query()->whereKey($variableId)->value('env_variable');
            if (!$env) {
                continue;
            }
            if (!$deleting && $variable->variable_value === '' && $this->clearing === $serverId . ':' . $env && DB::transactionLevel() > 0) {
                continue;
            }
            $server = Server::query()->find($serverId);
            if ($server) {
                $this->assertWritable($server, $env);
            }
        }
    }

    /** @internal Explicit migration clears only; never writes a confidential value. */
    public function clearForMigration(Server $server, string $env): void
    {
        if (DB::transactionLevel() < 1 || $this->clearing !== null) {
            throw new DomainException('migration_transaction_required');
        }
        $variable = EggVariable::query()->where('egg_id', $server->egg_id)->where('env_variable', $env)->firstOrFail();
        $this->clearing = $server->id . ':' . $env;
        try {
            ServerVariable::query()->updateOrCreate(['server_id' => $server->id, 'variable_id' => $variable->id], ['variable_value' => '']);
        } finally {
            $this->clearing = null;
        }
    }
}
