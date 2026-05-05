<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Dto;

use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;

final class RemoteScanRequestData
{
    public function __construct(
        public readonly string $siteIdentifier,
        public readonly string $domain,
        public readonly string $startUrl,
        public readonly ?string $sitemapUrl,
        public readonly RemoteScanSourceType $sourceType,
        public readonly int $maxPages,
        public readonly bool $followLinks,
        public readonly string $axeLocale,
    ) {
    }
}