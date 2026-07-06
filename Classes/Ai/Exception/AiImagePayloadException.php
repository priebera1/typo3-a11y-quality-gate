<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Exception;

final class AiImagePayloadException extends \RuntimeException
{
    /** @param array<string, bool|int|string|null> $diagnostics */
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly array $diagnostics = [],
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** @return array<string, bool|int|string|null> */
    public function diagnostics(): array
    {
        return $this->diagnostics;
    }
}
