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
        $site = $this->resolveSiteByPageId($pageUid);
        if ($site instanceof Site) {
            return $site;
        }

        throw new \RuntimeException(
            sprintf(
                'Cannot resolve site for page UID %d. Make sure the page is part of a configured TYPO3 site.',
                $pageUid
            ),
            1700000001
        );
    }

    public function resolveSiteByPageId(int $pageUid): ?Site
    {
        if ($pageUid <= 0) {
            return null;
        }

        try {
            return $this->siteFinder->getSiteByPageId($pageUid);
        } catch (\Throwable) {
            return null;
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

    public function resolveSiteIdentifierForPageId(int $pageUid, string $fallback = ''): string
    {
        $site = $this->resolveSiteByPageId($pageUid);

        return $site instanceof Site ? $site->getIdentifier() : $fallback;
    }

    public function getAllSites(): array
    {
        try {
            return $this->siteFinder->getAllSites();
        } catch (\Throwable) {
            return [];
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
        $queryParams = $request->getQueryParams();

        if ($pageUid <= 0) {
            $pageUid = (int)($queryParams['pageUid'] ?? $queryParams['id'] ?? 0);
        }

        $site = $this->resolveSiteByPageId($pageUid);
        if ($site instanceof Site) {
            return $site;
        }

        $siteIdentifier = trim((string)($queryParams['site'] ?? $queryParams['siteIdentifier'] ?? ''));

        return $this->resolveSiteByIdentifier($siteIdentifier);
    }

    public function resolveSiteBaseByIdentifier(string $siteIdentifier): string
    {
        $site = $this->resolveSiteByIdentifier($siteIdentifier);

        return $site instanceof Site ? trim((string)$site->getBase()) : '';
    }

    public function resolveSiteIdentifierForBackendRequest(
        ServerRequestInterface $request,
        ?int $pageUid = null,
    ): string {
        $site = $this->resolveSiteForBackendRequest($request, $pageUid ?? 0);

        return $site?->getIdentifier() ?? '';
    }
}
