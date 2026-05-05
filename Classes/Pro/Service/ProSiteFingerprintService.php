<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Service\ExtensionContextService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class ProSiteFingerprintService
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ExtensionContextService $extensionContextService,
    ) {
    }

    /**
     * @return list<string>
     */
    public function collectAllSites(): array
    {
        $allSites = [];

        try {
            foreach ($this->siteFinder->getAllSites() as $site) {
                if (!$site instanceof Site) {
                    continue;
                }

                $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase());
                if ($domain !== '') {
                    $allSites[] = $domain;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        $allSites = array_values(array_unique($allSites));
        sort($allSites);

        return $allSites;
    }

    /**
     * @return list<string>
     */
    public function collectValidationSites(string $currentDomain, bool $isTrial): array
    {
        $currentDomain = trim($currentDomain);

        if ($isTrial) {
            return $currentDomain !== '' ? [$currentDomain] : [];
        }

        return $this->collectAllSites();
    }

    /**
     * @param list<string> $allSites
     */
    public function buildFingerprint(array $allSites): string
    {
        if ($allSites === []) {
            return 'no_sites';
        }

        return sha1(implode('|', $allSites));
    }
}