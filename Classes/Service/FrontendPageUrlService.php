<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

final class FrontendPageUrlService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function resolveForPage(Site $site, int $pageUid, int $languageUid): string
    {
        if ($pageUid <= 0 || $languageUid < 0) {
            return '';
        }

        if ($languageUid === 0) {
            $previewUrl = $this->resolvePreviewUrl($pageUid);
            if ($previewUrl !== '') {
                return $previewUrl;
            }
        }

        return $this->resolvePublicForPage($site, $pageUid, $languageUid);
    }

    public function resolvePublicForPage(Site $site, int $pageUid, int $languageUid): string
    {
        if ($pageUid <= 0 || $languageUid < 0) {
            return '';
        }

        $base = $this->resolveLanguageBase($site, $languageUid);
        if ($base === '') {
            return '';
        }

        $slug = $this->resolvePageSlug($pageUid, $languageUid);
        if ($slug === null) {
            return '';
        }

        $base = rtrim($base, '/');
        if ($slug === '' || $slug === '/') {
            return $base . '/';
        }

        return $base . '/' . ltrim($slug, '/');
    }


    public function resolvePageUidForUrl(Site $site, string $url, int $languageUid = -1): int
    {
        $slug = $this->normalizeUrlToPageSlug($site, $url, $languageUid);
        if ($slug === null) {
            return 0;
        }

        if ($slug === '/' || $slug === '') {
            try {
                return (int)$site->getRootPageId();
            } catch (\Throwable) {
                return 0;
            }
        }

        $languageIds = [];
        if ($languageUid >= 0) {
            $languageIds[] = $languageUid;
        } else {
            try {
                foreach ($site->getLanguages() as $language) {
                    $languageIds[] = (int)$language->getLanguageId();
                }
            } catch (\Throwable) {
                $languageIds[] = 0;
            }
        }

        $languageIds = array_values(array_unique(array_merge($languageIds, [0])));
        $slugVariants = array_values(array_unique([
            $slug,
            rtrim($slug, '/'),
            '/' . ltrim($slug, '/'),
        ]));

        foreach ($languageIds as $candidateLanguageUid) {
            foreach ($slugVariants as $candidateSlug) {
                $pageUid = $candidateLanguageUid > 0
                    ? $this->resolveDefaultPageUidForTranslatedSlug($site, $candidateSlug, $candidateLanguageUid)
                    : $this->resolveDefaultPageUidForSlug($site, $candidateSlug);

                if ($pageUid > 0) {
                    return $pageUid;
                }
            }
        }

        return 0;
    }

    private function resolvePreviewUrl(int $pageUid): string
    {
        try {
            $previewUri = PreviewUriBuilder::create($pageUid)->buildUri();
            if ($previewUri !== null) {
                return trim((string)$previewUri);
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function resolveLanguageBase(Site $site, int $languageUid): string
    {
        try {
            $language = $site->getLanguageById($languageUid);
            $base = trim((string)$language->getBase());
            if ($base !== '') {
                return $base;
            }
        } catch (\Throwable) {
        }

        return trim((string)$site->getBase());
    }

    private function resolvePageSlug(int $pageUid, int $languageUid): ?string
    {
        $defaultSlug = $this->resolveDefaultPageSlug($pageUid);
        if ($languageUid <= 0) {
            return $defaultSlug;
        }

        $translatedSlug = $this->resolveTranslatedPageSlug($pageUid, $languageUid);
        if ($translatedSlug !== null && $translatedSlug !== '') {
            return $translatedSlug;
        }

        return $defaultSlug;
    }

    private function resolveDefaultPageSlug(int $pageUid): ?string
    {
        $page = BackendUtility::getRecord('pages', $pageUid, 'slug');

        return is_array($page) ? trim((string)($page['slug'] ?? '')) : null;
    }

    private function resolveTranslatedPageSlug(int $pageUid, int $languageUid): ?string
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $row = $queryBuilder
                ->select('slug')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq(
                        'l10n_parent',
                        $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->eq(
                        'deleted',
                        $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                    ),
                    // The live translation, in the order ScopeAccessService::liveTranslationUid() uses for scan admission
                    // (P1-02): a workspace version is not what visitors (or the crawler) see, and must not become the URL.
                    $queryBuilder->expr()->eq(
                        't3ver_wsid',
                        $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                    )
                )
                ->orderBy('uid')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            return is_array($row) ? trim((string)($row['slug'] ?? '')) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeUrlToPageSlug(Site $site, string $url, int $languageUid): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $path = (string)($parts['path'] ?? '');
        $path = '/' . ltrim(rawurldecode($path), '/');

        $basePath = '';
        try {
            $base = $languageUid >= 0 ? $this->resolveLanguageBase($site, $languageUid) : (string)$site->getBase();
            $baseParts = parse_url($base);
            if (is_array($baseParts)) {
                $basePath = '/' . trim((string)($baseParts['path'] ?? ''), '/');
                if ($basePath === '/') {
                    $basePath = '';
                }
            }
        } catch (\Throwable) {
            $basePath = '';
        }

        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath));
        } elseif ($basePath !== '' && $path === $basePath) {
            $path = '/';
        }

        $path = '/' . ltrim($path, '/');
        $path = preg_replace('#/+#', '/', $path);
        $path = is_string($path) ? $path : '/';

        return $path === '' ? '/' : $path;
    }

    /**
     * The default-language page of the given site with this slug. Slugs are unique per site only, so a page of another
     * site with the same slug never matches. Hidden and scheduled live pages count (scans of them exist); workspace
     * versions do not.
     */
    private function resolveDefaultPageUidForSlug(Site $site, string $slug): int
    {
        $candidates = array_map(static fn (array $row): int => (int)$row['uid'], $this->findLivePagesBySlug($slug, 0));
        $ofSite = $this->pagesOfSite($site, $candidates);
        foreach ($candidates as $pageUid) {
            if ($ofSite[$pageUid] ?? false) {
                return $pageUid;
            }
        }

        return 0;
    }

    private function resolveDefaultPageUidForTranslatedSlug(Site $site, string $slug, int $languageUid): int
    {
        $candidates = [];
        foreach ($this->findLivePagesBySlug($slug, $languageUid) as $row) {
            $parent = (int)($row['l10n_parent'] ?? 0);
            $candidates[] = $parent > 0 ? $parent : (int)$row['uid'];
        }
        // Large-installation fix (LOW-1): slugs repeat across the sites of a multi-site installation; the candidates'
        // rootlines are followed together, one query per tree level, instead of one rootline per candidate.
        $ofSite = $this->pagesOfSite($site, $candidates);
        foreach ($candidates as $pageUid) {
            if ($ofSite[$pageUid] ?? false) {
                return $pageUid;
            }
        }

        return 0;
    }

    /**
     * isPageOfSite() for many pages: whether the site's root page is on the page's rootline (the page itself included).
     * The rootline follows pid through pages that are not deleted, as BackendUtility::BEgetRootLine() does; a missing
     * or deleted page ends it.
     *
     * @param list<int> $pageUids
     * @return array<int, bool>
     */
    private function pagesOfSite(Site $site, array $pageUids): array
    {
        $rootPageId = (int)$site->getRootPageId();
        $pageUids = array_values(array_unique(array_filter($pageUids, static fn (int $uid): bool => $uid > 0)));
        if ($pageUids === [] || $rootPageId <= 0) {
            return [];
        }
        $parent = [];
        $frontier = $pageUids;
        for ($depth = 0; $depth < 100 && $frontier !== []; $depth++) {
            $rows = [];
            try {
                foreach (array_chunk($frontier, 500) as $chunk) {
                    $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
                    $queryBuilder->getRestrictions()->removeAll();
                    foreach ($queryBuilder->select('uid', 'pid')
                        ->from('pages')
                        ->where(
                            $queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                            $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                        )
                        ->executeQuery()
                        ->fetchAllAssociative() as $row) {
                        $rows[(int)$row['uid']] = (int)$row['pid'];
                    }
                }
            } catch (\Throwable) {
                return [];
            }
            $next = [];
            foreach ($frontier as $uid) {
                $parent[$uid] = $rows[$uid] ?? false;
                $pid = $parent[$uid];
                if ($pid !== false && $pid > 0 && $uid !== $rootPageId && !array_key_exists($pid, $parent)) {
                    $next[$pid] = $pid;
                }
            }
            $frontier = array_values($next);
        }
        $result = [];
        foreach ($pageUids as $uid) {
            $current = $uid;
            $result[$uid] = false;
            for ($steps = 0; $steps < 100 && $current > 0; $steps++) {
                if (($parent[$current] ?? false) === false) {
                    break;
                }
                if ($current === $rootPageId) {
                    $result[$uid] = true;
                    break;
                }
                $current = (int)$parent[$current];
            }
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function findLivePagesBySlug(string $slug, int $languageUid): array
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll();

            return $queryBuilder
                ->select('uid', 'l10n_parent')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('slug', $queryBuilder->createNamedParameter($slug)),
                    $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        } catch (\Throwable) {
            return [];
        }
    }
}
