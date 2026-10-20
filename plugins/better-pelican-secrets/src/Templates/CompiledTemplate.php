<?php

namespace ShookEagle\BetterPelicanSecrets\Templates;

/** Literal spans and approved ID/mode tokens, with no resolved secret values. */
final readonly class CompiledTemplate
{
    public function __construct(public string $format, public array $parts, public array $manifest) {}
}
