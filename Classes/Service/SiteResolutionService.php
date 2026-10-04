<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

final class SiteResolutionService implements SiteResolutionServiceInterface
{
    public const PAGE_CONTEXT_NO_PAGE = 'no_page';
    public const PAGE_CONTEXT_NOT_FOUND = 'page_not_found';
    public const PAGE_CONTEXT_OUTSIDE_SITE = 'outside_site';
    public const PAGE_CONTEXT_IN_SITE = 'in_site';

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly ?ConnectionPool $connectionPool = null,
    ) {
    }

    /**
     * Why a page id has no site context. TYPO3 answers "no site" alike for a page outside every Site Configuration
     * and for an id that names no page (deleted, or a stale link), but only the first one is fixed by creating a
     * Site Configuration, so the Overview explains them differently.
     */
    public function describePageSiteContext(int $pageUid): string
    {
        if ($pageUid <= 0) {
            return self::PAGE_CONTEXT_NO_PAGE;
        }

        if ($this->resolveSiteByPageId($pageUid) instanceof Site) {
            return self::PAGE_CONTEXT_IN_SITE;
        }

        return $this->pageExists($pageUid) ? self::PAGE_CONTEXT_OUTSIDE_SITE : self::PAGE_CONTEXT_NOT_FOUND;
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
        $siteIdentifier = trim((string)($queryParams['site'] ?? $queryParams['siteIdentifier'] ?? ''));

        if ($pageUid <= 0) {
            $pageUid = (int)($queryParams['pageUid'] ?? $queryParams['id'] ?? 0);
        }

        $siteByPage = $this->resolveSiteByPageId($pageUid);

        // Backend module links can carry both an id/pageUid and an explicit site
        // context. For multi-site setups, especially nested site roots, the
        // explicit site context is the authoritative context for site-wide actions
        // and history rendering. The selected page id may be a stale tree context
        // or may resolve to a parent site in TYPO3's rootline lookup. Returning the
        // explicit site here keeps siteIdentifier, siteRootPid and site base URL in
        // one consistent context.
        if ($siteIdentifier !== '') {
            $explicitSite = $this->resolveSiteByIdentifier($siteIdentifier);
            if ($explicitSite instanceof Site) {
                return $explicitSite;
            }
        }

        return $siteByPage;
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

    /**
     * A hidden or access-restricted page is still a page of the tree; only a deleted or missing record is not.
     */
    private function pageExists(int $pageUid): bool
    {
        if (!$this->connectionPool instanceof ConnectionPool) {
            return false;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return (int)$queryBuilder
            ->count('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne() > 0;
    }
}
