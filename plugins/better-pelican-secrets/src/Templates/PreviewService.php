<?php

namespace ShookEagle\BetterPelicanSecrets\Templates;

use App\Models\Server;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretAuthorizer;

final class PreviewService
{
    public function __construct(private TemplateCompiler $compiler, private SecretAuthorizer $authorizer) {}

    public function preview(User $actor, int $serverId, TemplateDocument $document): string
    {
        $this->authorizer->assertMember($actor, $serverId);
        $server = Server::query()->findOrFail($serverId);
        if (!$actor->can('file.read-content', $server)) {
            throw new AuthorizationException('File access denied.');
        }
        $compiled = $this->compiler->compile($document);
        $values = [];
        foreach ($document->manifest as $entry) {
            $values[$entry['secret_id']] = 'DUMMY_VALUE';
        }

        return $this->compiler->render($compiled, $values);
    }
}
