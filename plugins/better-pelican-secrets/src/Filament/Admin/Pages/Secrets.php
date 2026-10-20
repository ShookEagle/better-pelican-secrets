<?php

namespace ShookEagle\BetterPelicanSecrets\Filament\Admin\Pages;

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

    public ?int $selectedServer = null;

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Key;

    protected static string|array $routeMiddleware = [SecretResponse::class];

    protected string $view = 'better-pelican-secrets::filament.admin.secrets';

    public static function canAccess(): bool
    {
        $actor = auth()->user();

        return $actor && $actor->canAccessPanel(Filament::getPanel('admin'))
            && app(SecretActions::class)->servers($actor, true) !== [];
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    protected function adminContext(): bool
    {
        return true;
    }

    protected function secretServer(): Server
    {
        $server = Server::find($this->selectedServer);
        abort_unless($server && app(SecretActions::class)->accessible($this->secretActor(), $server, true), 403);

        return $server;
    }

    protected function getViewData(): array
    {
        $actor = $this->secretActor();
        $servers = app(SecretActions::class)->servers($actor, true);
        if ($this->selectedServer !== null) {
            return ['servers' => $servers, 'server' => $this->secretServer(), ...$this->managementData($this->secretServer())];
        }

        return ['servers' => $servers, 'server' => null, 'rows' => app(SecretActions::class)->adminListing($actor)];
    }
}
