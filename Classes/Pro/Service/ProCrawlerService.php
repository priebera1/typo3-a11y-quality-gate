<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Pro\Dto\CrawlerResultsResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSummaryResult;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
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
        string $scannerPreviewToken = '',
        string $httpAuthUser = '',
        string $httpAuthPass = '',
        array $excludedPatterns = [],
        array $priorityUrls = [],
        array $cookieSelectors = [],
        ?int $languageId = null,
        string $languageCode = '',
    ): CrawlerSubmitResult {
        $licence = $this->proLicenceService->validate($domain, $version);

        if (!$this->hasCrawlerCapability($licence)) {
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
                $scannerPreviewToken,
                $httpAuthUser,
                $httpAuthPass,
                $excludedPatterns,
                $priorityUrls,
                $cookieSelectors,
                $languageId,
                $languageCode,
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


    private function hasCrawlerCapability(LicenceValidationResult $licence): bool
    {
        if (!$licence->valid) {
            return false;
        }

        if ($licence->hasFeature(FeatureFlag::Crawler)) {
            return true;
        }

        $plan = strtolower(trim($licence->isTrial ? 'trial' : $licence->plan));

        return in_array($plan, ['trial', 'pro', 'agency', 'enterprise'], true);
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
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler summary request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                $responseDto = $this->crawlerClient->summary($token->accessToken, $jobId);
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler summary request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }

        if (!$responseDto->success || $responseDto->jobId === null) {
            throw new TokenRefreshException(
                $responseDto->errorMessage ?? 'Remote crawler summary request failed.'
            );
        }

        return CrawlerSummaryResult::fromResponseDto($responseDto);
    }

    private function isTokenExpiredCrawlerException(ApiRequestFailedException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, '401')
            || str_contains($message, 'token_expired')
            || str_contains($message, 'expired token')
            || str_contains($message, 'invalid token');
    }


    /**
     * @return array<string, mixed>
     */
    public function getHistory(
        string $domain,
        string $version,
        string $siteId,
        int $limit = 10,
        string $sourceType = '',
        string $startUrl = '',
        string $status = ''
    ): array {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->history(
                $token->accessToken,
                $siteId,
                $limit,
                $sourceType,
                $startUrl,
                $status
            );
        } catch (ApiRequestFailedException $exception) {
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler history request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                return $this->crawlerClient->history(
                    $token->accessToken,
                    $siteId,
                    $limit,
                    $sourceType,
                    $startUrl,
                    $status
                );
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler history request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getLatest(string $domain, string $version, string $siteId): array
    {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->latest($token->accessToken, $siteId);
        } catch (ApiRequestFailedException $exception) {
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler latest request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                return $this->crawlerClient->latest($token->accessToken, $siteId);
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler latest request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function compareScans(string $domain, string $version, string $fromJobId, string $toJobId): array
    {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->compare($token->accessToken, $fromJobId, $toJobId);
        } catch (ApiRequestFailedException $exception) {
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler compare request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                return $this->crawlerClient->compare($token->accessToken, $fromJobId, $toJobId);
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler compare request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getRegressionAlert(
        string $domain,
        string $version,
        string $siteId,
        string $sourceType,
        string $startUrl = '',
        string $language = 'en'
    ): array {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->regressionAlert($token->accessToken, $siteId, $sourceType, $startUrl);
        } catch (ApiRequestFailedException $exception) {
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler regression alert request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                return $this->crawlerClient->regressionAlert($token->accessToken, $siteId, $sourceType, $startUrl);
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler regression alert request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getRemediationPlan(
        string $domain,
        string $version,
        string $jobId,
        string $language = 'en'
    ): array {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->remediationPlan($token->accessToken, $jobId);
        } catch (ApiRequestFailedException $exception) {
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler remediation plan request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                return $this->crawlerClient->remediationPlan($token->accessToken, $jobId);
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler remediation plan request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getLatestRemediationPlan(
        string $domain,
        string $version,
        string $siteId,
        string $sourceType,
        ?string $startUrl = null
    ): array {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->latestRemediationPlan($token->accessToken, $siteId, $sourceType, $startUrl);
        } catch (ApiRequestFailedException $exception) {
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler latest remediation plan request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                return $this->crawlerClient->latestRemediationPlan($token->accessToken, $siteId, $sourceType, $startUrl);
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler latest remediation plan request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getProgressSummary(string $domain, string $version, string $siteId): array
    {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->progressSummary($token->accessToken, $siteId);
        } catch (ApiRequestFailedException $exception) {
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler progress summary request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                return $this->crawlerClient->progressSummary($token->accessToken, $siteId);
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler progress summary request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }
    }


    /**
     * @return array<string, mixed>
     */
    public function getAccessibilityStatement(
        string $domain,
        string $version,
        string $jobId,
        string $language = 'en'
    ): array {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->accessibilityStatement($token->accessToken, $jobId, $language);
        } catch (ApiRequestFailedException $exception) {
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler accessibility statement request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                return $this->crawlerClient->accessibilityStatement($token->accessToken, $jobId, $language);
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler accessibility statement request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getLatestAccessibilityStatement(
        string $domain,
        string $version,
        string $siteId,
        string $sourceType,
        string $startUrl = '',
        string $language = 'en'
    ): array {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->latestAccessibilityStatement($token->accessToken, $siteId, $sourceType, $startUrl, $language);
        } catch (ApiRequestFailedException $exception) {
            if (!$this->isTokenExpiredCrawlerException($exception)) {
                throw new TokenRefreshException(
                    'Remote crawler latest accessibility statement request failed: ' . $exception->getMessage(),
                    0,
                    $exception
                );
            }

            try {
                $token = $this->proTokenService->getValidToken($domain, $version, true);
                return $this->crawlerClient->latestAccessibilityStatement($token->accessToken, $siteId, $sourceType, $startUrl, $language);
            } catch (ApiRequestFailedException $retryException) {
                throw new TokenRefreshException(
                    'Remote crawler latest accessibility statement request failed after token refresh: ' . $retryException->getMessage(),
                    0,
                    $retryException
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $domain, string $version, string $jobId): array
    {
        $licence = $this->proLicenceService->validate($domain, $version);

        if (!$this->hasCrawlerCapability($licence)) {
            throw new TokenRefreshException('Crawler feature is not available for this licence.');
        }

        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->cancel($token->accessToken, $jobId);
        } catch (ApiRequestFailedException $exception) {
            throw new TokenRefreshException(
                'Remote crawler cancel request failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }
    }
}
