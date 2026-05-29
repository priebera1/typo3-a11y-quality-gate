<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

final class SiteLanguageService
{
    public function __construct(
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array<int, array{languageId:int,title:string,locale:string,flagIdentifier:string,base:string,sitemapUrl:string,isAll:bool}>
     */
    public function getLanguagesForSite(string $siteIdentifier): array
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            return [];
        }

        $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);

        return $site instanceof Site ? $this->getLanguagesForSiteObject($site) : [];
    }

    /**
     * @return array<int, array{languageId:int,title:string,locale:string,flagIdentifier:string,base:string,sitemapUrl:string,isAll:bool}>
     */
    public function getLanguagesForSiteObject(Site $site): array
    {
        $languages = [];

        foreach ($site->getLanguages() as $language) {
            try {
                $base = (string)$language->getBase();
            } catch (\Throwable) {
                $base = (string)$site->getBase();
            }

            $languageId = (int)$language->getLanguageId();
            $title = trim((string)$language->getTitle());
            $locale = '';
            $flagIdentifier = '';

            try {
                $locale = (string)$language->getLocale();
            } catch (\Throwable) {
                $locale = '';
            }

            try {
                $flagIdentifier = (string)$language->getFlagIdentifier();
            } catch (\Throwable) {
                $flagIdentifier = '';
            }

            $languages[] = [
                'languageId' => $languageId,
                'title' => $title !== '' ? $title : ('Language ' . $languageId),
                'locale' => $locale,
                'flagIdentifier' => $flagIdentifier,
                'base' => $base,
                'sitemapUrl' => rtrim($base, '/') . '/sitemap.xml',
                'isAll' => false,
            ];
        }

        usort(
            $languages,
            static fn(array $a, array $b): int => $a['languageId'] <=> $b['languageId']
        );

        return $languages;
    }

    /**
     * @param array<int, array{languageId:int,title:string,locale:string,flagIdentifier:string,base:string,sitemapUrl:string,isAll:bool}> $languages
     * @return array<int, array{languageId:int,title:string,locale:string,flagIdentifier:string,base:string,sitemapUrl:string,isAll:bool}>
     */
    public function filterLanguagesAvailableForPage(int $pageUid, array $languages): array
    {
        if ($pageUid <= 0 || $languages === []) {
            return $languages;
        }

        $defaultPageUid = $this->resolveDefaultLanguagePageUid($pageUid);
        if ($defaultPageUid <= 0) {
            return $languages;
        }

        $availableLanguageUids = [0 => true];
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $qb->getRestrictions()->removeAll();

        $rows = $qb
            ->select('sys_language_uid')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($defaultPageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $languageUid = (int)($row['sys_language_uid'] ?? 0);
            if ($languageUid > 0) {
                $availableLanguageUids[$languageUid] = true;
            }
        }

        return array_values(array_filter(
            $languages,
            static fn(array $language): bool => isset($availableLanguageUids[(int)($language['languageId'] ?? 0)])
        ));
    }

    private function resolveDefaultLanguagePageUid(int $pageUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $qb->getRestrictions()->removeAll();

        $row = $qb
            ->select('uid', 'sys_language_uid', 'l10n_parent')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        if (!is_array($row)) {
            return $pageUid;
        }

        $languageUid = (int)($row['sys_language_uid'] ?? 0);
        $l10nParent = (int)($row['l10n_parent'] ?? 0);

        if ($languageUid > 0 && $l10nParent > 0) {
            return $l10nParent;
        }

        return (int)($row['uid'] ?? $pageUid);
    }

    public function resolveLanguageContext(Site $site, int $languageUid): ?array
    {
        if ($languageUid < 0) {
            return null;
        }

        foreach ($this->getLanguagesForSiteObject($site) as $language) {
            if ((int)$language['languageId'] === $languageUid) {
                return $language;
            }
        }

        return null;
    }

    public function resolveLanguageCode(?array $language): string
    {
        if ($language === null) {
            return '';
        }

        $locale = trim((string)($language['locale'] ?? ''));
        if ($locale === '') {
            return '';
        }

        $parts = preg_split('/[-_]/', $locale);
        $code = is_array($parts) ? trim((string)($parts[0] ?? '')) : '';

        return strtolower($code);
    }

    public function containsLanguage(array $languages, int $languageUid): bool
    {
        if ($languageUid === -1) {
            return true;
        }

        foreach ($languages as $language) {
            if ((int)$language['languageId'] === $languageUid) {
                return true;
            }
        }

        return false;
    }
}
