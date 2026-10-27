<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\FreePreview\FreeAccessTokenService;
use Priebera\A11yQualityGate\FreePreview\FreePreviewActivationService;
use Priebera\A11yQualityGate\FreePreview\FreePreviewException;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResponseDto;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;

/**
 * FREE scans and the FREE status reach the AQG service only for a site whose terms acceptance the service has
 * recorded; the acceptance id travels with the token request and the submit, and the service's refusal is recorded.
 */
final class FreeRemotePreviewActivationGateTest extends TestCase
{
    private const SITE = 'https://www.aqg-client.de/';

    #[Test]
    public function withoutActivationNoScanIsSentAndNoTokenIsRequested(): void
    {
        $token = $this->createMock(FreeAccessTokenService::class);
        $token->expects(self::never())->method('getValidToken');
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::never())->method('submitFree');

        try {
            $this->service($token, $crawler, $this->activation(''))->submit(self::SITE, 'main', self::SITE, '2.1.0', 'key');
            self::fail('A FREE scan without activation must be refused.');
        } catch (FreePreviewException $exception) {
            self::assertSame('ACTIVATION_REQUIRED', $exception->state);
            self::assertSame('free_terms_acceptance_required', $exception->errorCode);
            self::assertSame(403, $exception->httpStatus);
        }
    }

    #[Test]
    public function withoutActivationTheStatusIsNotRequestedFromTheService(): void
    {
        $token = $this->createMock(FreeAccessTokenService::class);
        $token->expects(self::never())->method('getValidToken');
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::never())->method('entitlementStatus');

        $status = $this->service($token, $crawler, $this->activation(''))->getEntitlementStatus(self::SITE, 'main', '2.1.0');

        self::assertSame('ACTIVATION_REQUIRED', $status['state']);
        self::assertFalse($status['available']);
    }

    #[Test]
    public function anActivatedSiteSendsItsAcceptanceWithTheTokenRequestAndTheSubmit(): void
    {
        $token = $this->createMock(FreeAccessTokenService::class);
        $token->expects(self::once())->method('getValidToken')
            ->with(self::SITE, 'main', '2.1.0', false, 'acc_01JABCDEF')
            ->willReturn($this->token());
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::once())->method('submitFree')
            ->with('jwt', 'anonymous-id', self::SITE, 'main', self::SITE, 'key', 'acc_01JABCDEF')
            ->willReturn(CrawlerSubmitResponseDto::fromArray(['success' => true, 'jobId' => 'job-1', 'status' => 'queued']));

        $result = $this->service($token, $crawler, $this->activation('acc_01JABCDEF'))->submit(self::SITE, 'main', self::SITE, '2.1.0', 'key');

        self::assertSame('job-1', $result->jobId);
    }

    #[Test]
    public function theServicesRefusalOfTheAcceptanceIsRecordedAndExplained(): void
    {
        $token = $this->createMock(FreeAccessTokenService::class);
        $token->method('getValidToken')->willReturn($this->token());
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('submitFree')->willThrowException(new ApiRequestFailedException('refused', 403, null, 'free_terms_outdated'));
        $activation = $this->activation('acc_01JABCDEF');
        $activation->expects(self::once())->method('recordServiceRefusal')->with(self::SITE, 'free_terms_outdated');

        try {
            $this->service($token, $crawler, $activation)->submit(self::SITE, 'main', self::SITE, '2.1.0', 'key');
            self::fail('The refusal must reach the editor.');
        } catch (FreePreviewException $exception) {
            self::assertSame('ACTIVATION_REQUIRED', $exception->state);
            self::assertSame('free_terms_outdated', $exception->errorCode);
        }
    }

    private function activation(string $acceptanceId): FreePreviewActivationService
    {
        $activation = $this->createMock(FreePreviewActivationService::class);
        $activation->method('activeAcceptanceId')->willReturn($acceptanceId);
        $activation->method('status')->willReturn([
            'state' => $acceptanceId !== '' ? FreePreviewActivationService::STATE_ACTIVE : FreePreviewActivationService::STATE_NOT_ACTIVATED,
            'acceptance' => null,
            'domain' => 'aqg-client.de',
        ]);

        return $activation;
    }

    private function token(): AccessTokenResult
    {
        return new AccessTokenResult('jwt', 3600, time(), 'free', ['crawler'], 'free_daily', ['crawler_submit', 'crawler_status', 'crawler_results', 'crawler_summary']);
    }

    private function service(FreeAccessTokenService $token, AqgCrawlerClient $crawler, FreePreviewActivationService $activation): FreeRemotePreviewService
    {
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')->willReturn('anonymous-id');
        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getDisplayPayload')->willReturn(null);

        return new FreeRemotePreviewService($token, $crawler, $identity, $cache, $activation);
    }
}
