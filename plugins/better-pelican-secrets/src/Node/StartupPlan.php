<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use JsonSerializable;

final readonly class StartupPlan implements JsonSerializable
{
    public function __construct(public string $env, public string $secretId) {}

    public function toWire(): array
    {
        return ['env' => $this->env, 'secret_id' => $this->secretId];
    }

    public function jsonSerialize(): array
    {
        return $this->toWire();
    }
}
