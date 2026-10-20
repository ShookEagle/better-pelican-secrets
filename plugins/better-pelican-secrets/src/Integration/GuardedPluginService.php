<?php

namespace ShookEagle\BetterPelicanSecrets\Integration;

use App\Models\Plugin;
use App\Services\Helpers\PluginService;
use Illuminate\Auth\Access\AuthorizationException;
use ShookEagle\BetterPelicanSecrets\Plans\ServerLifecycleService;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;

class GuardedPluginService extends PluginService
{
    private function guard(string|Plugin $plugin): void
    {
        if (($plugin instanceof Plugin ? $plugin->id : $plugin) === 'better-pelican-secrets') {
            app(ServerLifecycleService::class)->assertIntegrationCanDisable();
            // Native web/API permission to manage plugins does not imply secret authority.
            $actor = auth()->user();
            if ($actor && !app(SecretAuthorizer::class)->isRoot($actor)) {
                throw new AuthorizationException('Secret access denied.');
            }
        }
    }

    public function disablePlugin(string|Plugin $plugin): void
    {
        $this->guard($plugin);
        parent::disablePlugin($plugin);
    }

    public function uninstallPlugin(Plugin $plugin, bool $deleteFiles = false): void
    {
        $this->guard($plugin);
        parent::uninstallPlugin($plugin, $deleteFiles);
    }

    public function deletePlugin(Plugin $plugin): void
    {
        $this->guard($plugin);
        parent::deletePlugin($plugin);
    }

    public function rollbackPluginMigrations(Plugin $plugin): void
    {
        $this->guard($plugin);
        parent::rollbackPluginMigrations($plugin);
    }
}
