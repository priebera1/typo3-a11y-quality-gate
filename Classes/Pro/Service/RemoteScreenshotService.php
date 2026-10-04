<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Core\Http\RequestFactory;

final class RemoteScreenshotService
{
    public function __construct(
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ProTokenService $proTokenService,
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionContextService $extensionContextService,
    ) {
    }

    /**
     * @return array{content:string,contentType:string,filename:string}|null
     *
     * @throws TokenRefreshException
     */
    public function fetchScreenshotByRemotePageUid(int $remotePageUid): ?array
    {
        if ($remotePageUid <= 0) {
            return null;
        }

        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
        if (!is_array($remotePage)) {
            return null;
        }

        $externalPageId = trim((string)($remotePage['external_page_id'] ?? ''));
        if ($externalPageId === '') {
            return null;
        }

        $remoteScanUid = (int)($remotePage['remote_scan'] ?? 0);
        $remoteScan = $remoteScanUid > 0
            ? $this->remoteScanRepository->findScanByUid($remoteScanUid)
            : null;

        if (!is_array($remoteScan)) {
            return null;
        }

        $siteIdentifier = trim((string)($remoteScan['site_identifier'] ?? ''));
        if ($siteIdentifier === '') {
            return null;
        }

        $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
        if ($site === null) {
            return null;
        }

        $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase());
        $version = $this->extensionContextService->getExtensionVersion();

        $token = $this->proTokenService->getValidToken($domain, $version);

        $crawlerBaseUrl = rtrim(ProSettings::resolveCrawlerBaseUrl(), '/');
        $url = $crawlerBaseUrl . '/crawl/page/' . rawurlencode($externalPageId) . '/screenshot';

        $apiResponse = $this->requestFactory->request($url, 'GET', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token->accessToken,
                'Accept' => 'image/png,image/*,*/*',
            ],
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => 20,
        ]);

        if ($apiResponse->getStatusCode() >= 400) {
            return null;
        }

        $contentType = strtolower(trim(explode(';', $apiResponse->getHeaderLine('Content-Type'))[0]));
        if ($contentType === '') {
            $contentType = 'image/png';
        }

        // Served inline from the backend origin: raster images only. An SVG is a document that can run script.
        if (!in_array($contentType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }

        $content = (string)$apiResponse->getBody();
        if ($content === '') {
            return null;
        }

        return [
            'content' => $content,
            'contentType' => $contentType,
            'filename' => 'aqg-screenshot-' . $remotePageUid . '.png',
        ];
    }
}
