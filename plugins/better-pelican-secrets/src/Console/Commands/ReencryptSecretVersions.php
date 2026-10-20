<?php

namespace ShookEagle\BetterPelicanSecrets\Console\Commands;

use ShookEagle\BetterPelicanSecrets\Console\MaintenanceCommand;

final class ReencryptSecretVersions extends MaintenanceCommand
{
    protected $signature = 'secrets:reencrypt {actor : Root administrator ID} {server : Server ID}';

    protected $description = 'Re-encrypt immutable versions using the active Laravel key and configured previous keys.';

    public function handle(): int
    {
        return $this->maintain('reencrypt', 'Re-encrypted versions');
    }
}
