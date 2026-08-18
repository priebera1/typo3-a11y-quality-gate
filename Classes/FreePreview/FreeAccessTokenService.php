<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;

final class FreeAccessTokenService
{
    private const REQUIRED_CAPABILITIES = [
        'crawler_submit',
        'crawler_status',
        'crawler_results',
        'crawler_summary',
    ];

    public function __construct(
        private readonly AqgApiClient $apiClient,
        private readonly ProCacheManager $cacheManager,
        private readonly InstallationIdentityServiceInterface $installationIdentityService,
    ) {
    }

    public function getValidToken(
        string $siteUrl,
        string $siteIdentifier,
        string $version,
        bool $forceRefresh = false,
    ): AccessTokenResult {
        $siteUrl = trim($siteUrl);
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            throw new FreePreviewException(
                'Free Remote Preview requires a TYPO3 site identifier.',
                'MISSING_SITE_IDENTIFIER',
                'missing_site_identifier',
                400,
            );
        }
        if (filter_var($siteUrl, FILTER_VALIDATE_URL) === false) {
            throw new FreePreviewException(
                'Free Remote Preview requires a valid TYPO3 site URL.',
                'INVALID_SITE',
                'invalid_site_url',
                400,
            );
        }

        $installationId = $this->installationIdentityService->getOrCreateInstallationId();
        if (trim($installationId) === '') {
            throw new FreePreviewException(
                'Free Remote Preview installation identity is unavailable.',
                'MISSING_INSTALLATION_ID',
                'missing_installation_id',
                500,
            );
        }
        $cacheKey = 'aqg_free_token_' . hash('sha256', implode('|', [
            $installationId,
            trim($siteUrl),
            trim($siteIdentifier),
        ]));
        $cached = $this->cacheManager->getToken($cacheKey);

        if (
            !$forceRefresh
            && $cached !== null
            && !$cached->isExpiringSoon(ProConstants::TOKEN_REFRESH_MARGIN)
            && $this->hasExpectedContract(
                $cached->plan,
                $cached->entitlement,
                $cached->features,
                $cached->capabilities,
            )
        ) {
            return $cached;
        }

        try {
            $response = $this->apiClient->issueFreeToken(
                $installationId,
                $siteUrl,
                $siteIdentifier,
                $version,
            );
        } catch (ApiRequestFailedException $exception) {
            $errorCode = trim($exception->apiErrorCode);
            $state = match ($errorCode) {
                'missing_installation_id' => 'MISSING_INSTALLATION_ID',
                'free_remote_preview_disabled', 'feature_not_available' => 'FEATURE_NOT_AVAILABLE',
                'route_not_found' => 'ENDPOINT_NOT_FOUND',
                'validation_failed', 'invalid_request' => 'TOKEN_CONTRACT_ERROR',
                default => $exception->httpStatus >= 500 || $exception->httpStatus === 0
                    ? 'API_UNAVAILABLE'
                    : 'TOKEN_ERROR',
            };
            throw new FreePreviewException(
                match ($state) {
                    'MISSING_INSTALLATION_ID' => 'Free Remote Preview installation identity is missing.',
                    'FEATURE_NOT_AVAILABLE' => 'Free Remote Preview is not enabled on the API.',
                    'ENDPOINT_NOT_FOUND' => 'The Free Remote Preview token endpoint is not available.',
                    'TOKEN_CONTRACT_ERROR' => 'The Free Remote Preview token request does not match the API contract.',
                    'API_UNAVAILABLE' => 'Free Remote Preview authentication is temporarily unavailable.',
                    default => 'Free Remote Preview authentication was rejected.',
                },
                $state,
                $errorCode !== '' ? $errorCode : 'token_request_failed',
                $exception->httpStatus > 0 ? $exception->httpStatus : 503,
                [],
                $exception,
            );
        }

        if (!$response->success || $response->accessToken === null) {
            $code = trim((string)$response->errorCode);
            throw new FreePreviewException(
                $code === 'free_remote_preview_disabled'
                    ? 'Free Remote Preview is not enabled on the API yet.'
                    : 'Free Remote Preview authentication failed.',
                $code === 'free_remote_preview_disabled' ? 'FEATURE_NOT_AVAILABLE' : 'TOKEN_ERROR',
                $code !== '' ? $code : 'token_error',
                $code === 'free_remote_preview_disabled' ? 403 : 503,
            );
        }

        if (!$this->hasExpectedContract(
            $response->plan,
            $response->entitlement,
            $response->features,
            $response->capabilities,
        )) {
            throw new FreePreviewException(
                'The Free Remote Preview token response does not match the expected contract.',
                'TOKEN_CONTRACT_ERROR',
                'invalid_free_token_contract',
                502,
            );
        }

        $result = AccessTokenResult::fromResponseDto($response);
        $this->cacheManager->setToken(
            $cacheKey,
            $result,
            max(1, $result->expiresIn - ProConstants::TOKEN_REFRESH_MARGIN),
        );

        return $result;
    }

    /**
     * @param list<string> $features
     * @param list<string> $capabilities
     */
    private function hasExpectedContract(
        string $plan,
        string $entitlement,
        array $features,
        array $capabilities,
    ): bool {
        return strtolower($plan) === 'free'
            && strtolower($entitlement) === 'free_daily'
            && in_array('crawler', $features, true)
            && array_diff(self::REQUIRED_CAPABILITIES, $capabilities) === [];
    }
}
