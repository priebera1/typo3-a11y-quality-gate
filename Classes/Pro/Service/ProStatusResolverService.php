<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Service\ExtensionContextService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class ProStatusResolverService
{
    public function __construct(
        private readonly ProCapabilityService $proCapabilityService,
        private readonly ExtensionContextService $extensionContextService,
        private readonly SiteFinder $siteFinder,
    ) {
    }

    /**
     * @return object
     */
    public function resolveForSite(?Site $site): object
    {
        $domain = $site !== null
            ? $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase())
            : '';

        return $this->resolveForDomain($domain);
    }

    /**
     * @return object
     */
    public function resolveForSiteIdentifier(string $siteIdentifier): object
    {
        if ($siteIdentifier === '') {
            return $this->resolveForDomain('');
        }

        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return $this->resolveForDomain('');
        }

        return $this->resolveForSite($site);
    }

    public function hasCrawlerForAnySite(): bool
    {
        try {
            $sites = $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            return false;
        }

        foreach ($sites as $site) {
            if (!$site instanceof Site) {
                continue;
            }

            $status = $this->resolveForSite($site);

            if ($status->valid && $status->hasCrawler) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return object
     */
    private function resolveForDomain(string $domain): object
    {
        return $this->proCapabilityService->getStatus(
            $domain,
            $this->extensionContextService->getExtensionVersion()
        );
    }
}