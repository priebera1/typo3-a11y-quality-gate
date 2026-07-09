<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Exception;

final class AiLinkTextSuggestionException extends \RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $message = '',
        int $code = 1771002800,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $safeCode, $code, $previous);
    }
}
