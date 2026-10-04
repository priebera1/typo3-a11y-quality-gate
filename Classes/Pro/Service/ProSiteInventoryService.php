<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Every configured TYPO3 site with all its hosts — the base and every language base — read from the Site
 * Configuration. The installation reports it with every licensed request (`siteInventory`): the AQG service lets
 * a domain be activated, and issues scanner tokens, only for hosts the verified installation reports here. It is
 * never built from request input, and it is reported complete (development hosts included); the service decides
 * what is licensable.
 *
 * `allSites` (ProSiteFingerprintService) stays the project identity that released versions send; this inventory
 * adds the language hosts and the site each host belongs to.
 */
final class ProSiteInventoryService
{
    private const MAX_SITES = 500;
    private const MAX_HOSTS_PER_SITE = 50;

    public function __construct(
        private readonly SiteResolutionService $siteResolutionService,
        private readonly DomainNormalizer $domainNormalizer,
    ) {
    }

    /**
     * @return list<array{site: string, domains: list<string>}>
     */
    public function collect(): array
    {
        $inventory = [];

        foreach ($this->siteResolutionService->getAllSites() as $site) {
            if (!$site instanceof Site || count($inventory) >= self::MAX_SITES) {
                continue;
            }

            $hosts = [];
            $this->addHost($hosts, (string)$site->getBase());
            foreach ($site->getAllLanguages() as $language) {
                try {
                    $this->addHost($hosts, (string)$language->getBase());
                } catch (\Throwable) {
                }
            }

            if ($hosts === []) {
                continue;
            }

            $inventory[] = [
                'site' => mb_substr($site->getIdentifier(), 0, 255),
                'domains' => array_slice($hosts, 0, self::MAX_HOSTS_PER_SITE),
            ];
        }

        return $inventory;
    }

    /**
     * @param list<string> $hosts
     */
    private function addHost(array &$hosts, string $base): void
    {
        $host = $this->domainNormalizer->normalizeFromSiteBase($base);
        if ($host !== '' && !in_array($host, $hosts, true)) {
            $hosts[] = $host;
        }
    }
}
