<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use JsonSerializable;

final readonly class DesiredPlan implements JsonSerializable
{
    public function __construct(public int $protocol, public string $serverUuid, public string $revision,
        public array $versions, public array $files, public array $startup, public array $limits) {}

    public function toWire(): array
    {
        return ['protocol' => $this->protocol, 'server_uuid' => $this->serverUuid, 'revision' => $this->revision,
            'versions' => array_map(fn ($ref) => ['secret_id' => $ref->secretId, 'version' => $ref->version], $this->versions),
            'files' => array_map(fn (FilePlan $file) => $file->toWire(), $this->files),
            'startup' => array_map(fn (StartupPlan $binding) => $binding->toWire(), $this->startup),
            'limits' => $this->limits];
    }

    public function jsonSerialize(): array
    {
        return $this->toWire();
    }
}
