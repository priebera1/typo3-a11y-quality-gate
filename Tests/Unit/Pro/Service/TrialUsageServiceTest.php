<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResult;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Http\AqgCrawlerClient;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProTokenService;
use Priebera\A11yQualityGate\Pro\Service\TrialUsageService;

/**
 * The Trial's daily scans as the AQG crawler counts and enforces them (`trialDaily` of /entitlement/status), not a
 * counter kept in TYPO3.
 *
 * Regression guard: the Overview showed the fixed text "Max 5 scans per day" with no usage, so a Trial user only
 * learned the allowance was used up from the rejected scan.
 */
final class TrialUsageServiceTest extends TestCase
{
    private const DOMAIN = 'client.example';
    private const SITE_URL = 'https://client.example/';
    private const SITE_ID = 'main';
    private const VERSION = '1.9.9';

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    #[Test]
    public function itReportsTheAllowanceTheCrawlerEnforces(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::once())->method('entitlementStatus')
            ->with('trial-jwt', 'installation-1', self::SITE_URL, self::SITE_ID)
            ->willReturn($this->trialPayload(jobsUsed: 2));

        self::assertSame([
            'limitsEnforced' => true,
            'available' => true,
            'jobsUsed' => 2,
            'jobsLimit' => 5,
            'scansRemaining' => 3,
            'maxPagesPerJob' => 10,
            'nextScanAt' => '',
        ], $this->usage($crawler));
    }

    #[Test]
    public function aUsedUpAllowanceSaysWhenTheNextScanIsPossible(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willReturn($this->trialPayload(jobsUsed: 5, nextScanAt: '2026-10-08T08:00:00.000Z'));

        $usage = $this->usage($crawler);

        self::assertFalse($usage['available'] ?? null);
        self::assertSame(0, $usage['scansRemaining'] ?? null);
        self::assertSame('2026-10-08T08:00:00.000Z', $usage['nextScanAt'] ?? null);
    }

    #[Test]
    public function repeatedRendersAskTheApiOnceWithinTheCacheWindow(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::once())->method('entitlementStatus')->willReturn($this->trialPayload(jobsUsed: 1));
        $service = $this->service($crawler);

        $first = $service->getUsage(self::DOMAIN, self::VERSION, self::SITE_URL, self::SITE_ID);
        $second = $service->getUsage(self::DOMAIN, self::VERSION, self::SITE_URL, self::SITE_ID);

        self::assertSame($first, $second);
    }

    #[Test]
    public function forgettingTheUsageMakesTheNextRenderAskAgain(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::exactly(2))->method('entitlementStatus')
            ->willReturnOnConsecutiveCalls($this->trialPayload(jobsUsed: 1), $this->trialPayload(jobsUsed: 2));
        $service = $this->service($crawler);

        $service->getUsage(self::DOMAIN, self::VERSION, self::SITE_URL, self::SITE_ID);
        $service->forget(self::DOMAIN);

        self::assertSame(2, $service->getUsage(self::DOMAIN, self::VERSION, self::SITE_URL, self::SITE_ID)['jobsUsed'] ?? null);
    }

    #[Test]
    public function anApiThatDoesNotReportTrialUsageYieldsNoUsage(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willReturn(['entitlement' => 'trial', 'remoteCrawlerVisible' => true, 'freeDaily' => null]);

        self::assertNull($this->usage($crawler));
    }

    #[Test]
    public function aPaidAnswerIsNoTrialUsage(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willReturn(['entitlement' => 'pro'] + $this->trialPayload(jobsUsed: 1));

        self::assertNull($this->usage($crawler));
    }

    #[Test]
    public function anUnreachableApiYieldsNoUsageInsteadOfFailingTheView(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->method('entitlementStatus')->willThrowException(new ApiRequestFailedException('timeout', 0));

        self::assertNull($this->usage($crawler));
    }

    #[Test]
    public function aPaidSubmitDropsTheCachedUsageEvenWhenTheApiRejectsIt(): void
    {
        $crawler = $this->createMock(AqgCrawlerClient::class);
        $crawler->expects(self::exactly(2))->method('entitlementStatus')->willReturn($this->trialPayload(jobsUsed: 5));
        $crawler->method('submit')->willThrowException(new ApiRequestFailedException('Trial limit', 429, null, 'trial_crawl_limit'));
        $usage = $this->service($crawler);
        $licence = $this->createMock(ProLicenceService::class);
        $licence->method('validate')->willReturn(new LicenceValidationResult(valid: true, plan: 'trial', isTrial: true));
        $submitter = new ProCrawlerService($licence, $this->tokens(), $crawler, $usage);

        $usage->getUsage(self::DOMAIN, self::VERSION, self::SITE_URL, self::SITE_ID);
        try {
            $submitter->submit(self::DOMAIN, self::VERSION, self::SITE_ID, self::SITE_URL, null, RemoteScanSourceType::Crawl);
            self::fail('The rejected submit must throw.');
        } catch (TokenRefreshException) {
        }
        $usage->getUsage(self::DOMAIN, self::VERSION, self::SITE_URL, self::SITE_ID);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function usage(AqgCrawlerClient $crawler): ?array
    {
        return $this->service($crawler)->getUsage(self::DOMAIN, self::VERSION, self::SITE_URL, self::SITE_ID);
    }

    private function service(AqgCrawlerClient $crawler): TrialUsageService
    {
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')->willReturn('installation-1');

        $cache = $this->createMock(ProCacheManager::class);
        $cache->method('getDisplayPayload')->willReturnCallback(fn (string $key): ?array => $this->cache[$key] ?? null);
        $cache->method('setDisplayPayload')->willReturnCallback(function (string $key, array $payload, int $ttl): void {
            self::assertGreaterThan(0, $ttl);
            $this->cache[$key] = $payload;
        });
        $cache->method('removeDisplayPayload')->willReturnCallback(function (string $key): void {
            unset($this->cache[$key]);
        });

        return new TrialUsageService($this->tokens(), $crawler, $identity, $cache);
    }

    private function tokens(): ProTokenService
    {
        $tokens = $this->createMock(ProTokenService::class);
        $tokens->method('getValidToken')->willReturn(new AccessTokenResult('trial-jwt', 3600, time(), 'trial', ['crawler']));

        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    private function trialPayload(int $jobsUsed, string $nextScanAt = ''): array
    {
        return [
            'entitlement' => 'trial',
            'remoteCrawlerVisible' => true,
            'freeDaily' => null,
            'trialDaily' => [
                'limitsEnforced' => true,
                'available' => $jobsUsed < 5,
                'jobsUsed' => $jobsUsed,
                'jobsLimit' => 5,
                'scansRemaining' => max(0, 5 - $jobsUsed),
                'maxPagesPerJob' => 10,
                'windowHours' => 24,
                'nextScanAt' => $nextScanAt === '' ? null : $nextScanAt,
            ],
        ];
    }
}
