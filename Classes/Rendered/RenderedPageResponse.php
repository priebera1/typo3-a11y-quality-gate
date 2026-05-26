<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

final readonly class RenderedPageResponse
{
    public function __construct(
        public bool $success,
        public string $html = '',
        public int $statusCode = 0,
        public string $contentType = '',
        public string $error = '',
        public string $finalUrl = '',
    ) {
    }
}
