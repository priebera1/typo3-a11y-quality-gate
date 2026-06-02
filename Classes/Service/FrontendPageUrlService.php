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
                    ? $this->resolveDefaultPageUidForTranslatedSlug($candidateSlug, $candidateLanguageUid)
                    : $this->resolveDefaultPageUidForSlug($candidateSlug);

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
                    )
                )
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

    private function resolveDefaultPageUidForSlug(string $slug): int
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $row = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq(
                        'slug',
                        $queryBuilder->createNamedParameter($slug)
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->eq(
                        'deleted',
                        $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                    )
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            return is_array($row) ? (int)($row['uid'] ?? 0) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function resolveDefaultPageUidForTranslatedSlug(string $slug, int $languageUid): int
    {
        try {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $row = $queryBuilder
                ->select('uid', 'l10n_parent')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq(
                        'slug',
                        $queryBuilder->createNamedParameter($slug)
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)
                    ),
                    $queryBuilder->expr()->eq(
                        'deleted',
                        $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                    )
                )
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if (!is_array($row)) {
                return 0;
            }

            $parent = (int)($row['l10n_parent'] ?? 0);
            return $parent > 0 ? $parent : (int)($row['uid'] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }
}
