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
}
