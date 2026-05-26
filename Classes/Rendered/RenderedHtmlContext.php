<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

final readonly class RenderedHtmlContext
{
    public function __construct(
        public int $pageUid,
        public int $languageUid,
        public string $siteIdentifier,
        public string $url,
        public string $html,
        public \DOMDocument $document,
        public \DOMXPath $xpath,
    ) {
    }
}
