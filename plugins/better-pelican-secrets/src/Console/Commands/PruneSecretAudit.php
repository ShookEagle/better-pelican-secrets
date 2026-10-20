<?php

namespace ShookEagle\BetterPelicanSecrets\Console\Commands;

use Illuminate\Console\Command;
use ShookEagle\BetterPelicanSecrets\Secrets\VersionRetentionService;
use Throwable;

final class PruneSecretAudit extends Command
{
    protected $signature = 'secrets:prune-audit';

    protected $description = 'Apply the trusted host audit retention policy (90 days by default).';

    public function handle(): int
    {
        try {
            $count = app(VersionRetentionService::class)->pruneAudit();
            $this->info('Pruned audit events: ' . $count . '.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Audit pruning failed.');

            return self::FAILURE;
        }
    }
}
