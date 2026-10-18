<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Pro\Dto\CrawlerResultsResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSummaryResult;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Enum\FeatureFlag;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;
use Priebera\A11yQualityGate\Utility\StringListUtility;

final class ProCrawlerService
{
    private const DOMAIN_REASONS = ['domain_limit_reached', 'domain_not_activated', 'domain_not_detected'];

    public function __construct(
        private readonly ProLicenceService $proLicenceService,
        private readonly ProTokenService $proTokenService,
        private readonly AqgCrawlerClient $crawlerClient,
        private readonly ?TrialUsageService $trialUsageService = null,
        private readonly ?PaidSubmitIdempotencyStore $submitKeys = null,
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
        string $purpose = '',
    ): CrawlerSubmitResult {
        $licence = $this->proLicenceService->validate($domain, $version);

        if (!$this->hasCrawlerCapability($licence)) {
            throw $this->crawlerUnavailable($licence);
        }

        if ($captureScreenshot && !$licence->hasFeature(FeatureFlag::ScreenshotCapture)) {
            $captureScreenshot = false;
        }

        // Kept until the crawler answers, so a repeated submit of this request picks up the scan a timed-out attempt
        // may have started (O1) — and the token-refresh retry below is never a second scan either.
        $fingerprint = $this->submitFingerprint(
            $domain, $siteId, $startUrl, $sitemapUrl, $sourceType, $maxPages, $followLinks, $axeLocale, $captureScreenshot,
            $cookieDismiss, $scannerPreviewToken !== '', $httpAuthUser !== '' && $httpAuthPass !== '', $excludedPatterns,
            $priorityUrls, $cookieSelectors, $languageId, $languageCode, $purpose,
        );
        $idempotencyKey = $this->submitKeys?->keyFor($fingerprint) ?? PaidSubmitIdempotencyStore::newKey();
        // The crawler's own failure, as opposed to one of the licence API while fetching a token.
        $crawlerFailure = null;

        try {
            $responseDto = $this->withCurrentToken($domain, $version, 'submit', function (string $accessToken) use (
                $siteId, $startUrl, $sitemapUrl, $sourceType, $maxPages, $followLinks, $axeLocale, $captureScreenshot,
                $cookieDismiss, $scannerPreviewToken, $httpAuthUser, $httpAuthPass, $excludedPatterns, $priorityUrls,
                $cookieSelectors, $languageId, $languageCode, $idempotencyKey, &$crawlerFailure,
            ): CrawlerSubmitResponseDto {
                try {
                    return $this->crawlerClient->submit(
                        $accessToken,
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
                        $idempotencyKey,
                    );
                } catch (ApiRequestFailedException $exception) {
                    $crawlerFailure = $exception;
                    throw $exception;
                }
            });
        } catch (TokenRefreshException $exception) {
            if ($exception->getPrevious() === $crawlerFailure && $this->isUnconfirmedSubmit($crawlerFailure)) {
                $this->submitKeys?->markUnconfirmed($fingerprint, $idempotencyKey);
                throw new TokenRefreshException(
                    'Remote crawler submit not confirmed (the scan may have been accepted): ' . $exception->getMessage(),
                    0,
                    new ApiRequestFailedException('The crawler did not answer the submit.', 503, $exception, 'submit_unconfirmed'),
                );
            }
            $this->submitKeys?->release($fingerprint, $idempotencyKey, false);
            throw $exception;
        } finally {
            // Accepted, refused at the Trial limit or lost in transit: the shown allowance may be out of date now.
            $this->trialUsageService?->forget($domain);
        }

        $this->submitKeys?->release($fingerprint, $idempotencyKey);

        if (!$responseDto->success || $responseDto->jobId === null) {
            throw new TokenRefreshException(
                $responseDto->errorMessage ?? 'Remote crawler submit failed.'
            );
        }

        return CrawlerSubmitResult::fromResponseDto($responseDto);
    }


    /**
     * No answer, or a server error, after the request may have reached the crawler: a timeout, a dropped connection,
     * an unreadable body, 408 or 5xx. A request this PHP process never sent (the crawler did not answer earlier, code
     * `transport_error`) and every 4xx are definitive.
     */
    private function isUnconfirmedSubmit(?ApiRequestFailedException $exception): bool
    {
        if (!$exception instanceof ApiRequestFailedException || $exception->apiErrorCode === 'transport_error') {
            return false;
        }

        return $exception->httpStatus === 0 || $exception->httpStatus === 408 || $exception->httpStatus >= 500;
    }

    /**
     * What decides which scan runs, without the secrets themselves (only whether a scanner token or HTTP credentials
     * are sent): the same request gets the same key, a changed setting is a new scan.
     *
     * @param list<string>|array<int|string, string> $excludedPatterns
     * @param list<string>|array<int|string, string> $priorityUrls
     * @param list<string>|array<int|string, string> $cookieSelectors
     */
    private function submitFingerprint(
        string $domain,
        string $siteId,
        string $startUrl,
        ?string $sitemapUrl,
        RemoteScanSourceType $sourceType,
        int $maxPages,
        bool $followLinks,
        string $axeLocale,
        bool $captureScreenshot,
        bool $cookieDismiss,
        bool $withScannerToken,
        bool $withHttpAuth,
        array $excludedPatterns,
        array $priorityUrls,
        array $cookieSelectors,
        ?int $languageId,
        string $languageCode,
        string $purpose,
    ): string {
        return hash('sha256', json_encode([
            'v' => 1,
            // Verify fix scans the page as it is now: it never picks up a page scan started before the fix.
            'purpose' => $purpose,
            'domain' => $domain,
            'siteId' => $siteId,
            'startUrl' => $startUrl,
            'sitemapUrl' => trim((string)$sitemapUrl),
            'sourceType' => $sourceType->value,
            'maxPages' => $maxPages,
            'followLinks' => $followLinks,
            'axeLocale' => $axeLocale,
            'captureScreenshot' => $captureScreenshot,
            'cookieDismiss' => $cookieDismiss,
            'scannerToken' => $withScannerToken,
            'httpAuth' => $withHttpAuth,
            'excludedPatterns' => StringListUtility::normalize($excludedPatterns),
            'priorityUrls' => StringListUtility::normalize($priorityUrls),
            'cookieSelectors' => StringListUtility::normalize($cookieSelectors),
            'languageId' => $languageId,
            'languageCode' => trim($languageCode),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * A licence refused only for this site's domain says what to fix (activate it, free a slot, or use a domain a
     * site of this installation reports), so its reason reaches the scan error presenter.
     */
    private function crawlerUnavailable(LicenceValidationResult $licence): TokenRefreshException
    {
        $reason = (string)$licence->reason;

        return new TokenRefreshException(
            'Crawler feature is not available for this licence.',
            0,
            in_array($reason, self::DOMAIN_REASONS, true)
                ? new ApiRequestFailedException('AQG licence refused the domain.', 403, null, $reason)
                : null,
        );
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
        $responseDto = $this->withCurrentToken($domain, $version, 'results request', fn (string $accessToken) => $this->crawlerClient->results($accessToken, $jobId));

        if (!$responseDto->success || $responseDto->jobId === null) {
            throw new TokenRefreshException(
                $responseDto->errorMessage ?? 'Remote crawler results request failed.'
            );
        }

        return CrawlerResultsResult::fromResponseDto($responseDto);
    }

    public function getStatus(string $domain, string $version, string $jobId): CrawlerStatusResult
    {
        $responseDto = $this->withCurrentToken($domain, $version, 'status request', fn (string $accessToken) => $this->crawlerClient->status($accessToken, $jobId));

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

    /**
     * Runs a crawler request with the cached token and, when the crawler refuses that token (401: expired, or revoked
     * — e.g. after a plan change the token's rights no longer match the licence), once more with a new one. Before,
     * only the history-type reads did that, so scan submit, status and results failed until the cached token expired.
     *
     * @template T
     * @param callable(string): T $request
     * @return T
     */
    private function withCurrentToken(string $domain, string $version, string $label, callable $request): mixed
    {
        $token = $this->proTokenService->getValidToken($domain, $version);
        try {
            return $request($token->accessToken);
        } catch (ApiRequestFailedException $exception) {
            // Only the crawler's answer that it refuses the token (401) — never a match in the message, which embeds
            // the request URL, payload and token length: a timed-out submit whose URL contains "401" was sent twice.
            if ($exception->httpStatus !== 401) {
                throw new TokenRefreshException('Remote crawler ' . $label . ' failed: ' . $exception->getMessage(), 0, $exception);
            }
        }

        try {
            return $request($this->proTokenService->getValidToken($domain, $version, true)->accessToken);
        } catch (ApiRequestFailedException $retryException) {
            throw new TokenRefreshException('Remote crawler ' . $label . ' failed after token refresh: ' . $retryException->getMessage(), 0, $retryException);
        }
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
        string $language = 'en',
        ?int $languageId = null,
    ): array {
        $token = $this->proTokenService->getValidToken($domain, $version);

        try {
            return $this->crawlerClient->regressionAlert($token->accessToken, $siteId, $sourceType, $startUrl, $language, $languageId);
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
                return $this->crawlerClient->regressionAlert($token->accessToken, $siteId, $sourceType, $startUrl, $language, $languageId);
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
            throw $this->crawlerUnavailable($licence);
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
