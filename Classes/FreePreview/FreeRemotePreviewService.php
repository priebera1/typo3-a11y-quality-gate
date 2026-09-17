<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerResultsResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSummaryResult;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;

final class FreeRemotePreviewService
{
    public function __construct(
        private readonly FreeAccessTokenService $tokenService,
        private readonly AqgCrawlerClient $crawlerClient,
        private readonly InstallationIdentityServiceInterface $installationIdentityService,
        private readonly ProCacheManager $cacheManager,
    ) {
    }

    /**
     * Free entitlement status for the Overview render path.
     *
     * The result is served from a short bounded cache so a slow or unavailable API cannot stall
     * the backend module on every page load. Only the Free display payload is ever cached: a paid
     * entitlement is always returned live so no authorization decision can be made from a stale
     * value, and no token, licence key or installation id is stored.
     *
     * @return array<string, mixed>
     */
    public function getEntitlementStatus(
        string $siteUrl,
        string $siteIdentifier,
        string $version,
        bool $forceRefresh = false,
    ): array {
        $cacheKey = $this->buildStatusCacheKey($siteUrl, $siteIdentifier);

        if (!$forceRefresh) {
            $cached = $this->cacheManager->getDisplayPayload($cacheKey);
            if ($this->isCacheableStatus($cached)) {
                $cached['fromCache'] = true;

                return $cached;
            }
        }

        $viewData = $this->fetchEntitlementStatus($siteUrl, $siteIdentifier, $version);
        $viewData['fromCache'] = false;

        if ($this->isCacheableStatus($viewData)) {
            $this->cacheManager->setDisplayPayload(
                $cacheKey,
                $viewData,
                (string)($viewData['state'] ?? '') === 'FREE_AVAILABLE'
                || (string)($viewData['state'] ?? '') === 'FREE_USED_TODAY'
                    ? ProConstants::FREE_ENTITLEMENT_CACHE_TTL
                    : ProConstants::FREE_ENTITLEMENT_ERROR_CACHE_TTL
            );
        }

        return $viewData;
    }

    /**
     * The cached Free entitlement status, without contacting the API. Render paths that must stay fast,
     * such as the Page module, show quota details only when the Overview or a submit already fetched
     * them; a miss returns null instead of adding a synchronous API call. Shares the Overview's key.
     *
     * @return array<string, mixed>|null
     */
    public function peekEntitlementStatus(string $siteUrl, string $siteIdentifier): ?array
    {
        $cached = $this->cacheManager->getDisplayPayload($this->buildStatusCacheKey($siteUrl, $siteIdentifier));

        return $this->isCacheableStatus($cached) ? $cached : null;
    }

    /**
     * A payload may be cached only when it is a Free display state. Paid entitlements and anything
     * that is not recognisably Free are excluded so that no capability can be granted from cache.
     *
     * @param array<string, mixed>|null $viewData
     */
    private function isCacheableStatus(?array $viewData): bool
    {
        if ($viewData === null) {
            return false;
        }

        return ($viewData['isFree'] ?? null) === true
            && (string)($viewData['entitlement'] ?? '') === 'free_daily'
            && !array_key_exists('accessToken', $viewData)
            && !array_key_exists('installationId', $viewData);
    }

    private function buildStatusCacheKey(string $siteUrl, string $siteIdentifier): string
    {
        return 'aqg_free_entitlement_' . hash('sha256', implode('|', [
            trim($siteUrl),
            trim($siteIdentifier),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchEntitlementStatus(string $siteUrl, string $siteIdentifier, string $version): array
    {
        try {
            $token = $this->tokenService->getValidToken($siteUrl, $siteIdentifier, $version);
            $payload = $this->crawlerClient->entitlementStatus(
                $token->accessToken,
                $this->installationIdentityService->getOrCreateInstallationId(),
                $siteUrl,
                $siteIdentifier,
            );
        } catch (FreePreviewException $exception) {
            return $this->errorViewData($exception);
        } catch (ApiRequestFailedException $exception) {
            return $this->errorViewData($this->mapApiException($exception));
        } catch (\Throwable) {
            return $this->errorViewData(new FreePreviewException(
                'Free Remote Preview status is temporarily unavailable.',
                'API_UNAVAILABLE',
                'free_daily_status_unavailable',
                503,
            ));
        }

        $entitlement = strtolower(trim((string)($payload['entitlement'] ?? '')));
        if (in_array($entitlement, ['trial', 'pro', 'agency'], true)) {
            return [
                'isFree' => false,
                'entitlement' => $entitlement,
                'state' => strtoupper($entitlement),
                'remoteCrawlerVisible' => (bool)($payload['remoteCrawlerVisible'] ?? true),
            ];
        }

        $freeDaily = $payload['freeDaily'] ?? null;
        if (
            $entitlement !== 'free_daily'
            || !is_bool($payload['remoteCrawlerVisible'] ?? null)
            || !is_array($freeDaily)
            || !is_bool($freeDaily['available'] ?? null)
            || !is_numeric($freeDaily['jobsUsed'] ?? null)
            || !is_numeric($freeDaily['jobsLimit'] ?? null)
            || !is_numeric($freeDaily['pagesUsed'] ?? null)
            || !is_numeric($freeDaily['pagesLimit'] ?? null)
            || trim((string)($freeDaily['resetsAt'] ?? '')) === ''
        ) {
            return $this->errorViewData(new FreePreviewException(
                'The Free Remote Preview status response does not match the expected contract.',
                'API_CONTRACT_ERROR',
                'invalid_entitlement_status_contract',
                502,
            ));
        }

        $available = (bool)($freeDaily['available'] ?? false);
        $scansUsed = max(0, (int)($freeDaily['jobsUsed'] ?? 0));
        $scansLimit = max(0, (int)($freeDaily['jobsLimit'] ?? 0));

        return [
            'isFree' => true,
            'entitlement' => 'free_daily',
            'state' => $available ? 'FREE_AVAILABLE' : 'FREE_USED_TODAY',
            'remoteCrawlerVisible' => (bool)($payload['remoteCrawlerVisible'] ?? true),
            'available' => $available,
            'jobsUsed' => $scansUsed,
            'jobsLimit' => $scansLimit,
            'scansRemaining' => max(0, $scansLimit - $scansUsed),
            'pagesUsed' => max(0, (int)($freeDaily['pagesUsed'] ?? 0)),
            'pagesLimit' => max(0, (int)($freeDaily['pagesLimit'] ?? 0)),
            'resetsAt' => trim((string)($freeDaily['resetsAt'] ?? '')),
            'upgradeUrl' => trim((string)($payload['upgradeUrl'] ?? '')),
            'errorCode' => '',
            'message' => '',
            'retryable' => false,
        ];
    }

    public function submit(
        string $siteUrl,
        string $siteIdentifier,
        string $startUrl,
        string $version,
        string $idempotencyKey,
    ): CrawlerSubmitResult {
        $token = $this->tokenService->getValidToken($siteUrl, $siteIdentifier, $version);

        try {
            $response = $this->crawlerClient->submitFree(
                $token->accessToken,
                $this->installationIdentityService->getOrCreateInstallationId(),
                $siteUrl,
                $siteIdentifier,
                $startUrl,
                $idempotencyKey,
            );
        } catch (ApiRequestFailedException $exception) {
            if ($this->mayHaveChangedFreeUsage($exception)) {
                $this->forgetEntitlementStatus($siteUrl, $siteIdentifier);
            }
            throw $this->mapApiException($exception);
        }

        if (!$response->success || $response->jobId === null) {
            throw new FreePreviewException(
                'Free Remote Preview submit failed.',
                'API_UNAVAILABLE',
                $response->errorCode ?? 'free_submit_failed',
                503,
            );
        }

        // The API has just consumed a credit: the next render must show it, not the cached count.
        $this->forgetEntitlementStatus($siteUrl, $siteIdentifier);

        return CrawlerSubmitResult::fromResponseDto($response);
    }

    private function forgetEntitlementStatus(string $siteUrl, string $siteIdentifier): void
    {
        $this->cacheManager->removeDisplayPayload($this->buildStatusCacheKey($siteUrl, $siteIdentifier));
    }

    /**
     * Rejections the API answers before reserving a credit (proof, idempotency, site, rate limit)
     * leave the cached count true. A daily-limit rejection proves a cached "available" state wrong,
     * and a transport error or 5xx does not tell whether the reservation committed before it failed.
     */
    private function mayHaveChangedFreeUsage(ApiRequestFailedException $exception): bool
    {
        return trim($exception->apiErrorCode) === 'free_daily_limit_reached'
            || $exception->httpStatus === 0
            || $exception->httpStatus >= 500;
    }

    public function getStatus(string $siteUrl, string $siteIdentifier, string $version, string $jobId): CrawlerStatusResult
    {
        $token = $this->tokenService->getValidToken($siteUrl, $siteIdentifier, $version);
        try {
            $response = $this->crawlerClient->status($token->accessToken, $jobId);
        } catch (ApiRequestFailedException $exception) {
            throw $this->mapApiException($exception);
        }

        if (!$response->success || $response->jobId === null) {
            throw new FreePreviewException('Free Remote Preview status failed.', 'API_UNAVAILABLE', 'free_status_failed', 503);
        }

        return CrawlerStatusResult::fromResponseDto($response);
    }

    public function getSummary(string $siteUrl, string $siteIdentifier, string $version, string $jobId): CrawlerSummaryResult
    {
        $token = $this->tokenService->getValidToken($siteUrl, $siteIdentifier, $version);
        try {
            $response = $this->crawlerClient->summary($token->accessToken, $jobId);
        } catch (ApiRequestFailedException $exception) {
            throw $this->mapApiException($exception);
        }

        if (!$response->success || $response->jobId === null) {
            throw new FreePreviewException('Free Remote Preview summary failed.', 'API_UNAVAILABLE', 'free_summary_failed', 503);
        }

        return CrawlerSummaryResult::fromResponseDto($response);
    }

    public function getResults(string $siteUrl, string $siteIdentifier, string $version, string $jobId): CrawlerResultsResult
    {
        $token = $this->tokenService->getValidToken($siteUrl, $siteIdentifier, $version);
        try {
            $response = $this->crawlerClient->results($token->accessToken, $jobId);
        } catch (ApiRequestFailedException $exception) {
            throw $this->mapApiException($exception);
        }

        if (!$response->success || $response->jobId === null) {
            throw new FreePreviewException('Free Remote Preview results failed.', 'API_UNAVAILABLE', 'free_results_failed', 503);
        }

        return CrawlerResultsResult::fromResponseDto($response);
    }

    private function mapApiException(ApiRequestFailedException $exception): FreePreviewException
    {
        $code = trim($exception->apiErrorCode);
        // API throttling is temporary: the request may succeed later, so it is neither a contract nor a site error.
        $rateLimited = in_array($code, FreePreviewException::RATE_LIMIT_CODES, true);
        $state = $rateLimited ? 'API_UNAVAILABLE' : match ($code) {
            'free_daily_limit_reached' => 'FREE_LIMIT_REACHED',
            'feature_not_available', 'free_remote_preview_disabled' => 'FEATURE_NOT_AVAILABLE',
            'idempotency_key_reused' => 'IDEMPOTENCY_CONFLICT',
            'missing_installation_id' => 'MISSING_INSTALLATION_ID',
            'installation_identity_mismatch' => 'INSTALLATION_IDENTITY_MISMATCH',
            'site_identity_mismatch' => 'SITE_IDENTITY_MISMATCH',
            'invalid_site', 'invalid_site_url', 'unsafe_site_url', 'invalid_request' => 'INVALID_SITE',
            'invalid_token', 'token_expired', 'unauthorized' => 'TOKEN_ERROR',
            'route_not_found' => 'ENDPOINT_NOT_FOUND',
            'invalid_installation_proof', 'installation_proof_missing',
            'installation_proof_unavailable', 'installation_proof_timeout',
            'installation_proof_too_large', 'installation_proof_redirect_blocked' => 'PROOF_ERROR',
            default => $exception->httpStatus >= 500 || $exception->httpStatus === 0
                ? 'API_UNAVAILABLE'
                : 'API_CONTRACT_ERROR',
        };

        $message = $rateLimited
            ? BackendLabelUtility::translate('freePreview.error.rateLimited', 'AQG paused Free Remote Preview requests from this site for now. Try again later.')
            : match ($state) {
                'FREE_LIMIT_REACHED' => BackendLabelUtility::translate('freePreview.error.limitReached', 'The Free Remote Preview daily scan limit has been reached.'),
                'FEATURE_NOT_AVAILABLE' => BackendLabelUtility::translate('freePreview.error.featureUnavailable', 'This feature is not included in the Free Remote Preview. Start a trial or choose a PRO or Agency plan to use it.'),
                'IDEMPOTENCY_CONFLICT' => BackendLabelUtility::translate('freePreview.error.idempotency', 'This Free Remote Preview request conflicts with an earlier submit. Reload before starting a new scan.'),
                'PROOF_ERROR' => BackendLabelUtility::translate('freePreview.error.proof', 'The public Free Remote Preview proof could not be verified.'),
                'MISSING_INSTALLATION_ID' => BackendLabelUtility::translate('freePreview.error.missingInstallation', 'Free Remote Preview installation identity is missing.'),
                'INSTALLATION_IDENTITY_MISMATCH' => BackendLabelUtility::translate('freePreview.error.installationMismatch', 'The Free Remote Preview installation identity does not match its token.'),
                'SITE_IDENTITY_MISMATCH' => BackendLabelUtility::translate('freePreview.error.siteMismatch', 'The TYPO3 site identifier does not match the Free Remote Preview token.'),
                'INVALID_SITE' => BackendLabelUtility::translate('freePreview.error.invalidSite', 'The configured TYPO3 site URL is not valid for Free Remote Preview.'),
                'TOKEN_ERROR' => BackendLabelUtility::translate('freePreview.error.token', 'Free Remote Preview authentication was rejected.'),
                'ENDPOINT_NOT_FOUND' => BackendLabelUtility::translate('freePreview.error.endpoint', 'The Free Remote Preview status endpoint is not available.'),
                'API_CONTRACT_ERROR' => BackendLabelUtility::translate('freePreview.error.contract', 'The Free Remote Preview request was rejected by the API contract.'),
                default => BackendLabelUtility::translate('freePreview.error.unavailable', 'Free Remote Preview is temporarily unavailable.'),
            };

        return new FreePreviewException(
            $message,
            $state,
            $code !== '' ? $code : 'free_preview_unavailable',
            $exception->httpStatus > 0 ? $exception->httpStatus : 503,
            $exception->details,
            $exception,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function errorViewData(FreePreviewException $exception): array
    {
        return [
            'isFree' => true,
            'entitlement' => 'free_daily',
            'state' => $exception->state,
            'remoteCrawlerVisible' => true,
            'available' => false,
            'jobsUsed' => null,
            'jobsLimit' => null,
            'pagesUsed' => null,
            'pagesLimit' => null,
            'resetsAt' => trim((string)($exception->freeDaily['resetsAt'] ?? '')),
            'upgradeUrl' => '',
            'errorCode' => $exception->errorCode,
            'message' => $exception->getMessage(),
            'retryable' => in_array($exception->state, [
                'API_UNAVAILABLE',
                'TOKEN_ERROR',
                'ENDPOINT_NOT_FOUND',
            ], true),
        ];
    }
}
