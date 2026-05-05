<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

final class ExtensionContextService
{
    public function __construct(
        private readonly DomainNormalizer $domainNormalizer,
    ) {
    }

    public function getExtensionVersion(string $extensionKey = 'a11y_quality_gate'): string
    {
        try {
            return (string)ExtensionManagementUtility::getExtensionVersion($extensionKey);
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    public function getNormalizedDomainFromSiteBase(string $siteBase): string
    {
        return $this->domainNormalizer->normalizeFromSiteBase($siteBase);
    }

    /**
     * @return array{domain:string, version:string}
     */
    public function getCrawlerContextFromSiteBase(
        string $siteBase,
        string $extensionKey = 'a11y_quality_gate',
    ): array {
        return [
            'domain' => $this->getNormalizedDomainFromSiteBase($siteBase),
            'version' => $this->getExtensionVersion($extensionKey),
        ];
    }
}