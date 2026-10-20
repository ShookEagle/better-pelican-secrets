<?php

namespace ShookEagle\BetterPelicanSecrets\Secrets;

use DomainException;
use JsonSerializable;
use SensitiveParameter;
use Stringable;

final readonly class SecretValue implements JsonSerializable, Stringable
{
    private \SensitiveParameterValue $value;

    public function __construct(#[SensitiveParameter] string $value)
    {
        if (strlen($value) > 65536 || !mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new DomainException('Invalid secret value.');
        }
        $this->value = new \SensitiveParameterValue($value);
    }

    public static function generate(): self
    {
        return new self(rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='));
    }

    public function bytes(): string
    {
        return $this->value->getValue();
    }

    public function __toString(): string
    {
        return '[REDACTED]';
    }

    public function jsonSerialize(): string
    {
        return '[REDACTED]';
    }

    public function __debugInfo(): array
    {
        return ['value' => '[REDACTED]'];
    }

    public function __serialize(): array
    {
        return ['value' => '[REDACTED]'];
    }

    public function __unserialize(array $data): void
    {
        throw new DomainException('Secret values cannot be unserialized.');
    }
}
