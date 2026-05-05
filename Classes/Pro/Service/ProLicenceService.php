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
        if ($cached !== null) {
            return $cached;
        }

        try {
            $responseDto = $this->apiClient->validate(
                $licenceKey,
                $domain,
                $version,
                $allSites,
            );

            $result = LicenceValidationResult::fromResponseDto($responseDto);

            $isTrialPlan = $result->plan === 'trial' || $result->isTrial;

            $ttl = $isTrialPlan
                ? ProConstants::CACHE_TTL_TRIAL
                : ($result->valid ? ProConstants::CACHE_TTL_VALID : ProConstants::CACHE_TTL_INVALID);

            $this->cacheManager->setLicenceResult($cacheKey, $result, $ttl);

            return $result;
        } catch (ApiRequestFailedException $exception) {
            $graceResult = $this->cacheManager->getGraceLicenceResult($cacheKey);
            if ($graceResult !== null) {
                return $graceResult;
            }

            return LicenceValidationResult::invalid($exception->getMessage());
        }
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
            return LicenceValidationResult::invalid($exception->getMessage());
        }
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