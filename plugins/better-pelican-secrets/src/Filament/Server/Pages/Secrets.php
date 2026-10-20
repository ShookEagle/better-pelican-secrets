<?php

namespace ShookEagle\BetterPelicanSecrets\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Models\Server;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\ManagesSecrets;
use ShookEagle\BetterPelicanSecrets\Filament\Shared\SecretActions;
use ShookEagle\BetterPelicanSecrets\Http\SecretResponse;

final class Secrets extends Page
{
    use ManagesSecrets;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Key;

    protected static string|array $routeMiddleware = [SecretResponse::class];

    protected string $view = 'better-pelican-secrets::filament.server.secrets';

    public static function canAccess(): bool
    {
        $actor = auth()->user();
        $server = Filament::getTenant();

        return $actor && $server instanceof Server && app(SecretActions::class)->accessible($actor, $server);
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    protected function adminContext(): bool
    {
        return false;
    }

    protected function secretServer(): Server
    {
        $server = Filament::getTenant();
        abort_unless($server instanceof Server, 403);

        return Server::findOrFail($server->id);
    }

    protected function getViewData(): array
    {
        return ['server' => $this->secretServer(), ...$this->managementData($this->secretServer())];
    }
}
