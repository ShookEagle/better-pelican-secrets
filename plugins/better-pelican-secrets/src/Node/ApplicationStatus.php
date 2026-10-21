<?php

namespace ShookEagle\BetterPelicanSecrets\Node;

final readonly class ApplicationStatus
{
    public function __construct(public string $desiredRevision, public ?string $appliedRevision, public bool $pending,
        public bool $dirty, public ?string $lastAttemptId, public ?string $lastErrorCode, public bool $observationStale = false) {}
}
