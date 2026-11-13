<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

/**
 * A refused editor AI request with a safe, stable code the editor UI maps to its own copy (never a provider message).
 */
final class EditorAiException extends \RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $message,
        public readonly int $httpStatus = 422,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
