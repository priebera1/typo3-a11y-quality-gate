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
    public function __construct(
        private readonly AqgApiClient $apiClient,
        private readonly ProCacheManager $cacheManager,
        private readonly ProSettings $proSettings,
        private readonly ProSiteFingerprintService $proSiteFingerprintService,
    ) {
    }

    public function getValidToken(string $domain, string $version): AccessTokenResult
    {
        if (!$this->proSettings->isConfigured()) {
            throw new ProNotConfiguredException('AQG PRO licence key is not configured.');
        }

        $isTrial = $this->proSettings->isTrialKey();
        $allSites = $this->proSiteFingerprintService->collectValidationSites($domain, $isTrial);

        $cacheKey = $this->buildCacheKey($domain, $allSites);
        $cached = $this->cacheManager->getToken($cacheKey);

        if (
            $cached !== null
            && !$cached->isExpiringSoon(ProConstants::TOKEN_REFRESH_MARGIN)
        ) {
            return $cached;
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
            throw new TokenRefreshException(
                $responseDto->errorMessage ?? 'AQG token issuance failed.'
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