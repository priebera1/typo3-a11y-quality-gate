<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class SiteResolutionService
{
    public function __construct(
        private readonly SiteFinder $siteFinder,
    ) {
    }

    public function resolveSiteIdentifierFromPageId(int $pageUid): string
    {
        return $this->resolveSiteFromPageId($pageUid)->getIdentifier();
    }

    public function resolveSiteFromPageId(int $pageUid): Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageUid);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                sprintf(
                    'Cannot resolve site for page UID %d. Make sure the page is part of a configured TYPO3 site. Original error: %s',
                    $pageUid,
                    $e->getMessage()
                ),
                1700000001,
                $e
            );
        }
    }

    public function resolveSiteForBackendRequest(
        ServerRequestInterface $request,
        int $pageUid = 0,
    ): ?Site {
        if ($pageUid > 0) {
            try {
                return $this->siteFinder->getSiteByPageId($pageUid);
            } catch (\Throwable) {
            }
        }

        $siteIdentifier = trim((string)($request->getQueryParams()['site'] ?? ''));
        if ($siteIdentifier !== '') {
            try {
                return $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            } catch (\Throwable) {
            }
        }

        $sites = $this->siteFinder->getAllSites();

        return $sites !== [] ? reset($sites) : null;
    }
}
