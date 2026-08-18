<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Exception;

final class ApiRequestFailedException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        ?\Throwable $previous = null,
        public readonly string $apiErrorCode = '',
        public readonly array $details = [],
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
