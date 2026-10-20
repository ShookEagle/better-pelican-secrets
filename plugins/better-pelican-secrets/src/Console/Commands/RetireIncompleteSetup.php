<?php

namespace ShookEagle\BetterPelicanSecrets\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use ShookEagle\BetterPelicanSecrets\Plans\ServerLifecycleService;

final class RetireIncompleteSetup extends Command
{
    protected $signature = 'secrets:retire-setup {actor} {server} {failure} {digest} {--confirm}';

    protected $description = 'Retire an abandoned setup after verifying its ordinary source SHA-256 (or absent).';

    public function handle(): int
    {
        try {
            if (!$this->option('confirm') || !ctype_digit((string) $this->argument('actor')) || !ctype_digit((string) $this->argument('server'))) {
                throw new \DomainException('Confirmation required.');
            }
            $actor = User::findOrFail((int) $this->argument('actor'));
            app(ServerLifecycleService::class)->retireIncompleteSetup($actor, (int) $this->argument('server'), (string) $this->argument('failure'), (string) $this->argument('digest'));
            $this->info('Incomplete setup retired.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Setup retirement failed.');

            return self::FAILURE;
        }
    }
}
