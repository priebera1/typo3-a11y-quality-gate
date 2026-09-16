<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;

/**
 * A failed licence request says nothing about the key. It must surface as a stable, retryable outage
 * reason — not as "invalid" and not as the exception message — while the licence stays invalid.
 */
final class ProLicenceFailureClassificationTest extends TestCase
{
    #[Test]
    public function transportFailureWithoutGraceResultIsAnOutageNotAVerdict(): void
    {
        $apiClient = $this->createMock(AqgApiClient::class);
        $apiClient->method('validate')->willThrowException(
            new ApiRequestFailedException('AQG API request failed.', 0, null, 'transport_error')
        );

        $result = $this->service($apiClient)->validate('example.org', '1.9.4');

        self::assertFalse($result->valid);
        self::assertSame('api_unreachable', $result->reason);
    }

    #[Test]
    public function directValidationClassifiesTheFailureInsteadOfEchoingTheMessage(): void
    {
        $apiClient = $this->createMock(AqgApiClient::class);
        $apiClient->method('validate')->willThrowException(
            new ApiRequestFailedException('AQG API returned invalid JSON.', 502, null, 'invalid_json')
        );

        $result = $this->service($apiClient)->validateKeyDirect('aqg_live_key', 'example.org', '1.9.4');

        self::assertFalse($result->valid);
        self::assertSame('api_unreachable', $result->reason);
    }

    #[Test]
    public function serverErrorBodyWithoutReasonIsAnOutage(): void
    {
        $result = LicenceValidationResult::fromResponseDto(LicenceValidationResponseDto::fromArray([
            'success' => false,
            'error' => ['code' => 'internal_error', 'message' => 'Internal server error'],
        ]));

        self::assertFalse($result->valid);
        self::assertSame('api_unreachable', $result->reason);
    }

    #[Test]
    public function licenceRejectionsKeepTheirBusinessReason(): void
    {
        $expired = LicenceValidationResult::fromResponseDto(LicenceValidationResponseDto::fromArray([
            'success' => false,
            'error' => ['code' => 'licence_invalid', 'details' => ['reason' => 'expired']],
        ]));
        $invalidWithoutDetails = LicenceValidationResult::fromResponseDto(LicenceValidationResponseDto::fromArray([
            'success' => false,
            'error' => ['code' => 'licence_invalid', 'message' => 'Invalid licence key'],
        ]));

        self::assertSame('expired', $expired->reason);
        self::assertSame('invalid_key', $invalidWithoutDetails->reason);
    }

    private function service(AqgApiClient $apiClient): ProLicenceService
    {
        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn(true);
        $settings->method('getLicenceKey')->willReturn('aqg_live_key');
        $settings->method('isTrialKey')->willReturn(false);

        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getFreshLicenceResult')->willReturn(null);
        $cache->method('getGraceLicenceResult')->willReturn(null);

        $fingerprint = $this->createMock(ProSiteFingerprintService::class);
        $fingerprint->method('collectValidationSites')->willReturn(['example.org']);
        $fingerprint->method('buildFingerprint')->willReturn('fingerprint');

        return new ProLicenceService($apiClient, $cache, $settings, $fingerprint);
    }
}
