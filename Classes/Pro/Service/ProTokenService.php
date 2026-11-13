<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\ProNotConfiguredException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;

final class ProTokenService
{
    /**
     * Answers from the token endpoint that are a verdict on the licence or trial. A rate limit or an
     * unavailable entitlement service is not among them: those keep the bounded last-known-good state.
     */
    private const DOMAIN_TOKEN_ERRORS = ['domain_limit_reached', 'domain_not_activated', 'domain_not_detected'];

    /**
     * 2.1.0: the licence is valid, but its owner has not accepted the current data processing agreement, so the AQG
     * service issues no hosted token. Not a licence verdict — the cached licence state stays (validation still answers
     * valid); only the scan needs the acceptance.
     */
    private const DPA_REQUIRED = 'dpa_acceptance_required';

    private const DEFINITIVE_TOKEN_ERRORS = [
        'licence_invalid',
        'licence_project_mismatch',
        'licence_project_limit_reached',
        'licence_project_removed',
        'domain_limit_reached',
        'domain_not_activated',
        'domain_not_detected',
        'product_mismatch',
        'feature_not_available',
        'trial_invalid',
        'trial_revoked',
        'trial_not_verified',
        'trial_expired',
        'trial_domain_mismatch',
        'trial_project_mismatch',
    ];

    public function __construct(
        private readonly AqgApiClient $apiClient,
        private readonly ProCacheManager $cacheManager,
        private readonly ProSettings $proSettings,
        private readonly ProSiteFingerprintService $proSiteFingerprintService,
    ) {
    }

    public function getValidToken(string $domain, string $version, bool $forceRefresh = false, bool $askAgainAfterRefusal = false): AccessTokenResult
    {
        if (!$this->proSettings->isConfigured()) {
            throw new ProNotConfiguredException('AQG PRO licence key is not configured.');
        }

        $isTrial = $this->proSettings->isTrialKey();
        $allSites = $this->proSiteFingerprintService->collectValidationSites($domain, $isTrial);

        $cacheKey = $this->buildCacheKey($domain, $allSites);
        $cached = $this->cacheManager->getToken($cacheKey);

        if (!$forceRefresh
            && $cached !== null
            && !$cached->isExpiringSoon(ProConstants::TOKEN_REFRESH_MARGIN)
        ) {
            return $cached;
        }

        // N-14: a missing DPA acceptance does not change from one render to the next. Background callers (Overview
        // regression signal, history, screenshots) get the remembered answer instead of asking again and using up the
        // rate limit the user's next scan needs. A forced refresh and a scan the user starts ($askAgainAfterRefusal: the
        // owner may just have accepted the DPA) still ask.
        if (!$forceRefresh && !$askAgainAfterRefusal && $this->cacheManager->getTokenRefusal($cacheKey) === self::DPA_REQUIRED) {
            throw new TokenRefreshException(
                'AQG token issuance refused: ' . self::DPA_REQUIRED . '.',
                0,
                new ApiRequestFailedException('AQG token issuance refused: ' . self::DPA_REQUIRED . '.', 403, null, self::DPA_REQUIRED),
            );
        }

        try {
            $responseDto = $this->apiClient->issueToken(
                $this->proSettings->getLicenceKey(),
                $domain,
                $version,
                $allSites,
            );
        } catch (ApiRequestFailedException $exception) {
            throw new TokenRefreshException($exception->getMessage(), 0, $exception);
        }

        if (!$responseDto->success || $responseDto->accessToken === null) {
            // A licence verdict from the token endpoint is as definitive as one from validation: no cached
            // positive licence state may keep the paid UI open for another hour.
            if (in_array((string)$responseDto->errorCode, self::DOMAIN_TOKEN_ERRORS, true)) {
                // A refusal for this one domain is no verdict on the other sites of the installation: their offline
                // grace copies stay (the refused site's next validation replaces its own state).
                $this->cacheManager->flushLicenceAnswersAndTokens();
            } elseif (in_array((string)$responseDto->errorCode, self::DEFINITIVE_TOKEN_ERRORS, true)) {
                $this->cacheManager->flushAll();
            }

            // A domain answer names what to fix (activate the domain, or report it from a site), and so does a missing
            // DPA acceptance (accept it in the customer area): their codes reach the scan error presenter. Other
            // refusals stay a licence authentication failure.
            $errorCode = (string)$responseDto->errorCode;
            if ($errorCode === self::DPA_REQUIRED) {
                $this->cacheManager->setTokenRefusal($cacheKey, self::DPA_REQUIRED, ProConstants::TOKEN_REFUSAL_TTL);
            }
            throw new TokenRefreshException(
                $responseDto->errorMessage ?? 'AQG token issuance failed.',
                0,
                in_array($errorCode, [...self::DOMAIN_TOKEN_ERRORS, self::DPA_REQUIRED], true)
                    ? new ApiRequestFailedException('AQG token issuance refused: ' . $errorCode . '.', 403, null, $errorCode)
                    // M-06: a rate limit is no licence verdict; its code reaches the presenter as "limit".
                    : ($responseDto->httpStatus === 429
                        ? new ApiRequestFailedException('AQG token issuance rate limited.', 429, null, $errorCode !== '' ? $errorCode : 'rate_limit_exceeded')
                        : null),
            );
        }

        $result = AccessTokenResult::fromResponseDto($responseDto);

        $this->cacheManager->setToken(
            $cacheKey,
            $result,
            max(1, $result->expiresIn - ProConstants::TOKEN_REFRESH_MARGIN)
        );

        return $result;
    }

    /**
     * @param list<string> $allSites
     */
    private function buildCacheKey(string $domain, array $allSites): string
    {
        return md5(implode('|', [
            'aqg_token',
            $this->proSettings->getLicenceKey(),
            $domain,
            ProConstants::PRODUCT_SLUG,
            $this->proSiteFingerprintService->buildFingerprint($allSites),
        ]));
    }
}
