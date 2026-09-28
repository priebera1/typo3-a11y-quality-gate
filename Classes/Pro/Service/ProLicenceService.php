<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;

final class ProLicenceService
{
    public function __construct(
        private readonly AqgApiClient $apiClient,
        private readonly ProCacheManager $cacheManager,
        private readonly ProSettings $proSettings,
        private readonly ProSiteFingerprintService $proSiteFingerprintService,
    ) {
    }

    public function validate(string $domain, string $version): LicenceValidationResult
    {
        if (!$this->proSettings->isConfigured()) {
            return LicenceValidationResult::invalid('not_configured');
        }

        $licenceKey = $this->proSettings->getLicenceKey();
        $isTrialKey = $this->proSettings->isTrialKey($licenceKey);

        $allSites = $this->proSiteFingerprintService->collectValidationSites(
            $domain,
            $isTrialKey
        );

        $cacheKey = $this->buildCacheKey($domain, $allSites);
        $cached = $this->cacheManager->getFreshLicenceResult($cacheKey);
        if ($cached !== null && !$this->isPastEntitlementEnd($cached)) {
            return $cached;
        }

        try {
            $result = LicenceValidationResult::fromResponseDto($this->apiClient->validate(
                $licenceKey,
                $domain,
                $version,
                $allSites,
            ));
        } catch (ApiRequestFailedException $exception) {
            $result = LicenceValidationResult::invalid(self::failureReason($exception));
        }

        return $this->storeResult($cacheKey, $result);
    }

    /**
     * Caches a validation answer by what it says about the licence.
     *
     * - An outage, a rate limit or an answer without a verdict says nothing about the licence: the last
     *   known good entitlement stays in force while it has not reached its end, and it is never replaced.
     * - A valid answer is cached no longer than the entitlement lasts.
     * - A definitive rejection (invalid, expired, revoked, domain or project) replaces every positive
     *   state at once: the cached answer, the offline grace copy and the issued access tokens.
     */
    public function storeResult(string $cacheKey, LicenceValidationResult $result): LicenceValidationResult
    {
        if ($result->isTransientFailure()) {
            $grace = $this->cacheManager->getGraceLicenceResult($cacheKey);
            if ($grace !== null && $grace->valid && !$this->isPastEntitlementEnd($grace)) {
                $this->cacheManager->setFreshLicenceResult(
                    $cacheKey,
                    $grace,
                    $this->capToEntitlementEnd($grace, ProConstants::CACHE_TTL_TRANSIENT)
                );

                return $grace;
            }

            $this->cacheManager->setFreshLicenceResult($cacheKey, $result, ProConstants::CACHE_TTL_TRANSIENT);

            return $result;
        }

        if ($result->valid) {
            $isTrialPlan = $result->plan === 'trial' || $result->isTrial;
            $this->cacheManager->setLicenceResult(
                $cacheKey,
                $result,
                $this->capToEntitlementEnd($result, $isTrialPlan ? ProConstants::CACHE_TTL_TRIAL : ProConstants::CACHE_TTL_VALID),
                $this->capToEntitlementEnd($result, ProConstants::CACHE_TTL_GRACE, 0),
            );

            return $result;
        }

        $this->cacheManager->setLicenceResult(
            $cacheKey,
            $result,
            ProConstants::CACHE_TTL_INVALID,
            ProConstants::CACHE_TTL_GRACE,
        );
        $this->cacheManager->flushTokens();

        return $result;
    }

    /**
     * A cached valid answer is unusable once the entitlement it describes has ended; the API decides again.
     */
    private function isPastEntitlementEnd(LicenceValidationResult $result): bool
    {
        $end = $result->entitlementExpiresAt();

        return $result->valid && $end !== null && $end <= time();
    }

    /**
     * A TTL that ends no later than the entitlement. When the API still confirmed a licence past its
     * paid-through date (Stripe retrying a failed renewal), only a short re-check interval is cached and
     * no offline grace is kept: whether the licence continues is the API's decision.
     */
    private function capToEntitlementEnd(LicenceValidationResult $result, int $ttl, ?int $afterEndTtl = null): int
    {
        $end = $result->entitlementExpiresAt();
        if ($end === null) {
            return $ttl;
        }

        $remaining = $end - time();
        if ($remaining > 0) {
            return min($ttl, $remaining);
        }

        return $afterEndTtl ?? min($ttl, ProConstants::CACHE_TTL_TRANSIENT);
    }

    /**
     * @param list<string> $allSites
     */
    public function validateKeyDirect(
        string $licenceKey,
        string $domain,
        string $version,
        array $allSites = [],
    ): LicenceValidationResult {
        $licenceKey = trim($licenceKey);

        if ($licenceKey === '') {
            return LicenceValidationResult::invalid('empty_key');
        }

        try {
            $responseDto = $this->apiClient->validate(
                $licenceKey,
                $domain,
                $version,
                $allSites,
            );

            return LicenceValidationResult::fromResponseDto($responseDto);
        } catch (ApiRequestFailedException $exception) {
            return LicenceValidationResult::invalid(self::failureReason($exception));
        }
    }

    /**
     * A failed request says nothing about the key, so it surfaces as an outage the user can retry —
     * never as a licence verdict — while paid features stay off. Classified from the HTTP status, never
     * from the exception message.
     */
    private static function failureReason(ApiRequestFailedException $exception): string
    {
        return $exception->httpStatus === 429 ? 'rate_limited' : 'api_unreachable';
    }

    /**
     * @param list<string> $allSites
     */
    private function buildCacheKey(string $domain, array $allSites): string
    {
        return md5(implode('|', [
            'aqg_licence',
            $this->proSettings->getLicenceKey(),
            $domain,
            ProConstants::PRODUCT_SLUG,
            $this->proSiteFingerprintService->buildFingerprint($allSites),
        ]));
    }
}
