<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Site\Entity\Site;

final class SiteLanguageService
{
    public function __construct(
        private readonly SiteResolutionService $siteResolutionService,
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
