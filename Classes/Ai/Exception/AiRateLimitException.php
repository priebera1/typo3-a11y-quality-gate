<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Exception;

final class AiRateLimitException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
