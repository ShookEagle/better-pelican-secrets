<?php

namespace ShookEagle\BetterPelicanSecrets;

use Filament\Contracts\Plugin;
use Filament\Panel;

final class BetterPelicanSecretsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'better-pelican-secrets';
    }

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
