<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RemoteScreenshotState;
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
    /**
     * @param string $variant 'original' asks for the crawler's untouched capture (screenshot evidence); anything else
     *                        is the default request released extensions make, the annotated image
     */
    public function fetchScreenshotByRemotePageUid(int $remotePageUid, string $variant = 'highlighted'): ?array
    {
        $result = $this->fetchScreenshotOrState($remotePageUid, $variant);

        return isset($result['content']) ? $result : null;
    }

    /**
     * The screenshot, or why there is none: `['state' => …]` with a RemoteScreenshotState value. `expired` / `deleted`
     * only when the AQG service said so with its retention/deletion code; every other refusal or failure is
     * `unavailable` (never presented as a retention deletion).
     *
     * @return array{content:string,contentType:string,filename:string}|array{state:string}
     *
     * @throws TokenRefreshException
     */
    public function fetchScreenshotOrState(int $remotePageUid, string $variant = 'highlighted'): array
    {
        $unavailable = ['state' => RemoteScreenshotState::UNAVAILABLE];
        $original = $variant === 'original';
        if ($remotePageUid <= 0) {
            return $unavailable;
        }

        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
        if (!is_array($remotePage)) {
            return $unavailable;
        }

        $externalPageId = trim((string)($remotePage['external_page_id'] ?? ''));
        if ($externalPageId === '') {
            return $unavailable;
        }

        $remoteScanUid = (int)($remotePage['remote_scan'] ?? 0);
        $remoteScan = $remoteScanUid > 0
            ? $this->remoteScanRepository->findScanByUid($remoteScanUid)
            : null;

        if (!is_array($remoteScan)) {
            return $unavailable;
        }

        $siteIdentifier = trim((string)($remoteScan['site_identifier'] ?? ''));
        if ($siteIdentifier === '') {
            return $unavailable;
        }

        $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
        if ($site === null) {
            return $unavailable;
        }

        $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase());
        $version = $this->extensionContextService->getExtensionVersion();

        $token = $this->proTokenService->getValidToken($domain, $version);

        $crawlerBaseUrl = rtrim(ProSettings::resolveCrawlerBaseUrl(), '/');
        $url = $crawlerBaseUrl . '/crawl/page/' . rawurlencode($externalPageId) . '/screenshot' . ($original ? '?variant=original' : '');

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
            $decoded = json_decode((string)$apiResponse->getBody(), true);
            $errorCode = is_array($decoded) && is_array($decoded['error'] ?? null) && is_string($decoded['error']['code'] ?? null)
                ? $decoded['error']['code']
                : '';

            return ['state' => RemoteScreenshotState::fromServiceError($apiResponse->getStatusCode(), $errorCode)];
        }

        $contentType = strtolower(trim(explode(';', $apiResponse->getHeaderLine('Content-Type'))[0]));
        if ($contentType === '') {
            $contentType = 'image/png';
        }

        // Served inline from the backend origin: raster images only. An SVG is a document that can run script.
        if (!in_array($contentType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return $unavailable;
        }

        $content = (string)$apiResponse->getBody();
        if ($content === '') {
            return $unavailable;
        }

        return [
            'content' => $content,
            'contentType' => $contentType,
            'filename' => 'aqg-screenshot-' . $remotePageUid . ($original ? '-original' : '') . '.png',
        ];
    }
}
