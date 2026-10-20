<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

final readonly class SecretMetadata
{
    public function __construct(
        public string $secretId,
        public int $serverId,
        public string $name,
        public string $description,
        public int $currentVersion,
        public string $createdAt,
        public string $updatedAt,
    ) {}
}
