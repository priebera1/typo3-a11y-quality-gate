<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Exception;

final class AiModelDiscoveryException extends \RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
