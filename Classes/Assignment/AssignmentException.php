<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Assignment;

/** A refused assignment: the code names the reason (translated by the caller), the HTTP status the response class. */
final class AssignmentException extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $status)
    {
        parent::__construct($reason, $status);
    }
}
