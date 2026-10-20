<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

final readonly class SecretScope
{
    public function __construct(public int $serverId, public ?string $secretId = null) {}
}
