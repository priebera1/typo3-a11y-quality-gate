<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

final readonly class RenderedPageUrl
{
    public function __construct(
        public string $url,
        public string $allowedHost,
        public ?int $allowedPort,
        public string $siteIdentifier,
    ) {
    }
}
