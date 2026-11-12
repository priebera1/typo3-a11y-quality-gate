<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResponseDto;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
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
            'domain_limit_reached', 'domain_not_activated', 'domain_not_detected', 'product_mismatch', 'feature_not_available',
            'trial_invalid', 'trial_revoked', 'trial_not_verified', 'trial_expired', 'trial_domain_mismatch', 'trial_project_mismatch',
        ] as $code) {
            yield $code => [$code, true];
        }
        // A missing DPA acceptance is no licence verdict either: the licence stays validated, only hosted scans wait.
        foreach (['licence_rate_limited', 'entitlement_unavailable', 'validation_failed', 'dpa_acceptance_required', ''] as $code) {
            yield 'transient ' . ($code !== '' ? $code : '(no code)') => [$code, false];
        }
    }

    #[DataProvider('answers')]
    #[Test]
    public function onlyAVerdictDropsTheCachedPaidState(string $errorCode, bool $definitive): void
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        // A domain refusal concerns one site: answers and tokens go, the other sites' offline grace copies stay.
        $domainOnly = in_array($errorCode, ['domain_limit_reached', 'domain_not_activated', 'domain_not_detected'], true);
        $cache->expects($definitive && !$domainOnly ? self::once() : self::never())->method('flushAll');
        $cache->expects($domainOnly ? self::once() : self::never())->method('flushLicenceAnswersAndTokens');
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

    #[Test]
    public function aRateLimitedTokenRequestIsPresentedAsALimitNotAsALicenceFailure(): void
    {
        // M-06 (2026-11-05 company acceptance): the API's rate limiter answers without an error object; the scan then
        // failed with "Licence authentication failed — Revalidate the licence", hiding the real (DPA) reason as well.
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        $cache->expects(self::never())->method('flushAll');
        $client = $this->createMock(AqgApiClient::class);
        $client->method('issueToken')->willReturn(AccessTokenResponseDto::fromArray([
            'statusCode' => 429,
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded, retry in 1 minute',
        ]));
        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn(true);
        $settings->method('getLicenceKey')->willReturn('aqg_live_key');
        $sites = $this->createMock(ProSiteFingerprintService::class);
        $sites->method('collectValidationSites')->willReturn(['client.example']);
        $sites->method('buildFingerprint')->willReturn('fingerprint');

        try {
            (new ProTokenService($client, $cache, $settings, $sites))->getValidToken('client.example', '3.0.0');
            self::fail('A rate-limited token request must not yield a token.');
        } catch (TokenRefreshException $exception) {
            $presented = (new \Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter())->present($exception);
            self::assertSame('Remote scan limit reached', $presented['title']);
            self::assertStringNotContainsString('Revalidate', $presented['message']);
        }
    }

    #[Test]
    public function aMissingDpaAcceptanceNamesTheCustomerAccountStep(): void
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        $client = $this->createMock(AqgApiClient::class);
        $client->method('issueToken')->willReturn(AccessTokenResponseDto::fromArray([
            'success' => false,
            'error' => ['code' => 'dpa_acceptance_required', 'message' => 'Hosted scans need the AQG data processing agreement.', 'status' => 403],
        ]));
        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn(true);
        $settings->method('getLicenceKey')->willReturn('aqg_live_key');
        $sites = $this->createMock(ProSiteFingerprintService::class);
        $sites->method('collectValidationSites')->willReturn(['client.example']);
        $sites->method('buildFingerprint')->willReturn('fingerprint');

        try {
            (new ProTokenService($client, $cache, $settings, $sites))->getValidToken('client.example', '3.0.0');
            self::fail('No token without the DPA.');
        } catch (TokenRefreshException $exception) {
            $presented = (new \Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter())->present($exception);
            self::assertSame('Data processing agreement not accepted', $presented['title']);
            self::assertStringContainsString('customer area', $presented['message']);
            self::assertStringNotContainsString('Revalidate', $presented['message']);
        }
    }

    /**
     * @return iterable<string, array{0:string, 1:string|null}>
     */
    public static function domainAnswers(): iterable
    {
        yield 'not activated' => ['domain_not_activated', 'domain_not_activated'];
        yield 'not detected' => ['domain_not_detected', 'domain_not_detected'];
        yield 'limit reached' => ['domain_limit_reached', 'domain_limit_reached'];
        yield 'missing DPA acceptance' => ['dpa_acceptance_required', 'dpa_acceptance_required'];
        yield 'licence refusal stays an authentication failure' => ['licence_invalid', null];
    }

    /**
     * A refused domain names its own next step in the scan error, so its code travels with the exception.
     */
    #[DataProvider('domainAnswers')]
    #[Test]
    public function aDomainRefusalKeepsItsCode(string $errorCode, ?string $expectedCode): void
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getToken')->willReturn(null);
        $client = $this->createMock(AqgApiClient::class);
        $client->method('issueToken')->willReturn(AccessTokenResponseDto::fromArray([
            'success' => false,
            'error' => ['code' => $errorCode, 'message' => 'refused'],
        ]));
        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn(true);
        $settings->method('getLicenceKey')->willReturn('aqg_live_key');
        $sites = $this->createMock(ProSiteFingerprintService::class);
        $sites->method('collectValidationSites')->willReturn(['client.example']);
        $sites->method('buildFingerprint')->willReturn('fingerprint');

        try {
            (new ProTokenService($client, $cache, $settings, $sites))->getValidToken('client.example', '1.9.8');
            self::fail('The refused token must throw.');
        } catch (TokenRefreshException $exception) {
            $previous = $exception->getPrevious();
            if ($expectedCode === null) {
                self::assertNull($previous);
            } else {
                self::assertInstanceOf(ApiRequestFailedException::class, $previous);
                self::assertSame($expectedCode, $previous->apiErrorCode);
                self::assertSame(403, $previous->httpStatus);
            }
        }
    }
}
