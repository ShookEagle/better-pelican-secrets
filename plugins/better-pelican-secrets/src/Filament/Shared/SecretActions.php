<?php

namespace ShookEagle\BetterPelicanSecrets\Filament\Shared;

use App\Models\Server;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Plans\ApplicationStatusService;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ManagedFile;
use ShookEagle\BetterPelicanSecrets\Plans\Models\StartupBinding;
use ShookEagle\BetterPelicanSecrets\Secrets\AuditRecorder;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\SecretAuditEvent;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation as Op;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretScope;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretStore;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretVersionRef;

/** The shared public management boundary. No model containing values leaves it. */
final class SecretActions
{
    public function __construct(private SecretAuthorizer $auth, private SecretStore $store, private AuditRecorder $audit) {}

    public function authorize(User $actor, Op $operation, Server $server, ?string $id = null, bool $admin = false): void
    {
        $scope = new SecretScope($server->id, $id);
        if ($admin) {
            $this->auth->authorizeAdmin($actor, $operation, $scope);
        } else {
            $this->auth->authorize($actor, $operation, $scope);
        }
    }

    public function can(User $actor, Op $operation, Server $server, ?string $id = null, bool $admin = false): bool
    {
        try {
            $this->authorize($actor, $operation, $server, $id, $admin);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    public function accessible(User $actor, Server $server, bool $admin = false): bool
    {
        foreach (Op::cases() as $op) {
            if ($this->can($actor, $op, $server, admin: $admin)) {
                return true;
            }
        }
        foreach (Secret::where('server_id', $server->id)->pluck('id') as $id) {
            foreach (Op::cases() as $op) {
                if ($op !== Op::Create && $this->can($actor, $op, $server, $id, $admin)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function servers(User $actor, bool $admin = false): array
    {
        return Server::query()->orderBy('name')->get()->filter(fn ($server) => $this->accessible($actor, $server, $admin))
            ->map(fn ($server) => ['id' => $server->id, 'name' => $server->name])->values()->all();
    }

    public function listing(User $actor, Server $server, bool $admin = false): array
    {
        $all = $this->can($actor, Op::ListSecrets, $server, admin: $admin);
        $rows = [];
        foreach (Secret::query()->where('server_id', $server->id)->orderBy('name')->get() as $secret) {
            if ($all || $this->can($actor, Op::ListSecrets, $server, $secret->id, $admin)) {
                $rows[] = $this->metadata($actor, $server, $secret->id, $admin);
            }
        }
        if (!$all && !$rows) {
            throw new AuthorizationException('Secret access denied.');
        }

        return $rows;
    }

    public function adminListing(User $actor, ?int $serverId = null): array
    {
        $rows = [];
        foreach (Server::query()->when($serverId, fn ($q) => $q->whereKey($serverId))->get() as $server) {
            try {
                $listed = $this->listing($actor, $server, true);
                if ($listed) {
                    $status = app(ApplicationStatusService::class)->forServer($server);
                    foreach ($listed as $row) {
                        $rows[] = [...$row, 'status' => $status];
                    }
                }
            } catch (AuthorizationException) {
                // A cross-server list includes only explicitly listed scopes.
            }
        }

        return $rows;
    }

    private function metadata(User $actor, Server $server, string $id, bool $admin = false): array
    {
        $this->auth->assertScope(new SecretScope($server->id, $id));
        // Called only after an action's authorization; a write-only actor may get its own receipt.
        $meta = $this->store->metadata($id);
        $count = StartupBinding::where('server_id', $server->id)->where('secret_id', $id)->count();
        foreach (ManagedFile::where('server_id', $server->id)->get() as $file) {
            foreach ($file->manifest as $entry) {
                if ($entry['secret_id'] === $id) {
                    $count += $entry['count'];
                }
            }
        }
        $allowed = array_values(array_map(fn ($op) => $op->value, array_filter(Op::cases(),
            fn ($op) => $op !== Op::Create && $this->can($actor, $op, $server, $id, $admin))));

        return ['id' => $id, 'server_id' => $server->id, 'server_name' => $server->name, 'name' => $meta->name,
            'description' => $meta->description, 'value_set' => true, 'version' => $meta->currentVersion,
            'updated_at' => $meta->updatedAt, 'binding_count' => $count, 'allowed' => $allowed];
    }

    public function create(User $actor, Server $server, string $name, string $description, #[\SensitiveParameter] string $value, string $mode = 'manual', bool $admin = false): array
    {
        $this->authorize($actor, Op::Create, $server, admin: $admin);
        $input = match ($mode) {
            'generate' => SecretValue::generate(),
            'empty' => new SecretValue(''),
            'manual' => $value !== '' ? new SecretValue($value) : throw new DomainException('Choose a value or explicitly select an empty value.'),
            default => throw new DomainException('Invalid value mode.'),
        };
        $meta = $this->store->create($server, $name, $description, $input, $actor);

        return $this->metadata($actor, $server, $meta->secretId, $admin);
    }

    public function replace(User $actor, Server $server, string $id, #[\SensitiveParameter] string $value, bool $admin = false): array
    {
        $this->authorize($actor, Op::Update, $server, $id, $admin);
        if ($value !== '') {
            $this->store->replace($id, new SecretValue($value), $actor);
        }

        return $this->metadata($actor, $server, $id, $admin);
    }

    public function description(User $actor, Server $server, string $id, string $description, bool $admin = false): array
    {
        $this->authorize($actor, Op::Update, $server, $id, $admin);
        $this->store->updateDescription($id, $description, $actor);

        return $this->metadata($actor, $server, $id, $admin);
    }

    public function setEmpty(User $actor, Server $server, string $id, bool $admin = false): array
    {
        $this->authorize($actor, Op::Update, $server, $id, $admin);
        $this->store->replace($id, new SecretValue(''), $actor);

        return $this->metadata($actor, $server, $id, $admin);
    }

    public function generate(User $actor, Server $server, string $id, bool $admin = false): array
    {
        $this->authorize($actor, Op::Update, $server, $id, $admin);
        $this->store->replace($id, SecretValue::generate(), $actor);

        return $this->metadata($actor, $server, $id, $admin);
    }

    public function reveal(User $actor, Server $server, string $id, bool $admin = false): SecretValue
    {
        return DB::transaction(function () use ($actor, $server, $id, $admin) {
            $this->authorize($actor, Op::Reveal, $server, $id, $admin);
            $secret = Secret::query()->lockForUpdate()->findOrFail($id);
            $value = $this->store->reveal(new SecretVersionRef($id, $secret->current_version));
            $this->audit->record($actor, 'secret.reveal', ['server_id' => $server->id, 'secret_id' => $id, 'version' => $secret->current_version]);

            return $value;
        }, 3);
    }

    public function delete(User $actor, Server $server, string $id, bool $admin = false): void
    {
        app(BindingService::class)->deleteSecret($actor, $server, $id, $admin);
    }

    public function overview(User $actor, Server $server, bool $admin = false): array
    {
        if (!$this->accessible($actor, $server, $admin)) {
            throw new AuthorizationException('Secret access denied.');
        }
        $rows = [];
        try {
            $rows = $this->listing($actor, $server, $admin);
        } catch (AuthorizationException) {
        }
        $ids = array_column($rows, 'id');
        $files = ManagedFile::where('server_id', $server->id)->get()->filter(fn ($file) => array_all($file->manifest, fn ($entry) => in_array($entry['secret_id'], $ids, true)))
            ->map(fn ($file) => [...$file->only(['id', 'source', 'destination', 'format']),
                'can_manage' => array_all($file->manifest, fn ($entry) => $this->can($actor, Op::Bind, $server, $entry['secret_id'], $admin))])->values()->all();
        $bindings = StartupBinding::where('server_id', $server->id)->whereIn('secret_id', $ids)->get(['env', 'secret_id'])->toArray();
        $audit = [];
        if ($this->can($actor, Op::ManageAccess, $server, admin: $admin) && $this->can($actor, Op::ListSecrets, $server, admin: $admin)) {
            $audit = SecretAuditEvent::query()->where('metadata->server_id', $server->id)
                ->orderByDesc('id')->limit(40)->get(['actor_id', 'operation', 'metadata', 'created_at'])->toArray();
        }

        return ['rows' => $rows, 'files' => $files, 'bindings' => $bindings, 'audit' => $audit,
            'status' => app(ApplicationStatusService::class)->forServer($server),
            'canCreate' => $this->can($actor, Op::Create, $server, admin: $admin),
            'canManageServerAccess' => $this->can($actor, Op::ManageAccess, $server, admin: $admin)];
    }
}
