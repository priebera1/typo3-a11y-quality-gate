<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

final readonly class RenderedErrorPageDetectionResult
{
    public function __construct(
        public bool $suspectedErrorPage,
        public string $reason = '',
    ) {
    }
}
