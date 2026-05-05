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

    public function resolveForSinglePage(
        Site $site,
        string $pageUrl,
        string $axeLocale = 'en',
    ): RemoteScanRequestData {
        $siteBase = rtrim((string)$site->getBase(), '/');
        $siteIdentifier = trim((string)$site->getIdentifier());
        $domain = $this->domainNormalizer->normalizeFromSiteBase($siteBase);

        return new RemoteScanRequestData(
            siteIdentifier: $siteIdentifier,
            domain: $domain,
            startUrl: trim($pageUrl),
            sitemapUrl: null,
            sourceType: RemoteScanSourceType::SinglePage,
            maxPages: 1,
            followLinks: false,
            axeLocale: trim($axeLocale) !== '' ? trim($axeLocale) : 'en',
        );
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