<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\AbstractBackendModuleController;
use Priebera\A11yQualityGate\Controller\OverviewController;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanPersistenceService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Service\DateTimeService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;

/**
 * A frontend scan submitted under an earlier licence or Agency project stayed "active" in TYPO3: the AQG service
 * answers it with 403 for the current token, so every Overview load restored it, reported it as failed and kept the
 * scan buttons disabled. Such a job is recorded as failed and never restored.
 */
final class RemoteScanStaleJobTest extends TestCase
{
    private const JOB = 'f90350b0-0732-4761-9f38-2ef8278fbce4';

    #[Test]
    public function aJobTheServiceNoLongerServesToThisInstallationIsRecordedAsFailed(): void
    {
        $repository = $this->createMock(RemoteScanRepository::class);
        $repository->expects(self::once())->method('markFailed')
            ->with(self::JOB, 'Remote crawler job is no longer accessible for the current token.');

        self::assertTrue($this->recovery($repository)->discardUnavailableJob(
            ['job_id' => self::JOB, 'status' => 'active', 'persisted_at' => 0],
            new \RuntimeException('Crawler request failed', 0, new ApiRequestFailedException('Forbidden', 403, null, 'forbidden_resource')),
        ));
    }

    #[Test]
    public function aJobTheServiceNoLongerKnowsIsRecordedAsFailed(): void
    {
        $repository = $this->createMock(RemoteScanRepository::class);
        $repository->expects(self::once())->method('markFailed')->with(self::JOB, 'Remote crawler job no longer exists.');

        self::assertTrue($this->recovery($repository)->discardUnavailableJob(
            ['job_id' => self::JOB, 'status' => 'queued'],
            new ApiRequestFailedException('Not found', 404, null, 'not_found'),
        ));
    }

    #[Test]
    public function aTransientFailureOrAFinishedScanIsLeftAlone(): void
    {
        $repository = $this->createMock(RemoteScanRepository::class);
        $repository->expects(self::never())->method('markFailed');
        $recovery = $this->recovery($repository);

        self::assertFalse($recovery->discardUnavailableJob(
            ['job_id' => self::JOB, 'status' => 'active'],
            new ApiRequestFailedException('Too many requests', 429, null, 'rate_limited'),
        ));
        self::assertFalse($recovery->discardUnavailableJob(
            ['job_id' => self::JOB, 'status' => 'completed', 'persisted_at' => 1_790_000_000],
            new ApiRequestFailedException('Forbidden', 403, null, 'forbidden_resource'),
        ));
    }

    #[Test]
    public function theOverviewRestoresOnlyAScanTheServiceStillRuns(): void
    {
        $stale = ['job_id' => self::JOB, 'site_identifier' => 'aqg', 'status' => 'active', 'scan_scope' => 'page', 'persisted_at' => 0];

        $failed = $this->overviewActiveScan($stale, ['status' => 'failed'] + $stale);
        self::assertNull($failed, 'a job recovery recorded as failed is not restored as running');

        $running = $this->overviewActiveScan($stale, ['status' => 'running', 'pages_scanned' => 0] + $stale);
        self::assertSame('running', $running['status'] ?? null);
    }

    /**
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $recovered
     * @return array<string, mixed>|null
     */
    private function overviewActiveScan(array $stored, array $recovered): ?array
    {
        $repository = $this->createMock(RemoteScanRepository::class);
        $repository->method('findLatestActiveScanBySite')->with('aqg')->willReturn($stored);
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteBaseByIdentifier')->with('aqg')->willReturn('https://typo3test.example/');
        $recovery = $this->createMock(RemoteScanRecoveryService::class);
        $recovery->expects(self::once())->method('recoverScanIfNeeded')->with($stored, 'https://typo3test.example/')->willReturn($recovered);

        $controller = (new \ReflectionClass(OverviewController::class))->newInstanceWithoutConstructor();
        \Closure::bind(function () use ($repository, $recovery): void {
            $this->remoteScanRepository = $repository;
            $this->remoteScanRecoveryService = $recovery;
        }, $controller, OverviewController::class)();
        \Closure::bind(function () use ($sites): void {
            $this->siteResolutionService = $sites;
        }, $controller, AbstractBackendModuleController::class)();

        return (new \ReflectionMethod(OverviewController::class, 'resolveOverviewActiveRemoteScan'))
            ->invoke($controller, 'aqg', true, 728, 0);
    }

    private function recovery(RemoteScanRepository $repository): RemoteScanRecoveryService
    {
        return new RemoteScanRecoveryService(
            $repository,
            $this->createMock(ProCrawlerService::class),
            $this->createMock(RemoteScanPersistenceService::class),
            $this->createMock(ExtensionContextService::class),
            $this->createMock(DateTimeService::class),
            $this->createMock(FreeRemotePreviewService::class),
            new RemoteScanErrorPresenter(),
        );
    }
}
