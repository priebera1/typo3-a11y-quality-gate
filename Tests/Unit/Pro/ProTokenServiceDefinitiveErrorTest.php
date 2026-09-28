<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResponseDto;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Pro\Service\ProTokenService;

/**
 * A verdict from the token endpoint — licence, project, trial or plan — ends the cached paid state at once;
 * a rate limit or an unavailable entitlement service keeps the bounded last-known-good state.
 */
final class ProTokenServiceDefinitiveErrorTest extends TestCase
{
    /**
     * @return iterable<string, array{0:string, 1:bool}>
     */
    public static function answers(): iterable
    {
        foreach ([
            'licence_invalid', 'licence_project_mismatch', 'licence_project_limit_reached', 'licence_project_removed',
            'domain_limit_reached', 'product_mismatch', 'feature_not_available',
            'trial_invalid', 'trial_revoked', 'trial_not_verified', 'trial_expired', 'trial_domain_mismatch', 'trial_project_mismatch',
        ] as $code) {
            yield $code => [$code, true];
        }
        foreach (['licence_rate_limited', 'entitlement_unavailable', 'validation_failed', ''] as $code) {
            yield 'transient ' . ($code !== '' ? $code : '(no code)') => [$code, false];
        }
    }

    #[DataProvider('answers')]
    #[Test]
    public function onlyAVerdictDropsTheCachedPaidState(string $errorCode, bool $definitive): void
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        $cache->expects($definitive ? self::once() : self::never())->method('flushAll');
        $client = $this->createMock(AqgApiClient::class);
        $client->method('issueToken')->willReturn(AccessTokenResponseDto::fromArray([
            'success' => false,
            'error' => $errorCode !== '' ? ['code' => $errorCode, 'message' => 'refused'] : ['message' => 'unavailable'],
        ]));
        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn(true);
        $settings->method('isTrialKey')->willReturn(str_starts_with($errorCode, 'trial_'));
        $settings->method('getLicenceKey')->willReturn('aqg_live_key');
        $sites = $this->createMock(ProSiteFingerprintService::class);
        $sites->method('collectValidationSites')->willReturn(['client.example']);
        $sites->method('buildFingerprint')->willReturn('fingerprint');

        $this->expectException(TokenRefreshException::class);
        (new ProTokenService($client, $cache, $settings, $sites))->getValidToken('client.example', '1.9.7');
    }
}
