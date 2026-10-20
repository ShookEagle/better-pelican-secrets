<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

use DomainException;

final class NodeRequestError extends DomainException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 422)
    {
        parent::__construct($errorCode);
    }
}
