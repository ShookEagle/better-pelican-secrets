<?php

use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use ShookEagle\BetterPelicanSecrets\Providers\BetterPelicanSecretsProvider;

// A real application boot outside APP_ENV=testing: upstream must discover us.
require dirname(__DIR__, 2) . '/.work/panel/vendor/autoload.php';
$app = require dirname(__DIR__, 2) . '/.work/panel/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->runningUnitTests()) {
    throw new RuntimeException('The discovery probe must use the real application loader.');
}
$provider = BetterPelicanSecretsProvider::class;
$loaded = $app->providerIsLoaded($provider);

$plugins = [];
foreach ($loaded ? ['admin', 'server'] : [] as $panelId) {
    $plugins[$panelId] = Filament::getPanel($panelId)
        ->getPlugin('better-pelican-secrets')->getId();
}
echo json_encode(['panel_version' => config('app.version'), 'environment' => $app->environment(), 'provider_loaded' => $loaded, 'panels' => $plugins], JSON_THROW_ON_ERROR), PHP_EOL;
exit($loaded ? 0 : 1);
