<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use App\Models\Node;
use App\Models\Server;
use App\Models\ServerTransfer;
use App\Models\Subuser;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ManagedFile;
use ShookEagle\BetterPelicanSecrets\Plans\Models\StartupBinding;
use Throwable;

final class NodeCapabilityService
{
    public const PANEL_VERSION = '1.0.0-rc.1';

    public const WINGS_BASE = '7b489da7b8196d0b4baef3e2db062c06d743e2f9';

    public function compatible(Node $node, bool $refresh = false): bool
    {
        if ($node->scheme !== 'https') {
            return false;
        }
        $key = $this->key($node);
        if (!$refresh && Cache::get($key) === true) {
            return true;
        }
        try {
            $response = Http::daemon($node)->withOptions(['verify' => true, 'allow_redirects' => false, 'stream' => true])
                ->connectTimeout(3)->timeout(5)->get('/api/system', ['secrets' => '1']);
            $stream = $response->toPsrResponse()->getBody();
            if ($response->status() !== 200) {
                $stream->close();
                Cache::forget($key);

                return false;
            }
            $raw = '';
            while (!$stream->eof() && strlen($raw) <= 65536) {
                $chunk = $stream->read(min(8192, 65537 - strlen($raw)));
                if ($chunk === '') {
                    break;
                }
                $raw .= $chunk;
            }
            $stream->close();
            if (strlen($raw) > 65536) {
                Cache::forget($key);

                return false;
            }
            $capability = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            $valid = is_array($capability) && ($capability['protocol'] ?? null) === 1
                && ($capability['wings_base_commit'] ?? null) === self::WINGS_BASE
                && ($capability['panel_base_version'] ?? null) === self::PANEL_VERSION;
            if ($valid) {
                Cache::put($key, true, 60);
            } else {
                Cache::forget($key);
            }

            return $valid;
        } catch (Throwable) {
            Cache::forget($key);

            return false;
        }
    }

    public function invalidate(Node $node): void
    {
        Cache::forget($this->key($node));
    }

    public function assertCompatible(Node $node): void
    {
        if (!$this->compatible($node)) {
            throw new DomainException('node_incompatible');
        }
    }

    public function isProtected(Server $server): bool
    {
        return ManagedFile::query()->where('server_id', $server->id)->exists()
            || StartupBinding::query()->where('server_id', $server->id)->exists();
    }

    public function assertServerCompatible(Server $server): void
    {
        if ($this->isProtected($server)) {
            $this->assertCompatible($server->node);
        }
    }

    public function filterSocketPermissions(Server $server, array $permissions): array
    {
        if (!$this->isProtected($server) || $this->compatible($server->node)) {
            return $permissions;
        }
        if (in_array('*', $permissions, true)) {
            $ordinary = array_filter(Subuser::allPermissionKeys(), fn ($permission) => !str_starts_with($permission, 'secrets.'));
            $permissions = [...$permissions, ...$ordinary];
        }

        return array_values(array_unique(array_filter($permissions,
            fn ($permission) => !str_contains($permission, '*') && !in_array($permission, ['control.start', 'control.restart'], true))));
    }

    public function guardServerMove(Server $server): void
    {
        if (!$server->isDirty('node_id') || !$this->isProtected($server)) {
            return;
        }
        $target = Node::query()->find($server->node_id);
        if (!$target) {
            throw new DomainException('node_incompatible');
        }
        $this->assertCompatible($target);
    }

    public function guardTransfer(ServerTransfer $transfer): void
    {
        $server = Server::query()->find($transfer->server_id);
        if (!$server || !$this->isProtected($server)) {
            return;
        }
        $target = Node::query()->find($transfer->new_node);
        if (!$target) {
            throw new DomainException('node_incompatible');
        }
        $this->assertCompatible($target);
    }

    private function key(Node $node): string
    {
        return 'bps:capability:v1:' . hash('sha256', $node->id . ':' . $node->getConnectionAddress() . ':' . $node->getRawOriginal('daemon_token'));
    }
}
