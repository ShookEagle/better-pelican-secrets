<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use DomainException;
use Illuminate\Support\Str;

final readonly class SecretVersionRef
{
    public function __construct(public string $secretId, public int $version)
    {
        if (!Str::isUuid($secretId) || $version < 1) {
            throw new DomainException('Invalid secret version reference.');
        }
    }
}
