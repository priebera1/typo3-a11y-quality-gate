<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Contract;

use TYPO3\CMS\Core\Site\Entity\Site;

interface SiteResolutionServiceInterface
{
    public function resolveSiteByIdentifier(string $siteIdentifier): ?Site;
    public function resolveSiteIdentifierForPageId(int $pageUid, string $fallback = ''): string;
}
