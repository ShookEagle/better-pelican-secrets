<?php

namespace ShookEagle\BetterPelicanSecrets\Filament\Shared;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ShookEagle\BetterPelicanSecrets\Node\DesiredPlan;
use ShookEagle\BetterPelicanSecrets\Node\FilePlan;
use ShookEagle\BetterPelicanSecrets\Node\Protocol;
use ShookEagle\BetterPelicanSecrets\Plans\BindingService;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ManagedFile;
use ShookEagle\BetterPelicanSecrets\Secrets\Models\Secret;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretOperation as Op;
use ShookEagle\BetterPelicanSecrets\Templates\MigrationService;
use ShookEagle\BetterPelicanSecrets\Templates\PreviewService;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateDocument;
use ShookEagle\BetterPelicanSecrets\Templates\TemplateSourceService;

final class FileTemplateActions
{
    public function __construct(private SecretActions $actions) {}

    public function picker(User $actor, Server $server): array
    {
        app(SecretAuthorizer::class)->assertMember($actor, $server->id);

        return Secret::where('server_id', $server->id)->orderBy('name')->get()->filter(fn ($secret) => $this->actions->can($actor, Op::ListSecrets, $server, $secret->id) && $this->actions->can($actor, Op::Bind, $server, $secret->id))
            ->map(fn ($secret) => ['id' => $secret->id, 'name' => $secret->name])->values()->all();
    }

    public function suggest(string $path): array
    {
        $name = strtolower(basename($path));

        return match (true) {
            str_ends_with($name, '.json') => ['format' => 'json', 'mode' => 'json-string'],
            str_ends_with($name, '.yaml'), str_ends_with($name, '.yml') => ['format' => 'yaml', 'mode' => 'yaml-string'],
            $name === '.env', str_ends_with($name, '.env') => ['format' => 'dotenv', 'mode' => 'dotenv'],
            default => ['format' => 'text', 'mode' => 'raw'],
        };
    }

    public function prepare(User $actor, Server $server, FilePlan $file, string $source, bool $formatConfirmed): array
    {
        if (!$formatConfirmed) {
            throw new DomainException('format_confirmation_required');
        }
        app(BindingService::class)->preflightFile($actor, $server, $file, $source);
        $preview = app(PreviewService::class)->preview($actor, $server->id,
            new TemplateDocument($source, $file->format, $file->defaultMode, $file->manifest));

        return ['file' => $file, 'preview' => $preview];
    }

    public function manifest(User $actor, Server $server, string $source, string $defaultMode): array
    {
        $this->editPermission($actor, $server);
        if (!in_array($defaultMode, Protocol::MODES, true) || strlen($source) > Protocol::LIMITS['template_bytes']) {
            throw new DomainException('template_invalid');
        }
        preg_match_all('/\{\{secret:([A-Z][A-Z0-9_]{0,63})(?:\|(raw|json-string|yaml-string|dotenv))?\}\}/', $source, $tokens, PREG_SET_ORDER);
        $manifest = [];
        foreach ($tokens as $token) {
            $mode = $token[2] ?? $defaultMode;
            $key = $token[1] . ':' . $mode;
            if (!isset($manifest[$key])) {
                $secret = Secret::where('server_id', $server->id)->where('name', $token[1])->first();
                if (!$secret) {
                    throw new AuthorizationException('Secret access denied.');
                }
                $this->actions->authorize($actor, Op::Bind, $server, $secret->id);
                $manifest[$key] = ['secret_id' => $secret->id, 'name' => $secret->name, 'mode' => $mode, 'count' => 0];
            }
            $manifest[$key]['count']++;
        }

        return array_values($manifest);
    }

    /** Offsets are UTF-8 bytes. The browser converts its UTF-16 selection first. */
    public function insert(User $actor, Server $server, string $source, int $start, int $end, string $secretId, string $mode, string $format): string
    {
        $this->editPermission($actor, $server);
        $this->actions->authorize($actor, Op::Bind, $server, $secretId);
        if (!in_array($mode, Protocol::MODES, true) || !in_array($format, Protocol::FORMATS, true)
            || strlen($source) > Protocol::LIMITS['template_bytes'] || $start < 0 || $end < $start || $end > strlen($source)
            || !mb_check_encoding($source, 'UTF-8') || !mb_check_encoding(substr($source, 0, $start), 'UTF-8')
            || !mb_check_encoding(substr($source, $end), 'UTF-8')) {
            throw new DomainException('invalid_selection');
        }
        if ($format === 'json' && $mode === 'json-string') {
            [$start, $end] = $this->jsonValueSelection($source, $start, $end);
        }
        $name = Secret::where('server_id', $server->id)->findOrFail($secretId)->name;

        return substr($source, 0, $start) . '{{secret:' . $name . '|' . $mode . '}}' . substr($source, $end);
    }

    public function browse(User $actor, Server $server, string $directory = ''): array
    {
        $this->editPermission($actor, $server);
        if (!$actor->can('file.read', $server) || ($directory !== '' && !$this->safePath($directory))) {
            throw new AuthorizationException('File access denied.');
        }
        try {
            $entries = app(DaemonFileRepository::class)->setServer($server)->getDirectory($directory === '' ? '/' : $directory);
            $rows = [];
            foreach (array_slice($entries, 0, 500) as $entry) {
                if (!is_array($entry) || !is_string($entry['name'] ?? null) || str_contains($entry['name'], '/')
                    || !$this->safePath($entry['name']) || ($entry['symlink'] ?? true)) {
                    continue;
                }
                $rows[] = ['name' => $entry['name'], 'path' => ltrim($directory . '/' . $entry['name'], '/'),
                    'directory' => ($entry['directory'] ?? false) === true, 'file' => ($entry['file'] ?? false) === true];
            }

            return ['directory' => $directory, 'entries' => $rows];
        } catch (\Throwable) {
            throw new DomainException('source_unavailable');
        }
    }

    public function open(User $actor, Server $server, string $path): array
    {
        $this->editPermission($actor, $server);
        if (!$this->safePath($path)) {
            throw new DomainException('template_invalid');
        }
        $content = app(TemplateSourceService::class)->readPath($actor, $server, $path, true);
        $existing = ManagedFile::where('server_id', $server->id)->where('source', $path)->first();
        $guess = $this->suggest($path);

        return ['id' => $existing?->id ?? Str::uuid()->toString(), 'source' => $path,
            'destination' => $existing?->destination ?? '/home/container/' . $path,
            'format' => $existing?->format ?? $guess['format'], 'mode' => $existing?->default_mode ?? $guess['mode'],
            'content' => $content ?? '', 'digest' => $content === null ? 'absent' : hash('sha256', $content),
            'format_confirmed' => false, 'readonly_confirmed' => false, 'migration_confirmed' => false];
    }

    public function prepareDraft(User $actor, Server $server, #[\SensitiveParameter] array $draft): array
    {
        foreach (['id', 'source', 'destination', 'format', 'mode', 'content'] as $field) {
            if (!is_string($draft[$field] ?? null)) {
                throw new DomainException('template_invalid');
            }
        }
        if (!Str::isUuid($draft['id']) || !$this->safePath($draft['source'])) {
            throw new DomainException('template_invalid');
        }
        $manifest = $this->manifest($actor, $server, $draft['content'], $draft['mode']);
        $file = new FilePlan($draft['id'], $draft['source'], $draft['destination'], $draft['format'], $draft['mode'], $manifest);

        return $this->prepare($actor, $server, $file, $draft['content'], ($draft['format_confirmed'] ?? false) === true);
    }

    public function saveDraft(User $actor, Server $server, #[\SensitiveParameter] array $draft): DesiredPlan
    {
        if (($draft['readonly_confirmed'] ?? false) !== true || ($draft['migration_confirmed'] ?? false) !== true
            || !is_string($draft['digest'] ?? null) || !is_array($draft['references'] ?? null)) {
            throw new DomainException('migration_confirmation_required');
        }
        $prepared = $this->prepareDraft($actor, $server, $draft);

        return app(MigrationService::class)->migrate(
            $actor, $server, $draft['digest'], $draft['content'], $prepared['file'], $draft['references']);
    }

    public function remove(User $actor, Server $server, string $fileId): void
    {
        $this->editPermission($actor, $server);
        $file = ManagedFile::where('server_id', $server->id)->findOrFail($fileId);
        app(BindingService::class)->removeFile($actor, $server, $fileId);
        DB::table('bps_setup_failures')->where('server_id', $server->id)->where('source', $file->source)->delete();
    }

    private function safePath(string $path): bool
    {
        return $path !== '' && strlen($path) <= 4096 && mb_check_encoding($path, 'UTF-8') && !str_starts_with($path, '/') && !str_contains($path, chr(92))
            && !preg_match('/[[:cntrl:]]/u', $path) && !array_intersect(explode('/', $path), ['', '.', '..']);
    }

    private function editPermission(User $actor, Server $server): void
    {
        app(SecretAuthorizer::class)->assertMember($actor, $server->id);
        if (!$actor->can('file.update', $server)) {
            throw new AuthorizationException('File access denied.');
        }
    }

    private function jsonValueSelection(string $source, int $start, int $end): array
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"/s', $source, $strings, PREG_OFFSET_CAPTURE);
        foreach ($strings[0] as [$text, $offset]) {
            $after = $offset + strlen($text);
            if ($start >= $offset && $start < $after && $end <= $after) {
                if (str_starts_with(ltrim(substr($source, $after)), ':')) {
                    throw new DomainException('select_json_value');
                }

                return [$offset, $after];
            }
        }
        // A new value position may have no quoted string yet; selection must not cross one.
        foreach ($strings[0] as [$text, $offset]) {
            if ($start < $offset + strlen($text) && $end > $offset) {
                throw new DomainException('select_json_value');
            }
        }

        return [$start, $end];
    }
}
