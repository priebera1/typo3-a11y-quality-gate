<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;

/**
 * The Trial's frontend scan allowance as the AQG crawler counts and enforces it: `trialDaily` of
 * /entitlement/status, a rolling 24-hour window per licence. TYPO3 keeps no counter of its own.
 *
 * Like the Free status, the answer is display data behind a short cache so the Overview never waits on the API for
 * every render; every paid submit drops it (ProCrawlerService::submit()). No token or installation id is cached.
 */
final class TrialUsageService
{
    public function __construct(
        private readonly ProTokenService $proTokenService,
        private readonly AqgCrawlerClient $crawlerClient,
        private readonly InstallationIdentityServiceInterface $installationIdentityService,
        private readonly ProCacheManager $cacheManager,
    ) {
    }

    /**
     * @return array{limitsEnforced: bool, available: bool, jobsUsed: int, jobsLimit: int, scansRemaining: int, maxPagesPerJob: int, nextScanAt: string}|null
     *         null when the API did not report Trial usage (unreachable, older API, not a Trial)
     */
    public function getUsage(string $domain, string $version, string $siteUrl, string $siteIdentifier): ?array
    {
        $cacheKey = $this->cacheKey($domain);
        $cached = $this->cacheManager->getDisplayPayload($cacheKey);
        if (is_array($cached) && array_key_exists('trialUsage', $cached)) {
            return is_array($cached['trialUsage']) ? $cached['trialUsage'] : null;
        }

        try {
            $payload = $this->crawlerClient->entitlementStatus(
                $this->proTokenService->getValidToken($domain, $version)->accessToken,
                $this->installationIdentityService->getOrCreateInstallationId(),
                $siteUrl,
                $siteIdentifier,
            );
            $usage = $this->parse($payload);
        } catch (\Throwable) {
            $usage = null;
        }

        $this->cacheManager->setDisplayPayload(
            $cacheKey,
            ['trialUsage' => $usage],
            $usage !== null ? ProConstants::FREE_ENTITLEMENT_CACHE_TTL : ProConstants::FREE_ENTITLEMENT_ERROR_CACHE_TTL,
        );

        return $usage;
    }

    public function forget(string $domain): void
    {
        $this->cacheManager->removeDisplayPayload($this->cacheKey($domain));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{limitsEnforced: bool, available: bool, jobsUsed: int, jobsLimit: int, scansRemaining: int, maxPagesPerJob: int, nextScanAt: string}|null
     */
    private function parse(array $payload): ?array
    {
        $trialDaily = $payload['trialDaily'] ?? null;
        if (
            strtolower(trim((string)($payload['entitlement'] ?? ''))) !== 'trial'
            || !is_array($trialDaily)
            || !is_bool($trialDaily['limitsEnforced'] ?? null)
            || !is_bool($trialDaily['available'] ?? null)
            || !is_numeric($trialDaily['jobsUsed'] ?? null)
            || !is_numeric($trialDaily['jobsLimit'] ?? null)
            || !is_numeric($trialDaily['scansRemaining'] ?? null)
            || !is_numeric($trialDaily['maxPagesPerJob'] ?? null)
        ) {
            return null;
        }

        return [
            'limitsEnforced' => $trialDaily['limitsEnforced'],
            'available' => $trialDaily['available'],
            'jobsUsed' => max(0, (int)$trialDaily['jobsUsed']),
            'jobsLimit' => max(0, (int)$trialDaily['jobsLimit']),
            'scansRemaining' => max(0, (int)$trialDaily['scansRemaining']),
            'maxPagesPerJob' => max(0, (int)$trialDaily['maxPagesPerJob']),
            'nextScanAt' => trim((string)($trialDaily['nextScanAt'] ?? '')),
        ];
    }

    private function cacheKey(string $domain): string
    {
        return 'aqg_trial_usage_' . hash('sha256', strtolower(trim($domain)));
    }
}
