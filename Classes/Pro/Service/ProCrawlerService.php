<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Pro\Dto\CrawlerResultsResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSummaryResult;
use Priebera\A11yQualityGate\Pro\Enum\FeatureFlag;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;

final class ProCrawlerService
{
    public function __construct(
        private readonly ProLicenceService $proLicenceService,
        private readonly ProTokenService $proTokenService,
        private readonly AqgCrawlerClient $crawlerClient,
    ) {
    }

    public function submit(
        string $domain,
        string $version,
        string $siteId,
        string $startUrl,
        ?string $sitemapUrl,
        RemoteScanSourceType $sourceType,
        int $maxPages = 20,
        bool $followLinks = true,
        string $axeLocale = 'en',
        bool $captureScreenshot = false,
        bool $cookieDismiss = true,
    ): CrawlerSubmitResult {
        $licence = $this->proLicenceService->validate($domain, $version);

        if (!$licence->valid || !$licence->hasFeature(FeatureFlag::Crawler)) {
            throw new TokenRefreshException('Crawler feature is not available for this licence.');
        }

        if ($captureScreenshot && !$licence->hasFeature(FeatureFlag::ScreenshotCapture)) {
            $captureScreenshot = false;
        }

        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            $responseDto = $this->crawlerClient->submit(
                $token->accessToken,
                $siteId,
                $startUrl,
                $sitemapUrl,
                $sourceType,
                $maxPages,
                $followLinks,
                $axeLocale,
                $captureScreenshot,
                $cookieDismiss,
            );
        } catch (ApiRequestFailedException $exception) {
            throw new TokenRefreshException(
                'Remote crawler submit failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!$responseDto->success || $responseDto->jobId === null) {
            throw new TokenRefreshException(
                $responseDto->errorMessage ?? 'Remote crawler submit failed.'
            );
        }

        return CrawlerSubmitResult::fromResponseDto($responseDto);
    }

    public function getResults(string $domain, string $version, string $jobId): CrawlerResultsResult
    {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            $responseDto = $this->crawlerClient->results($token->accessToken, $jobId);
        } catch (ApiRequestFailedException $exception) {
            throw new TokenRefreshException(
                'Remote crawler results request failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!$responseDto->success || $responseDto->jobId === null) {
            throw new TokenRefreshException(
                $responseDto->errorMessage ?? 'Remote crawler results request failed.'
            );
        }

        return CrawlerResultsResult::fromResponseDto($responseDto);
    }

    public function getStatus(string $domain, string $version, string $jobId): CrawlerStatusResult
    {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            $responseDto = $this->crawlerClient->status($token->accessToken, $jobId);
        } catch (ApiRequestFailedException $exception) {
            throw new TokenRefreshException(
                'Remote crawler status request failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!$responseDto->success || $responseDto->jobId === null) {
            throw new TokenRefreshException(
                $responseDto->errorMessage ?? 'Remote crawler status request failed.'
            );
        }

        return CrawlerStatusResult::fromResponseDto($responseDto);
    }

    public function getSummary(string $domain, string $version, string $jobId): CrawlerSummaryResult
    {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            $responseDto = $this->crawlerClient->summary($token->accessToken, $jobId);
        } catch (ApiRequestFailedException $exception) {
            throw new TokenRefreshException(
                'Remote crawler summary request failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!$responseDto->success || $responseDto->jobId === null) {
            throw new TokenRefreshException(
                $responseDto->errorMessage ?? 'Remote crawler summary request failed.'
            );
        }

        return CrawlerSummaryResult::fromResponseDto($responseDto);
    }
}