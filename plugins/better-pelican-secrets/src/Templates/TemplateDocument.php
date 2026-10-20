<?php

namespace ShookEagle\BetterPelicanSecrets\Templates;

final readonly class TemplateDocument
{
    public function __construct(#[\SensitiveParameter] public string $source, public string $format,
        public string $defaultMode, public array $manifest) {}
}
