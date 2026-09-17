<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

final class FreePreviewException extends \RuntimeException
{
    /**
     * API throttling: the generic request limit and the Free submit limits per minute and per day.
     * The request is refused for now, not rejected as invalid.
     */
    public const RATE_LIMIT_CODES = ['rate_limit_exceeded', 'free_preview_rate_limited'];

    /**
     * @param array<string, mixed> $freeDaily
     */
    public function __construct(
        string $message,
        public readonly string $state,
        public readonly string $errorCode,
        public readonly int $httpStatus = 500,
        public readonly array $freeDaily = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function isRateLimited(): bool
    {
        return in_array($this->errorCode, self::RATE_LIMIT_CODES, true);
    }
}
