<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

/** A refused remediation action with a stable, safe code for the browser (never content or provider messages). */
final class RemediationException extends \RuntimeException
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly string $safeCode,
        string $message = '',
        public readonly int $httpStatus = 422,
        public readonly array $data = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $safeCode, 1771004000, $previous);
    }
}
