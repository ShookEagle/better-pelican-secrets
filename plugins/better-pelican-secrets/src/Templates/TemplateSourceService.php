<?php

namespace ShookEagle\BetterPelicanSecrets\Templates;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonFileRepository;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use ShookEagle\BetterPelicanSecrets\Node\Protocol;
use ShookEagle\BetterPelicanSecrets\Plans\ApplicationStatusService;
use ShookEagle\BetterPelicanSecrets\Plans\Models\ManagedFile;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;

final class TemplateSourceService
{
    public function __construct(private DaemonFileRepository $files, private TemplateCompiler $compiler,
        private SecretAuthorizer $authorizer, private ApplicationStatusService $status) {}

    public function read(User $actor, Server $server, string $fileId): string
    {
        $this->authorize($actor, $server, 'file.read-content');
        $file = ManagedFile::query()->where('server_id', $server->id)->findOrFail($fileId);

        return $this->source($server, $file);
    }

    public function save(User $actor, Server $server, string $fileId, string $source, string $expectedDigest): void
    {
        $this->authorize($actor, $server, 'file.update');
        DB::transaction(function () use ($server, $fileId, $source, $expectedDigest) {
            $server = Server::query()->lockForUpdate()->findOrFail($server->id);
            $file = ManagedFile::query()->where('server_id', $server->id)->lockForUpdate()->findOrFail($fileId);
            $compiled = $this->compiler->compile(new TemplateDocument($source, $file->format, $file->default_mode, $file->manifest));
            $dummy = [];
            foreach ($file->manifest as $entry) {
                $dummy[$entry['secret_id']] = 'DUMMY_VALUE';
            }
            $this->compiler->render($compiled, $dummy);
            if (!preg_match('/\A[0-9a-f]{64}\z/', $expectedDigest) || !hash_equals($expectedDigest, hash('sha256', $this->source($server, $file)))) {
                throw new DomainException('source_conflict');
            }
            $this->files->setServer($server)->putContent($file->source, $source);
            $file->source_digest = hash('sha256', $source);
            $file->save();
            $this->status->recordSourceSave($server, $file->id, $file->source_digest);
        }, 3);
    }

    private function authorize(User $actor, Server $server, string $permission): void
    {
        $this->authorizer->assertMember($actor, $server->id);
        if (!$actor->can($permission, $server)) {
            throw new AuthorizationException('File access denied.');
        }
    }

    private function source(Server $server, ManagedFile $file): string
    {
        return $this->readPathInternal($server, $file->source);
    }

    public function readPath(User $actor, Server $server, string $path, bool $allowMissing = false): ?string
    {
        $this->authorize($actor, $server, 'file.read-content');
        if ($path === '' || strlen($path) > 4096 || !mb_check_encoding($path, 'UTF-8') || preg_match('/[[:cntrl:]]/u', $path) || str_contains($path, chr(92)) || str_starts_with($path, '/')
            || array_intersect(explode('/', $path), ['', '.', '..'])) {
            throw new TemplateError('template_invalid');
        }
        try {
            return $this->readPathInternal($server, $path);
        } catch (RequestException $error) {
            if ($allowMissing && $error->response->status() === 404) {
                return null;
            }
            throw new TemplateError('template_invalid');
        } catch (\Throwable $error) {
            if ($error instanceof TemplateError) {
                throw $error;
            }
            throw new TemplateError('template_invalid');
        }
    }

    private function readPathInternal(Server $server, string $path): string
    {
        $limit = config('better-pelican-secrets.limits.template_bytes', Protocol::LIMITS['template_bytes']);
        if (!is_int($limit) || $limit < 1 || $limit > Protocol::LIMITS['template_bytes']) {
            throw new TemplateError('limit_exceeded');
        }
        $response = $this->files->setServer($server)->getHttpClient()
            ->withOptions(['stream' => true])->connectTimeout(3)->timeout(10)
            ->get("/api/servers/{$server->uuid}/files/contents", ['file' => $path]);
        $stream = $response->toPsrResponse()->getBody();
        try {
            $length = $response->header('Content-Length');
            if (ctype_digit($length) && (float) $length > $limit) {
                throw new TemplateError('limit_exceeded');
            }
            $source = '';
            while (!$stream->eof() && strlen($source) <= $limit) {
                $chunk = $stream->read(min(8192, $limit + 1 - strlen($source)));
                if ($chunk === '') {
                    break;
                }
                $source .= $chunk;
            }
            if (strlen($source) > $limit) {
                throw new TemplateError('limit_exceeded');
            }
            if (!mb_check_encoding($source, 'UTF-8') || str_contains($source, "\0")) {
                throw new TemplateError('template_invalid');
            }

            return $source;
        } finally {
            $stream->close();
        }
    }
}
