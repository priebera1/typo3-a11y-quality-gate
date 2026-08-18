<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerResultsResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSummaryResult;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;

final class FreeRemotePreviewService
{
    public function __construct(
        private readonly FreeAccessTokenService $tokenService,
        private readonly AqgCrawlerClient $crawlerClient,
        private readonly InstallationIdentityServiceInterface $installationIdentityService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getEntitlementStatus(string $siteUrl, string $siteIdentifier, string $version): array
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

        return CrawlerSubmitResult::fromResponseDto($response);
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
        $state = match ($code) {
            'free_daily_limit_reached' => 'FREE_LIMIT_REACHED',
            'feature_not_available', 'free_remote_preview_disabled' => 'FEATURE_NOT_AVAILABLE',
            'idempotency_key_reused' => 'IDEMPOTENCY_CONFLICT',
            'missing_installation_id' => 'MISSING_INSTALLATION_ID',
            'installation_identity_mismatch' => 'INSTALLATION_IDENTITY_MISMATCH',
            'site_identity_mismatch' => 'SITE_IDENTITY_MISMATCH',
            'invalid_site', 'invalid_site_url', 'unsafe_site_url', 'invalid_request' => 'INVALID_SITE',
            'invalid_token', 'token_expired', 'unauthorized' => 'TOKEN_ERROR',
            'route_not_found' => 'ENDPOINT_NOT_FOUND',
            'rate_limit_exceeded' => 'API_UNAVAILABLE',
            'invalid_installation_proof', 'installation_proof_missing',
            'installation_proof_unavailable', 'installation_proof_timeout',
            'installation_proof_too_large', 'installation_proof_redirect_blocked' => 'PROOF_ERROR',
            default => $exception->httpStatus >= 500 || $exception->httpStatus === 0
                ? 'API_UNAVAILABLE'
                : 'API_CONTRACT_ERROR',
        };

        $message = match ($state) {
            'FREE_LIMIT_REACHED' => 'The Free Remote Preview daily scan limit has been reached.',
            'FEATURE_NOT_AVAILABLE' => 'This remote feature is available in PRO.',
            'IDEMPOTENCY_CONFLICT' => 'This Free Remote Preview request conflicts with an earlier submit. Reload before starting a new scan.',
            'PROOF_ERROR' => 'The public Free Remote Preview proof could not be verified.',
            'MISSING_INSTALLATION_ID' => 'Free Remote Preview installation identity is missing.',
            'INSTALLATION_IDENTITY_MISMATCH' => 'The Free Remote Preview installation identity does not match its token.',
            'SITE_IDENTITY_MISMATCH' => 'The TYPO3 site identifier does not match the Free Remote Preview token.',
            'INVALID_SITE' => 'The configured TYPO3 site URL is not valid for Free Remote Preview.',
            'TOKEN_ERROR' => 'Free Remote Preview authentication was rejected.',
            'ENDPOINT_NOT_FOUND' => 'The Free Remote Preview status endpoint is not available.',
            'API_CONTRACT_ERROR' => 'The Free Remote Preview request was rejected by the API contract.',
            default => 'Free Remote Preview is temporarily unavailable.',
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
