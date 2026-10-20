<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use JsonSerializable;

final readonly class AppliedReport implements JsonSerializable
{
    public function __construct(public int $protocol, public string $serverUuid, public string $kind,
        public string $attemptId, public string $revision, public array $sourceDigests, public array $versions,
        public ?string $errorCode, public ?string $observationSequence = null) {}

    public function toWire(): array
    {
        $wire = ['protocol' => $this->protocol, 'server_uuid' => $this->serverUuid, 'kind' => $this->kind,
            'attempt_id' => $this->attemptId, 'revision' => $this->revision,
            'source_digests' => $this->sourceDigests ?: (object) [],
            'versions' => array_map(fn ($ref) => ['secret_id' => $ref->secretId, 'version' => $ref->version], $this->versions),
            'error_code' => $this->errorCode];
        if ($this->observationSequence !== null) {
            $wire['observation_sequence'] = $this->observationSequence;
        }

        return $wire;
    }

    public function jsonSerialize(): array
    {
        return $this->toWire();
    }
}
