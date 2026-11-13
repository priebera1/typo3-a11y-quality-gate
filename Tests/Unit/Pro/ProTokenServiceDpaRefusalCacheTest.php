<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResponseDto;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Pro\Service\ProTokenService;

/**
 * N-14 / M-06 (final customer acceptance): without an accepted DPA every background render asked the token endpoint
 * again (20 refused requests during two content scans), so the next real scan hit the rate limit and showed the
 * rate-limit text instead of the DPA reason. A DPA refusal is now remembered briefly; callers get the same DPA answer
 * without a request, and a forced refresh (the scan's own retry after a 401) still asks.
 */
final class ProTokenServiceDpaRefusalCacheTest extends TestCase
{
    #[Test]
    public function aDpaRefusalIsRememberedBriefly(): void
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        $cache->method('getTokenRefusal')->willReturn(null);
        $cache->expects(self::once())->method('setTokenRefusal')
            ->with(self::isType('string'), 'dpa_acceptance_required', ProConstants::TOKEN_REFUSAL_TTL);
        $client = $this->createMock(AqgApiClient::class);
        $client->expects(self::once())->method('issueToken')->willReturn(self::dpaRefusal());

        $this->expectException(TokenRefreshException::class);
        $this->service($client, $cache)->getValidToken('client.example', '3.0.0');
    }

    #[Test]
    public function aRememberedDpaRefusalAnswersWithoutARequest(): void
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        $cache->method('getTokenRefusal')->willReturn('dpa_acceptance_required');
        $client = $this->createMock(AqgApiClient::class);
        $client->expects(self::never())->method('issueToken');

        try {
            $this->service($client, $cache)->getValidToken('client.example', '3.0.0');
            self::fail('No token without the DPA.');
        } catch (TokenRefreshException $exception) {
            $previous = $exception->getPrevious();
            self::assertInstanceOf(ApiRequestFailedException::class, $previous);
            self::assertSame('dpa_acceptance_required', $previous->apiErrorCode);
            self::assertSame(403, $previous->httpStatus);
            $presented = (new \Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter())->present($exception);
            self::assertSame('Data processing agreement not accepted', $presented['title']);
        }
    }

    #[Test]
    public function aForcedRefreshStillAsks(): void
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        $cache->method('getTokenRefusal')->willReturn('dpa_acceptance_required');
        $client = $this->createMock(AqgApiClient::class);
        $client->expects(self::once())->method('issueToken')->willReturn(self::dpaRefusal());

        $this->expectException(TokenRefreshException::class);
        $this->service($client, $cache)->getValidToken('client.example', '3.0.0', true);
    }

    #[Test]
    public function aScanTheUserStartsStillAsks(): void
    {
        // The owner may just have accepted the DPA: their next scan must not wait for the remembered refusal to expire.
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        $cache->method('getTokenRefusal')->willReturn('dpa_acceptance_required');
        $client = $this->createMock(AqgApiClient::class);
        $client->expects(self::once())->method('issueToken')->willReturn(self::dpaRefusal());

        $this->expectException(TokenRefreshException::class);
        $this->service($client, $cache)->getValidToken('client.example', '3.0.0', false, true);
    }

    private static function dpaRefusal(): AccessTokenResponseDto
    {
        return AccessTokenResponseDto::fromArray([
            'success' => false,
            'error' => ['code' => 'dpa_acceptance_required', 'message' => 'Hosted scans need the AQG data processing agreement.', 'status' => 403],
        ]);
    }

    private function service(AqgApiClient $client, ProCacheManager $cache): ProTokenService
    {
        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn(true);
        $settings->method('getLicenceKey')->willReturn('aqg_live_key');
        $sites = $this->createMock(ProSiteFingerprintService::class);
        $sites->method('collectValidationSites')->willReturn(['client.example']);
        $sites->method('buildFingerprint')->willReturn('fingerprint');

        return new ProTokenService($client, $cache, $settings, $sites);
    }
}
