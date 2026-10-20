<?php

namespace ShookEagle\BetterPelicanSecrets\Console;

use App\Models\User;
use DomainException;
use Illuminate\Console\Command;
use ShookEagle\BetterPelicanSecrets\Secrets\VersionRetentionService;
use Throwable;

abstract class MaintenanceCommand extends Command
{
    protected function maintain(string $operation, string $label): int
    {
        try {
            $actorId = (string) $this->argument('actor');
            $serverId = (string) $this->argument('server');
            if (!ctype_digit($actorId) || !ctype_digit($serverId)) {
                throw new DomainException('Invalid maintenance scope.');
            }
            $actor = User::find((int) $actorId) ?? throw new DomainException('Invalid maintenance actor.');
            $count = app(VersionRetentionService::class)->$operation($actor, (int) $serverId);
            $this->info($label . ': ' . $count . '.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Secret maintenance failed.');

            return self::FAILURE;
        }
    }
}
