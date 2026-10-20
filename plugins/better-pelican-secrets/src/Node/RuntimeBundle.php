<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use DomainException;
use JsonSerializable;
use ShookEagle\BetterPelicanSecrets\Secrets\SecretValue;
use Stringable;

final readonly class RuntimeBundle implements JsonSerializable, Stringable
{
    /** @param array<string, SecretValue> $values Opaque values, never default serialization. */
    public function __construct(public DesiredPlan $plan, public string $attemptId, #[\SensitiveParameter] private array $values) {}

    /** @internal Explicit export for the authenticated node controller response only. */
    public function toWire(): array
    {
        $values = array_map(fn (SecretValue $value) => $value->bytes(), $this->values);

        return [...$this->jsonSerialize(), 'values' => $values ?: (object) []];
    }

    public function jsonSerialize(): array
    {
        return [...$this->plan->toWire(), 'attempt_id' => $this->attemptId];
    }

    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    public function __serialize(): array
    {
        return $this->jsonSerialize();
    }

    public function __unserialize(array $data): void
    {
        throw new DomainException('Runtime bundles cannot be unserialized.');
    }

    public function __toString(): string
    {
        return json_encode($this->jsonSerialize(), JSON_THROW_ON_ERROR);
    }
}
