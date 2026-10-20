<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use JsonSerializable;

final readonly class FilePlan implements JsonSerializable
{
    public function __construct(public string $fileId, public string $source, public string $destination,
        public string $format, public string $defaultMode, public array $manifest) {}

    public function toWire(): array
    {
        return ['file_id' => $this->fileId, 'source' => $this->source, 'destination' => $this->destination,
            'format' => $this->format, 'default_mode' => $this->defaultMode, 'manifest' => $this->manifest];
    }

    public function jsonSerialize(): array
    {
        return $this->toWire();
    }
}
