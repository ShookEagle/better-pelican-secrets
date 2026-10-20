<?php

namespace ShookEagle\BetterPelicanSecrets\Filament\Shared;

use App\Models\Server;
use App\Models\User;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;

trait ManagesSecrets
{
    public string $feedback = '';

    abstract protected function secretServer(): Server;

    abstract protected function adminContext(): bool;

    protected function secretActor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);
        if ($this->adminContext()) {
            abort_unless($actor->canAccessPanel(Filament::getPanel('admin')), 403);
        }

        return $actor;
    }

    protected function perform(\Closure $action, ?string $success = 'saved'): void
    {
        $this->feedback = '';
        try {
            $action($this->secretActor(), $this->secretServer(), $this->adminContext());
            $this->feedback = $success === null ? '' : __('better-pelican-secrets::secrets.' . $success);
        } catch (AuthorizationException) {
            abort(403);
        } catch (DomainException) {
            $this->feedback = __('better-pelican-secrets::secrets.failed');
        }
    }

    public function createSecret(string $name, string $description, #[\SensitiveParameter] string $value, string $mode): void
    {
        $this->perform(fn ($actor, $server, $admin) => app(SecretActions::class)->create($actor, $server, $name, $description, $value, $mode, $admin));
    }

    public function replaceValue(string $id, #[\SensitiveParameter] string $value): void
    {
        $this->perform(fn ($actor, $server, $admin) => app(SecretActions::class)->replace($actor, $server, $id, $value, $admin));
    }

    public function saveDescription(string $id, string $description): void
    {
        $this->perform(fn ($actor, $server, $admin) => app(SecretActions::class)->description($actor, $server, $id, $description, $admin));
    }

    public function setEmptyValue(string $id): void
    {
        $this->perform(fn ($actor, $server, $admin) => app(SecretActions::class)->setEmpty($actor, $server, $id, $admin));
    }

    public function generateValue(string $id): void
    {
        $this->perform(fn ($actor, $server, $admin) => app(SecretActions::class)->generate($actor, $server, $id, $admin));
    }

    public function deleteSecret(string $id): void
    {
        $this->perform(fn ($actor, $server, $admin) => app(SecretActions::class)->delete($actor, $server, $id, $admin));
    }

    public function revealValue(string $id): void
    {
        $this->perform(function ($actor, $server, $admin) use ($id) {
            $value = app(SecretActions::class)->reveal($actor, $server, $id, $admin);
            // Browser event only: no public property, flash, notification, session, or model state.
            $this->dispatch('bps-reveal', value: $value->bytes());
        }, 'revealed');
    }

    public function accessDetails(?string $id): void
    {
        $this->perform(function ($actor, $server, $admin) use ($id) {
            $details = app(GrantActions::class)->listing($actor, $server, $id, $admin);
            $this->dispatch('bps-access', scope: $id, details: $details);
        }, null);
    }

    public function changeAccess(?string $id, int $userId, array $operations, bool $revoke = false): void
    {
        $this->perform(fn ($actor, $server, $admin) => app(GrantActions::class)->change($actor, $server, $id, $userId, $operations, $revoke, $admin));
        // Fresh metadata is independently reauthorized after every mutation.
        $this->accessDetails($id);
    }

    protected function managementData(Server $server): array
    {
        try {
            return app(SecretActions::class)->overview($this->secretActor(), $server, $this->adminContext());
        } catch (AuthorizationException) {
            abort(403);
        }
    }
}
