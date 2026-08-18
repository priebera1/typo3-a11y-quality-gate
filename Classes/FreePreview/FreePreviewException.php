<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

final class FreePreviewException extends \RuntimeException
{
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
}
