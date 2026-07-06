<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Core\Site\Entity\Site;

final class ProStatusResolverService
{
    public function __construct(
        private readonly ProCapabilityService $proCapabilityService,
        private readonly ExtensionContextService $extensionContextService,
        private readonly SiteResolutionService $siteResolutionService,
    ) {
    }

    /**
     * @return object
     */
    public function resolveForSite(?Site $site): object
    {
        if ($site === null) {
            return $this->resolveForDomain('');
        }

        $domains = $this->collectCandidateDomainsForSite($site);
        $fallbackStatus = null;

        foreach ($domains as $domain) {
            $status = $this->resolveForDomain($domain);

            if ($fallbackStatus === null) {
                $fallbackStatus = $status;
            }

            if (($status->valid ?? false) && ($status->hasCrawler ?? false)) {
                return $status;
            }
        }

        return $fallbackStatus ?? $this->resolveForDomain('');
    }

    /**
     * @return object
     */
    public function resolveForSiteIdentifier(string $siteIdentifier): object
    {
        if ($siteIdentifier === '') {
            return $this->resolveForDomain('');
        }

        $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
        if ($site === null) {
            return $this->resolveForDomain('');
        }

        return $this->resolveForSite($site);
    }


    /**
     * Licence validation and remote crawler submission can be domain-sensitive.
     * TYPO3 site bases may differ between the default site base and language
     * bases, especially in test fixtures and multi-domain setups. The overview
     * must therefore resolve PRO status against the same domain candidates that
     * can be used by the remote crawler flow, not only the default site base.
     *
     * @return list<string>
     */
    private function collectCandidateDomainsForSite(Site $site): array
    {
        $domains = [];

        $this->appendNormalizedDomain($domains, (string)$site->getBase());

        foreach ($site->getLanguages() as $language) {
            try {
                $this->appendNormalizedDomain($domains, (string)$language->getBase());
            } catch (\Throwable) {
            }
        }

        return $domains !== [] ? $domains : [''];
    }

    /**
     * @param list<string> $domains
     */
    private function appendNormalizedDomain(array &$domains, string $siteBase): void
    {
        $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
        if ($domain === '' || in_array($domain, $domains, true)) {
            return;
        }

        $domains[] = $domain;
    }

    public function hasCrawlerForAnySite(): bool
    {
        foreach ($this->siteResolutionService->getAllSites() as $site) {
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
