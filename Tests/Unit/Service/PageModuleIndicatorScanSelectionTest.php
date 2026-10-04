<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\SourceStateRepository;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanSubmissionTracker;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\PageModuleIndicatorService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * Which scan the Page module panel shows and follows for page 42: its own scans only, a scan that is still being
 * submitted, a moving bar while page counts measure nothing, and the newest scan that checked the page as "Last scan".
 */
final class PageModuleIndicatorScanSelectionTest extends TestCase
{
    private const PAGE = 42;

    /** @var array<string, mixed> */
    private array $registry = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = [];
    }

    #[Test]
    public function aSinglePageScanOfAnotherPageIsNotFollowedHere(): void
    {
        $variables = $this->build(activeScan: $this->activeScan(['scan_scope' => 'page', 'page_uid' => 7]));

        self::assertFalse($variables['isRunning']);
        self::assertNotSame('running', $variables['rows'][1]['state']);
    }

    #[Test]
    public function aScanOfThisPageAndASiteCrawlAreFollowed(): void
    {
        self::assertTrue($this->build(activeScan: $this->activeScan(['scan_scope' => 'page', 'page_uid' => self::PAGE]))['isRunning']);
        self::assertTrue($this->build(activeScan: $this->activeScan(['scan_scope' => 'site', 'page_uid' => 0]))['isRunning']);
    }

    #[Test]
    public function aScanOfAnotherLanguageIsNotFollowed(): void
    {
        $variables = $this->build(activeScan: $this->activeScan(['scan_scope' => 'page', 'page_uid' => self::PAGE, 'language_uid' => 1]));

        self::assertFalse($variables['isRunning']);
    }

    #[Test]
    public function aScanStillBeingSubmittedForThisPageRendersAsStartingAndIsFollowed(): void
    {
        $tracker = new RemoteScanSubmissionTracker($this->registry());
        $tracker->begin('main', 'page', self::PAGE, 0);

        $variables = $this->build(tracker: $tracker);

        self::assertTrue($variables['isRunning'], 'the panel must be followed, or it keeps the previous result');
        self::assertSame('running', $variables['rows'][1]['state']);
        self::assertSame('pageModuleIndicator.meta.remoteStarting', $variables['meta']);
        self::assertSame('is-indeterminate', $variables['progress']['modeClass']);

        $tracker->end('main');
        self::assertFalse($this->build(tracker: $tracker)['isRunning'], 'a submit that ended without a scan ends the following');
    }

    #[Test]
    public function aScanBeingSubmittedForAnotherPageDoesNotMarkThisPage(): void
    {
        $tracker = new RemoteScanSubmissionTracker($this->registry());
        $tracker->begin('main', 'page', 7, 0);

        self::assertFalse($this->build(tracker: $tracker)['isRunning']);

        $tracker->begin('main', 'site', 0, 0);
        self::assertTrue($this->build(tracker: $tracker)['isRunning'], 'a site crawl being submitted covers every page');
    }

    #[Test]
    public function aSinglePageScanShowsAMovingBarInsteadOfAStillZeroOfOne(): void
    {
        $progress = $this->build(activeScan: $this->activeScan(['pages_scanned' => 0, 'pages_total' => 1]))['progress'];

        self::assertSame('is-indeterminate', $progress['modeClass']);
    }

    #[Test]
    public function aCrawlShowsItsPageProgressOnceAPageIsDone(): void
    {
        $starting = $this->build(activeScan: $this->activeScan(['scan_scope' => 'site', 'page_uid' => 0, 'pages_scanned' => 0, 'pages_total' => 19]));
        self::assertSame('is-indeterminate', $starting['progress']['modeClass']);

        $progress = $this->build(activeScan: $this->activeScan(['scan_scope' => 'site', 'page_uid' => 0, 'pages_scanned' => 9, 'pages_total' => 18]))['progress'];
        self::assertSame(['percent' => 50, 'modeClass' => 'is-determinate'], $progress);
    }

    #[Test]
    public function theFrontendRowShowsANewerSiteCrawlOfThePageOverAnOlderSinglePageScan(): void
    {
        $variables = $this->build(
            pageScanPage: ['uid' => 1, 'issues_count' => 2, 'remote_scan_finished_at' => 1_790_000_000],
            urlPage: ['uid' => 2, 'issues_count' => 3, 'is_failed' => 0, 'remote_scan_finished_at' => 1_790_003_600],
        );

        self::assertSame('3 issue types', $variables['rows'][1]['headline']);
        self::assertStringContainsString(date('d.m.Y H:i', 1_790_003_600), $variables['rows'][1]['meta']);
    }

    #[Test]
    public function aNewerFailedPageNeverReplacesAPageThatWasChecked(): void
    {
        $variables = $this->build(
            pageScanPage: ['uid' => 1, 'issues_count' => 2, 'remote_scan_finished_at' => 1_790_000_000],
            urlPage: ['uid' => 2, 'issues_count' => 0, 'is_failed' => 1, 'remote_scan_finished_at' => 1_790_003_600],
        );

        self::assertSame('2 issue types', $variables['rows'][1]['headline']);
        self::assertStringContainsString(date('d.m.Y H:i', 1_790_000_000), $variables['rows'][1]['meta']);
    }

    #[Test]
    public function theContentRowCountsASiteScanThatCheckedThePage(): void
    {
        $variables = $this->build(
            pageScan: ['finished_at' => 1_790_000_000],
            subtreeScan: ['finished_at' => 1_790_007_200],
        );

        self::assertSame('ok', $variables['rows'][0]['state']);
        self::assertStringContainsString(date('d.m.Y H:i', 1_790_007_200), $variables['rows'][0]['meta']);
    }

    #[Test]
    public function aPageOnlyASiteScanCheckedIsNoLongerNotScanned(): void
    {
        $variables = $this->build(subtreeScan: ['finished_at' => 1_790_007_200]);

        self::assertSame('ok', $variables['rows'][0]['state']);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function activeScan(array $overrides = []): array
    {
        return $overrides + [
            'job_id' => 'job-1',
            'site_identifier' => 'main',
            'status' => 'active',
            'scan_scope' => 'page',
            'page_uid' => self::PAGE,
            'language_uid' => 0,
            'pages_scanned' => 0,
            'pages_total' => 1,
        ];
    }

    private function registry(): Registry
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(fn (string $namespace, string $key, mixed $default = null): mixed => $this->registry[$namespace . '/' . $key] ?? $default);
        $registry->method('set')->willReturnCallback(function (string $namespace, string $key, mixed $value): void {
            $this->registry[$namespace . '/' . $key] = $value;
        });
        $registry->method('remove')->willReturnCallback(function (string $namespace, string $key): void {
            unset($this->registry[$namespace . '/' . $key]);
        });

        return $registry;
    }

    /**
     * @param array<string, mixed>|null $activeScan
     * @param array<string, mixed>|null $pageScanPage
     * @param array<string, mixed>|null $urlPage
     * @param array<string, mixed>|null $pageScan
     * @param array<string, mixed>|null $subtreeScan
     * @return array<string, mixed>
     */
    private function build(
        ?array $activeScan = null,
        ?RemoteScanSubmissionTracker $tracker = null,
        ?array $pageScanPage = null,
        ?array $urlPage = null,
        ?array $pageScan = null,
        ?array $subtreeScan = null,
    ): array {
        $proStatus = $this->createMock(ProStatusResolverService::class);
        $proStatus->method('resolveForSite')->willReturn(new ProStatusViewModel(
            configured: true,
            valid: true,
            proAvailable: true,
            plan: 'pro',
            features: ['crawler'],
            reason: null,
            reasonLabel: null,
            statusLabel: 'Licence active — Pro',
            showProHints: true,
            hasCrawler: true,
            hasExportPdf: true,
            hasMultiSite: false,
            hasProRules: true,
            domain: 'example.org',
        ));
        $issues = $this->createMock(IssueRepository::class);
        $issues->method('countOpenBySeverity')->willReturn(['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0]);
        $scans = $this->createMock(ScanRepository::class);
        $scans->method('findLastCompletedPageScan')->willReturn($pageScan);
        $scans->method('findLastCompletedSubtreeScanCoveringPage')->with('main', self::PAGE, 0)->willReturn($subtreeScan);
        $remoteScans = $this->createMock(RemoteScanRepository::class);
        $remoteScans->method('findLatestActiveScanBySite')->willReturn($activeScan);
        $remoteScans->method('findLatestPageForCompletedPageScan')->willReturn($pageScanPage);
        $remoteScans->method('findLatestPageByUrl')->willReturn($urlPage);
        $recovery = $this->createMock(RemoteScanRecoveryService::class);
        $recovery->method('recoverScanIfNeeded')->willReturnArgument(0);
        $scanStatus = $this->createMock(ScanStatusService::class);
        $scanStatus->method('getStatus')->willReturn([]);
        $backendContext = $this->createMock(BackendContextService::class);
        $backendContext->method('translate')->willReturnCallback(static fn (string $key): string => match ($key) {
            'pageModuleIndicator.row.lastScan' => 'Last scan %s',
            'pageModuleIndicator.metric.issueTypes.plural' => '%d issue types',
            default => $key,
        });
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/aqg'));
        $urls = $this->createMock(FrontendPageUrlService::class);
        $urls->method('resolveForPage')->willReturn('https://example.org/page');

        $service = new PageModuleIndicatorService(
            $issues,
            $this->createMock(SourceStateRepository::class),
            $scans,
            $remoteScans,
            $proStatus,
            $recovery,
            $scanStatus,
            $backendContext,
            $uriBuilder,
            $this->createMock(ViewFactoryInterface::class),
            $urls,
            $this->createMock(FreeRemotePreviewService::class),
            null,
            $tracker,
        );

        $variables = $service->buildViewData(self::PAGE, new Site('main', 1, ['base' => 'https://example.org/']), 0);
        self::assertIsArray($variables);

        return $variables;
    }
}
