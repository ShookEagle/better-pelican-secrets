<?php

namespace ShookEagle\BetterPelicanSecrets;

use Filament\Contracts\Plugin;
use Filament\Panel;
use ShookEagle\BetterPelicanSecrets\Filament\Server\Pages\Secrets;

final class BetterPelicanSecretsPlugin implements Plugin
{
    public function getId(): string
    {
        return 'better-pelican-secrets';
    }

    public function register(Panel $panel): void
    {
        $page = match ($panel->getId()) {
            'server' => Secrets::class,
            'admin' => Filament\Admin\Pages\Secrets::class,
            default => null,
        };
        if ($page) {
            $panel->pages([$page]);
        }
    }

    public function boot(Panel $panel): void {}
}
