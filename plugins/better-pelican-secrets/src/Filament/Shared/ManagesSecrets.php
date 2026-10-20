<?php

namespace ShookEagle\BetterPelicanSecrets\Filament\Shared;

use App\Models\Server;
use App\Models\User;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;

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
        } catch (DomainException $error) {
            $key = match ($error->getMessage()) {
                'source_saved_plan_failed' => 'source_uncertain',
                'source_conflict' => 'source_conflict',
                'format_confirmation_required', 'migration_confirmation_required' => 'confirmation_required',
                'node_incompatible' => 'node_incompatible',
                default => 'failed',
            };
            $this->feedback = __('better-pelican-secrets::secrets.' . $key);
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

    public function browseTemplates(string $directory = ''): void
    {
        $this->perform(function ($actor, $server) use ($directory) {
            $this->dispatch('bps-template-directory', listing: app(FileTemplateActions::class)->browse($actor, $server, $directory));
        }, null);
    }

    public function openTemplate(string $path): void
    {
        $this->perform(function ($actor, $server) use ($path) {
            $this->dispatch('bps-open-template', draft: app(FileTemplateActions::class)->open($actor, $server, $path));
        }, null);
    }

    public function insertTemplateToken(string $source, int $start, int $end, string $secretId, string $mode, string $format): void
    {
        $this->perform(function ($actor, $server) use ($source, $start, $end, $secretId, $mode, $format) {
            $this->dispatch('bps-template-inserted', source: app(FileTemplateActions::class)->insert($actor, $server, $source, $start, $end, $secretId, $mode, $format));
        }, null);
    }

    public function previewTemplate(#[\SensitiveParameter] array $draft): void
    {
        $this->perform(function ($actor, $server) use ($draft) {
            $prepared = app(FileTemplateActions::class)->prepareDraft($actor, $server, $draft);
            $this->dispatch('bps-template-preview', preview: $prepared['preview'], manifest: $prepared['file']->manifest);
        }, null);
    }

    public function saveTemplate(#[\SensitiveParameter] array $draft): void
    {
        $this->perform(function ($actor, $server) use ($draft) {
            app(FileTemplateActions::class)->saveDraft($actor, $server, $draft);
            $this->dispatch('bps-template-saved');
        });
    }

    public function removeTemplate(string $id): void
    {
        $this->perform(fn ($actor, $server) => app(FileTemplateActions::class)->remove($actor, $server, $id));
    }

    public function bindStartup(string $env, string $secretId, string $digest, bool $migrationConfirmed): void
    {
        $this->perform(fn ($actor, $server) => app(StartupBindingActions::class)->migrate($actor, $server, $env, $secretId, $digest, $migrationConfirmed));
    }

    public function unbindStartup(string $env): void
    {
        $this->perform(fn ($actor, $server) => app(StartupBindingActions::class)->unbind($actor, $server, $env));
    }

    protected function managementData(Server $server): array
    {
        try {
            $actor = $this->secretActor();
            $data = app(SecretActions::class)->overview($actor, $server, $this->adminContext());
            $member = true;
            try {
                app(SecretAuthorizer::class)->assertMember($actor, $server->id);
            } catch (AuthorizationException) {
                $member = false;
            }
            $data['canSetupFiles'] = $member && $actor->can('file.read-content', $server) && $actor->can('file.update', $server);
            $data['canBrowseFiles'] = $member && $actor->can('file.read', $server);
            $data['canSetupStartup'] = $member && $actor->can('startup.read', $server) && $actor->can('startup.update', $server);
            $data['bindable'] = ($data['canSetupFiles'] || $data['canSetupStartup']) ? app(FileTemplateActions::class)->picker($actor, $server) : [];
            $data['startupVariables'] = $data['canSetupStartup'] ? app(StartupBindingActions::class)->variables($actor, $server) : [];
            $data['setupFailures'] = $data['canSetupFiles'] ? DB::table('bps_setup_failures')->where('server_id', $server->id)->get(['file_id', 'source'])->toArray() : [];

            return $data;
        } catch (AuthorizationException) {
            abort(403);
        }
    }
}
