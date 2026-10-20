<?php

namespace ShookEagle\BetterPelicanSecrets\Console\Commands;

use ShookEagle\BetterPelicanSecrets\Console\MaintenanceCommand;

final class PurgeSecretVersions extends MaintenanceCommand
{
    protected $signature = 'secrets:purge-versions {actor : Root administrator ID} {server : Server ID}';

    protected $description = 'Purge secret versions unused by current or outstanding runtime references.';

    public function handle(): int
    {
        return $this->maintain('purgeUnreferenced', 'Purged versions');
    }
}
