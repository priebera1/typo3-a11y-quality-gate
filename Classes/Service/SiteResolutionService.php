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

    public function resolveSiteByIdentifier(string $siteIdentifier): ?Site
    {
        $siteIdentifier = trim($siteIdentifier);

        if ($siteIdentifier === '') {
            return null;
        }

        try {
            return $this->siteFinder->getSiteByIdentifier($siteIdentifier);
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveSiteFromBackendRequest(ServerRequestInterface $request): ?Site
    {
        return $this->resolveSiteForBackendRequest($request);
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

        return null;
    }

    public function resolveSiteIdentifierForBackendRequest(
        ServerRequestInterface $request,
        ?int $pageUid = null,
    ): string {
        $site = $this->resolveSiteForBackendRequest($request, $pageUid ?? 0);

        return $site?->getIdentifier() ?? '';
    }
}