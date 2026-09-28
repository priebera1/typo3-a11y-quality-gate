<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\MonitoringRunRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResult;
use Priebera\A11yQualityGate\Pro\Dto\RemoteScanRequestData;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanInputResolver;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\MonitoringNotifier;
use Priebera\A11yQualityGate\Service\RemoteMonitoringService;
use Priebera\A11yQualityGate\Service\RemoteScanFindingIndex;
use Priebera\A11yQualityGate\Service\RemoteScanPairingService;
use Priebera\A11yQualityGate\Service\ScanComparisonService;
use Priebera\A11yQualityGate\Service\SiteLanguageService;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Monitoring mails only a change in state: a new regression, an incomplete scan or a failure — never an
 * unchanged regression twice, never "all clear", and never without a PRO or Agency licence. It says "clear"
 * only when the current scan checked everything the baseline had checked, and a run with missing coverage
 * never becomes the next baseline.
 */
final class RemoteMonitoringServiceTest extends TestCase
{
    private const JOB = '11111111-1111-4111-8111-111111111111';
    private const BASELINE_JOB = '22222222-2222-4222-8222-222222222222';
    private const CURRENT_UID = 20;
    private const BASELINE_UID = 19;

    private ProCrawlerService $crawler;
    private RemoteScanRepository $scans;
    private MonitoringRunRepository $runs;
    private MonitoringNotifier $notifier;
    private RemoteScanPairingService $pairing;
    private RemoteScanFindingIndex $index;
    private string $plan = 'pro';
    private bool $trial = false;
    /** @var list<array<string, mixed>> */
    private array $runUpdates = [];
    /** @var array<int, array<string, array<string, mixed>>> finding index per scan uid */
    private array $indexes = [];

    protected function setUp(): void
    {
        $this->crawler = $this->createMock(ProCrawlerService::class);
        $this->scans = $this->createMock(RemoteScanRepository::class);
        $this->runs = $this->createMock(MonitoringRunRepository::class);
        $this->notifier = $this->createMock(MonitoringNotifier::class);
        $this->pairing = $this->getMockBuilder(RemoteScanPairingService::class)->disableOriginalConstructor()->onlyMethods(['findPreviousCompatible'])->getMock();
        $this->index = $this->createMock(RemoteScanFindingIndex::class);
        $this->index->method('build')->willReturnCallback(fn (array $scan): array => $this->indexes[(int)$scan['uid']] ?? []);
        $this->runs->method('update')->willReturnCallback(function (int $uid, array $data): void {
            $this->runUpdates[] = $data;
        });
    }

    #[Test]
    public function aTrialIsNotMonitored(): void
    {
        $this->trial = true;
        $this->crawler->expects(self::never())->method('submit');

        self::assertSame('not_entitled', $this->monitor()['outcome']);
    }

    #[Test]
    public function aPendingScanThatIsStillRunningIsNotJoinedByANewOne(): void
    {
        $this->runs->method('findPending')->willReturn(['uid' => 3, 'site_identifier' => 'main', 'language_uid' => 0, 'job_id' => self::JOB]);
        $this->scans->method('findScanByJobId')->willReturn(['uid' => 20, 'status' => 'running', 'persisted_at' => 0]);
        $this->crawler->expects(self::never())->method('submit');

        $result = $this->monitor(maxWait: 30);

        self::assertSame('waiting', $result['outcome']);
    }

    #[Test]
    public function theFirstCompleteScanBecomesTheBaselineWithoutMail(): void
    {
        $this->submitsAndCompletes(current: ['/a' => $this->page(['image-alt' => 1]), '/b' => $this->page([])]);
        $this->pairing->method('findPreviousCompatible')->willReturn(null);
        $this->notifier->expects(self::never())->method('send');

        self::assertSame('no_baseline', $this->monitor()['outcome']);
        self::assertSame(1, $this->runUpdates[0]['coverage_complete']);
    }

    #[Test]
    public function aFirstScanWithIncompleteFindingsIsNoBaseline(): void
    {
        $this->submitsAndCompletes(current: ['/a' => $this->page(['image-alt' => 1], complete: false)]);
        $this->pairing->method('findPreviousCompatible')->willReturn(null);
        $this->notifier->expects(self::once())->method('send')->willReturn(true);

        self::assertSame('incomplete', $this->monitor()['outcome']);
        self::assertSame(0, $this->runUpdates[0]['coverage_complete']);
    }

    #[Test]
    public function aCompleteUnchangedScanIsClearWithoutMailAndBecomesTheBaseline(): void
    {
        $pages = ['/a' => $this->page(['image-alt' => 1]), '/b' => $this->page([])];
        $this->submitsAndCompletes(current: $pages, baseline: $pages);
        $this->notifier->expects(self::never())->method('send');

        $result = $this->monitor();

        self::assertSame('clear', $result['outcome']);
        self::assertSame(2, $result['summary']['comparedPages']);
        self::assertSame(0, $result['summary']['coverageGaps']);
        self::assertSame(1, $this->runUpdates[0]['coverage_complete']);
        self::assertSame(self::BASELINE_JOB, $this->runUpdates[0]['baseline_job_id']);
    }

    #[Test]
    public function aCompleteRegressionIsMailedOnce(): void
    {
        $this->submitsAndCompletes(
            current: ['/a' => $this->page(['image-alt' => 1, 'color-contrast' => 2])],
            baseline: ['/a' => $this->page(['image-alt' => 1])],
        );
        $this->runs->method('findPreviousEvaluated')->willReturn(['state_fingerprint' => '']);
        $this->notifier->expects(self::once())->method('send')->willReturn(true);

        $result = $this->monitor();

        self::assertSame('regression', $result['outcome']);
        self::assertTrue($result['notified']);
        self::assertSame(1, $result['summary']['newIssueTypes']);
        self::assertStringStartsWith('regression:', (string)$this->runUpdates[0]['state_fingerprint']);
        self::assertLessThanOrEqual(100, strlen((string)$this->runUpdates[0]['state_fingerprint']), 'fits tx_a11y_monitoring_run.state_fingerprint');
    }

    #[Test]
    public function anUnchangedRegressionIsNotMailedAgain(): void
    {
        $current = ['/a' => $this->page(['color-contrast' => 2])];
        $baseline = ['/a' => $this->page([])];
        $this->submitsAndCompletes(current: $current, baseline: $baseline);
        $comparison = (new ScanComparisonService($this->index))->compareIndexes($this->indexes[self::BASELINE_UID], $this->indexes[self::CURRENT_UID]);
        $fingerprint = 'regression:' . (new ScanComparisonService($this->index))->regressionFingerprint($comparison);
        $this->runs->method('findPreviousEvaluated')->willReturn(['state_fingerprint' => $fingerprint, 'notified_at' => 1700000500]);
        $this->notifier->expects(self::never())->method('send');

        $result = $this->monitor();

        self::assertSame('regression', $result['outcome']);
        self::assertFalse($result['notified']);
        self::assertSame(1700000500, $this->runUpdates[0]['notified_at'], 'the delivery of this state is carried forward');
    }

    #[Test]
    public function theSameRegressionGettingWorseIsNotifiedAgain(): void
    {
        $this->submitsAndCompletes(current: ['/a' => $this->page(['color-contrast' => 5])], baseline: ['/a' => $this->page([])]);
        $earlier = (new ScanComparisonService($this->index))->compareIndexes(
            $this->keyed(['/a' => $this->page([])]),
            $this->keyed(['/a' => $this->page(['color-contrast' => 2])]),
        );
        $this->runs->method('findPreviousEvaluated')->willReturn([
            'state_fingerprint' => 'regression:' . (new ScanComparisonService($this->index))->regressionFingerprint($earlier),
            'notified_at' => 1700000500,
        ]);
        $this->notifier->expects(self::once())->method('send')->willReturn(true);

        self::assertTrue($this->monitor()['notified']);
    }

    #[Test]
    public function aFailedDeliveryIsRetriedByTheNextRun(): void
    {
        $this->submitsAndCompletes(current: ['/a' => $this->page(['color-contrast' => 2])], baseline: ['/a' => $this->page([])]);
        $comparison = (new ScanComparisonService($this->index))->compareIndexes($this->indexes[self::BASELINE_UID], $this->indexes[self::CURRENT_UID]);
        // Same state as the last run, whose mail could not be sent.
        $this->runs->method('findPreviousEvaluated')->willReturn([
            'state_fingerprint' => 'regression:' . (new ScanComparisonService($this->index))->regressionFingerprint($comparison),
            'notified_at' => 0,
        ]);
        $this->notifier->expects(self::once())->method('send')->willReturn(true);

        $result = $this->monitor();

        self::assertTrue($result['notified']);
        self::assertGreaterThan(0, $this->runUpdates[0]['notified_at']);
    }

    #[Test]
    public function aDeliveryThatFailsIsNotRecordedAsNotified(): void
    {
        $this->submitsAndCompletes(current: ['/a' => $this->page(['color-contrast' => 2])], baseline: ['/a' => $this->page([])]);
        $this->notifier->expects(self::once())->method('send')->willReturn(false);

        $result = $this->monitor();

        self::assertFalse($result['notified']);
        self::assertSame(0, $this->runUpdates[0]['notified_at']);
    }

    #[Test]
    public function aFailedScanIsReportedAsAFailureNotARegression(): void
    {
        $this->submitsAndCompletes(status: 'failed');
        $this->pairing->expects(self::never())->method('findPreviousCompatible');
        $this->notifier->expects(self::once())->method('send')
            ->with(['qa@example.org'], self::callback(static fn (array $report): bool => $report['outcome'] === 'failed' && $report['comparison'] === null))
            ->willReturn(true);

        self::assertSame('failed', $this->monitor()['outcome']);
        self::assertSame(0, $this->runUpdates[0]['coverage_complete']);
    }

    #[Test]
    public function aScanWithIncompletelyStoredFindingsIsIncompleteNeverClear(): void
    {
        $this->submitsAndCompletes(
            current: ['/a' => $this->page(['image-alt' => 1]), '/b' => $this->page([], complete: false)],
            baseline: ['/a' => $this->page(['image-alt' => 1]), '/b' => $this->page(['label' => 1])],
        );
        $this->notifier->expects(self::once())->method('send')
            ->with(['qa@example.org'], self::callback(static fn (array $report): bool => $report['outcome'] === 'incomplete'
                && $report['gaps'] === [['url' => 'https://example.org/b', 'reason' => 'evidence_incomplete']]))
            ->willReturn(true);

        $result = $this->monitor();

        self::assertSame('incomplete', $result['outcome']);
        self::assertSame(0, $this->runUpdates[0]['coverage_complete'], 'never the next baseline');
    }

    #[Test]
    public function missingCurrentCoverageIsIncompleteNotClear(): void
    {
        // The baseline checked /a and /b; this run only reached /a, which is unchanged. Previously "clear".
        $this->submitsAndCompletes(
            current: ['/a' => $this->page(['image-alt' => 1])],
            baseline: ['/a' => $this->page(['image-alt' => 1]), '/b' => $this->page(['label' => 1])],
        );
        $this->notifier->expects(self::once())->method('send')->willReturn(true);

        $result = $this->monitor();

        self::assertSame('incomplete', $result['outcome']);
        self::assertSame(1, $result['summary']['coverageGaps']);
        self::assertSame(0, $this->runUpdates[0]['coverage_complete']);
        self::assertStringStartsWith('incomplete:', (string)$this->runUpdates[0]['state_fingerprint']);
    }

    #[Test]
    public function aScanWithoutPagesIsIncomplete(): void
    {
        $this->submitsAndCompletes(current: [], baseline: ['/a' => $this->page([])]);
        $this->notifier->expects(self::once())->method('send')->willReturn(true);

        self::assertSame('incomplete', $this->monitor()['outcome']);
    }

    #[Test]
    public function aPageThatFailedInBothScansIsAKnownBrokenUrlNotLostCoverage(): void
    {
        $pages = ['/a' => $this->page(['image-alt' => 1]), '/gone' => $this->page([], failed: true)];
        $this->submitsAndCompletes(current: $pages, baseline: $pages);

        self::assertSame('clear', $this->monitor()['outcome']);
        self::assertSame(1, $this->runUpdates[0]['coverage_complete']);
    }

    #[Test]
    public function theBaselineIsTheLastCompleteMonitoringScanNotTheNewestCompatibleOne(): void
    {
        $pages = ['/a' => $this->page(['image-alt' => 1])];
        $this->submitsAndCompletes(current: $pages, baseline: $pages);
        // Trusted run found: the newest compatible scan (possibly an incomplete monitoring scan) is not asked for.
        $this->pairing->expects(self::never())->method('findPreviousCompatible');

        self::assertSame('clear', $this->monitor()['outcome']);
        self::assertSame(self::BASELINE_JOB, $this->runUpdates[0]['baseline_job_id']);
    }

    #[Test]
    public function withoutATrustedRunTheFallbackBaselineSkipsIncompleteAndUntrustedScans(): void
    {
        $this->submitsAndCompletes(current: ['/a' => $this->page([])], trustedRun: false);
        $this->indexes[31] = ['https://example.org/a' => $this->page([], complete: false) + ['url' => 'https://example.org/a']];
        $this->indexes[32] = ['https://example.org/a' => $this->page([]) + ['url' => 'https://example.org/a']];
        $this->runs->method('isUntrustedRunJob')->willReturnCallback(static fn (string $jobId): bool => $jobId === 'untrusted');
        $accepted = null;
        $this->pairing->method('findPreviousCompatible')->willReturnCallback(function (array $scan, ?\Closure $accept) use (&$accepted): ?array {
            $accepted = [
                'incomplete' => $accept(['uid' => 31, 'job_id' => 'user-scan']),
                'untrusted' => $accept(['uid' => 32, 'job_id' => 'untrusted']),
                'complete' => $accept(['uid' => 32, 'job_id' => 'user-scan-2']),
            ];

            return null;
        });

        $this->monitor();

        self::assertSame(['incomplete' => false, 'untrusted' => false, 'complete' => true], $accepted);
    }

    #[Test]
    public function aSiteWithAnActiveScanIsNotScannedAgain(): void
    {
        $this->scans->method('findLatestActiveScanBySite')->willReturn(['uid' => 9, 'status' => 'running']);
        $this->crawler->expects(self::never())->method('submit');

        self::assertSame('busy', $this->monitor()['outcome']);
    }

    /**
     * @param array<string, array<string, mixed>> $current page path => page
     * @param array<string, array<string, mixed>>|null $baseline page path => page, stored as the trusted run's scan
     */
    private function submitsAndCompletes(string $status = 'completed', array $current = [], ?array $baseline = null, bool $trustedRun = true): void
    {
        $this->crawler->method('submit')->willReturn(new CrawlerSubmitResult(self::JOB, 'queued', 'sitemap', null));
        $this->runs->method('insertSubmitted')->willReturn(4);
        $scan = [
            'uid' => self::CURRENT_UID,
            'job_id' => self::JOB,
            'site_identifier' => 'main',
            'scan_scope' => 'site',
            'source_type' => 'sitemap',
            'language_uid' => 0,
            'start_url' => 'https://example.org/',
            'status' => $status,
            'persisted_at' => $status === 'completed' ? 1700000000 : 0,
            'finished_at' => 1700000000,
        ];
        $baselineScan = ['uid' => self::BASELINE_UID, 'job_id' => self::BASELINE_JOB, 'finished_at' => 1690000000] + $scan;
        $this->scans->method('findScanByJobId')->willReturnCallback(
            static fn (string $jobId): ?array => match ($jobId) {
                self::JOB => $scan,
                self::BASELINE_JOB => ['status' => 'completed', 'persisted_at' => 1690000000] + $baselineScan,
                default => null,
            }
        );
        $this->indexes[self::CURRENT_UID] = $this->keyed($current);
        if ($baseline !== null) {
            $this->indexes[self::BASELINE_UID] = $this->keyed($baseline);
        }
        if ($baseline !== null && $trustedRun) {
            $this->runs->method('findLatestTrustedRun')->willReturn(['uid' => 2, 'job_id' => self::BASELINE_JOB, 'coverage_complete' => 1]);
        }
    }

    /**
     * @param array<string, int> $rules rule id => occurrences
     * @return array<string, mixed>
     */
    private function page(array $rules, bool $complete = true, bool $failed = false): array
    {
        return [
            'remotePageUid' => 1,
            'failed' => $failed,
            'httpStatus' => $failed ? 500 : 200,
            'evidenceComplete' => $complete && !$failed,
            'rules' => array_map(static fn (int $occurrences): array => ['occurrences' => $occurrences, 'impact' => 'serious'], $rules),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $pages
     * @return array<string, array<string, mixed>>
     */
    private function keyed(array $pages): array
    {
        $index = [];
        foreach ($pages as $path => $page) {
            $index['https://example.org' . $path] = ['url' => 'https://example.org' . $path] + $page;
        }

        return $index;
    }

    /** @return array<string, mixed> */
    private function monitor(int $maxWait = 0): array
    {
        $capabilities = $this->createMock(ProCapabilityService::class);
        $capabilities->method('getStatus')->willReturnCallback(fn (): ProStatusViewModel => new ProStatusViewModel(
            configured: true,
            valid: true,
            proAvailable: true,
            plan: $this->plan,
            features: [],
            reason: null,
            reasonLabel: null,
            statusLabel: '',
            showProHints: false,
            hasCrawler: true,
            hasExportPdf: true,
            hasMultiSite: $this->plan === 'agency',
            hasProRules: true,
            isTrial: $this->trial,
        ));

        $inputs = $this->createMock(RemoteScanInputResolver::class);
        $inputs->method('resolveForOverview')->willReturn(new RemoteScanRequestData(
            siteIdentifier: 'main',
            domain: 'example.org',
            startUrl: 'https://example.org/',
            sitemapUrl: 'https://example.org/sitemap.xml',
            sourceType: RemoteScanSourceType::Sitemap,
            maxPages: 500,
            followLinks: false,
            axeLocale: 'en',
        ));
        $settings = $this->createMock(RemoteScanAccessSettingsService::class);
        $settings->method('buildForSite')->willReturn([
            'scannerPreviewToken' => '', 'scannerTokenLength' => 0, 'resolvedRulesetUid' => 0, 'resolvedRulesetSiteIdentifier' => '',
            'httpAuthUser' => '', 'httpAuthPass' => '', 'excludedPatterns' => [], 'priorityUrls' => [], 'cookieSelectors' => [],
        ]);
        $recovery = $this->createMock(RemoteScanRecoveryService::class);
        $recovery->method('recoverScanIfNeeded')->willReturnArgument(0);
        $lock = $this->createMock(LockingStrategyInterface::class);
        $lock->method('acquire')->willReturn(true);
        $locks = $this->createMock(LockFactory::class);
        $locks->method('createLocker')->willReturn($lock);
        $context = $this->createMock(ExtensionContextService::class);
        $context->method('getExtensionVersion')->willReturn('1.9.6');
        $context->method('getNormalizedDomainFromSiteBase')->willReturn('example.org');

        $service = new RemoteMonitoringService(
            $this->createMock(SiteLanguageService::class),
            $inputs,
            $capabilities,
            $context,
            $this->crawler,
            $this->scans,
            $recovery,
            $settings,
            $this->pairing,
            new ScanComparisonService($this->index),
            $this->index,
            $this->runs,
            $this->notifier,
            $locks,
        );

        return $service->run(
            new Site('main', 1, ['base' => 'https://example.org/']),
            0,
            500,
            $maxWait,
            ['qa@example.org'],
            '',
            static function (int $seconds): void {
            },
        );
    }
}
