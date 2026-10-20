<?php

namespace ShookEagle\BetterPelicanSecrets\Templates;

use DomainException;

final class TemplateError extends DomainException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct(in_array($errorCode, ['template_invalid', 'manifest_mismatch', 'limit_exceeded', 'value_invalid', 'secret_missing', 'dotenv_unsupported'], true) ? $errorCode : 'template_invalid');
    }
}
