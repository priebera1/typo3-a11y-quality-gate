<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Pro\Dto\RemoteScanRequestData;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

final class RemoteScanInputResolver
{
    public function __construct(
        private readonly DomainNormalizer $domainNormalizer,
        private readonly RequestFactory $requestFactory,
    ) {
    }

    public function resolveForOverview(
        Site $site,
        int $maxPages,
        string $axeLocale = 'en',
    ): RemoteScanRequestData {
        $siteBase = rtrim((string)$site->getBase(), '/');
        $siteIdentifier = trim((string)$site->getIdentifier());
        $domain = $this->domainNormalizer->normalizeFromSiteBase($siteBase);

        $sitemapUrl = $this->resolveSitemapUrl($siteBase);
        $useSitemap = $sitemapUrl !== null && $sitemapUrl !== '';

        return new RemoteScanRequestData(
            siteIdentifier: $siteIdentifier,
            domain: $domain,
            startUrl: $siteBase . '/',
            sitemapUrl: $sitemapUrl,
            sourceType: $useSitemap
                ? RemoteScanSourceType::Sitemap
                : RemoteScanSourceType::Crawl,
            maxPages: max(1, $maxPages),
            followLinks: !$useSitemap,
            axeLocale: trim($axeLocale) !== '' ? trim($axeLocale) : 'en',
        );
    }

    public function resolveForFreePreview(Site $site, string $pageUrl): RemoteScanRequestData
    {
        return $this->resolveForSinglePage($site, $pageUrl);
    }


    /**
     * @param array{languageId:int,title:string,locale:string,flagIdentifier:string,base:string,sitemapUrl:string,isAll:bool} $language
     */
    public function resolveForOverviewLanguage(
        Site $site,
        array $language,
        int $maxPages,
        string $axeLocale = 'en',
    ): RemoteScanRequestData {
        $siteIdentifier = trim((string)$site->getIdentifier());
        $languageBase = rtrim((string)($language['base'] ?? ''), '/');
        if ($languageBase === '') {
            $languageBase = rtrim((string)$site->getBase(), '/');
        }

        $domain = $this->domainNormalizer->normalizeFromSiteBase($languageBase);
        $sitemapUrl = trim((string)($language['sitemapUrl'] ?? ''));
        if ($sitemapUrl === '') {
            $sitemapUrl = $languageBase . '/sitemap.xml';
        }

        return new RemoteScanRequestData(
            siteIdentifier: $siteIdentifier,
            domain: $domain,
            startUrl: $languageBase . '/',
            sitemapUrl: $sitemapUrl,
            sourceType: RemoteScanSourceType::Sitemap,
            maxPages: max(1, $maxPages),
            followLinks: false,
            axeLocale: trim($axeLocale) !== '' ? trim($axeLocale) : 'en',
        );
    }

    public function resolveForSinglePage(
        Site $site,
        string $pageUrl,
        string $axeLocale = 'en',
    ): RemoteScanRequestData {
        $siteBase = rtrim((string)$site->getBase(), '/');
        $siteIdentifier = trim((string)$site->getIdentifier());
        $domain = $this->domainNormalizer->normalizeFromSiteBase($siteBase);
        $startUrl = $this->resolveTrustedPageUrl($site, trim($pageUrl));

        return new RemoteScanRequestData(
            siteIdentifier: $siteIdentifier,
            domain: $domain,
            startUrl: $startUrl,
            sitemapUrl: null,
            sourceType: RemoteScanSourceType::SinglePage,
            maxPages: 1,
            followLinks: false,
            axeLocale: trim($axeLocale) !== '' ? trim($axeLocale) : 'en',
        );
    }

    private function resolveTrustedPageUrl(Site $site, string $pageUrl): string
    {
        if ($pageUrl === '') {
            throw new \InvalidArgumentException('Missing page URL', 1779360101);
        }

        $pageParts = parse_url($pageUrl);
        if (!is_array($pageParts)) {
            throw new \InvalidArgumentException('Invalid page URL', 1779360102);
        }

        $scheme = strtolower((string)($pageParts['scheme'] ?? ''));
        $host = strtolower((string)($pageParts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \InvalidArgumentException('Page URL must use the configured site domain', 1779360103);
        }

        foreach ($this->getAllowedSiteBaseUrls($site) as $baseUrl) {
            if ($this->isUrlBelowBase($pageUrl, $baseUrl)) {
                return $pageUrl;
            }
        }

        throw new \InvalidArgumentException('Page URL is outside the configured TYPO3 site', 1779360104);
    }

    private function getAllowedSiteBaseUrls(Site $site): array
    {
        $baseUrls = [rtrim((string)$site->getBase(), '/') . '/'];

        foreach ($site->getLanguages() as $language) {
            try {
                $base = trim((string)$language->getBase());
            } catch (\Throwable) {
                $base = '';
            }

            if ($base !== '') {
                $baseUrls[] = rtrim($base, '/') . '/';
            }
        }

        return array_values(array_unique($baseUrls));
    }

    private function isUrlBelowBase(string $url, string $baseUrl): bool
    {
        $urlParts = parse_url($url);
        $baseParts = parse_url($baseUrl);

        if (!is_array($urlParts) || !is_array($baseParts)) {
            return false;
        }

        $urlScheme = strtolower((string)($urlParts['scheme'] ?? ''));
        $baseScheme = strtolower((string)($baseParts['scheme'] ?? ''));
        $urlHost = strtolower((string)($urlParts['host'] ?? ''));
        $baseHost = strtolower((string)($baseParts['host'] ?? ''));
        $urlPort = (int)($urlParts['port'] ?? $this->defaultPortForScheme($urlScheme));
        $basePort = (int)($baseParts['port'] ?? $this->defaultPortForScheme($baseScheme));

        if ($urlScheme !== $baseScheme || $urlHost !== $baseHost || $urlPort !== $basePort) {
            return false;
        }

        $urlPath = '/' . ltrim((string)($urlParts['path'] ?? '/'), '/');
        $basePath = '/' . trim((string)($baseParts['path'] ?? '/'), '/');
        if ($basePath !== '/') {
            $basePath .= '/';
        }

        return $basePath === '/' || str_starts_with($urlPath . '/', $basePath);
    }

    private function defaultPortForScheme(string $scheme): int
    {
        return $scheme === 'http' ? 80 : 443;
    }

    private function resolveSitemapUrl(string $siteBase): ?string
    {
        if ($siteBase === '') {
            return null;
        }

        $rootSitemapUrl = $siteBase . '/sitemap.xml';

        try {
            $response = $this->requestFactory->request($rootSitemapUrl, 'GET', [
                'headers' => [
                    'Accept' => 'application/xml,text/xml;q=0.9,*/*;q=0.8',
                ],
                'timeout' => 10,
                'allow_redirects' => true,
                'http_errors' => false,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                return null;
            }

            $body = (string)$response->getBody();
            if ($body === '') {
                return null;
            }

            $pagesSitemapUrl = $this->extractPagesSitemapUrl($body);
            if ($pagesSitemapUrl !== null && $pagesSitemapUrl !== '') {
                return $pagesSitemapUrl;
            }

            if ($this->isUrlSet($body)) {
                return $rootSitemapUrl;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function extractPagesSitemapUrl(string $xmlContent): ?string
    {
        try {
            $xml = @simplexml_load_string($xmlContent);
            if (!$xml instanceof \SimpleXMLElement) {
                return null;
            }

            $locNodes = $xml->xpath('//*[local-name()="sitemap"]/*[local-name()="loc"]');
            if (!is_array($locNodes)) {
                return null;
            }

            foreach ($locNodes as $locNode) {
                $value = trim((string)$locNode);
                if ($value !== '' && str_contains($value, 'sitemap=pages')) {
                    return $value;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private function isUrlSet(string $xmlContent): bool
    {
        try {
            $xml = @simplexml_load_string($xmlContent);
            if (!$xml instanceof \SimpleXMLElement) {
                return false;
            }

            $urlNodes = $xml->xpath('//*[local-name()="url"]');
            return is_array($urlNodes) && $urlNodes !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}
