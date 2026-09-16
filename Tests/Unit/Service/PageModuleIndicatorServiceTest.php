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
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\PageModuleIndicatorService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The Page module must describe the remote option an installation really has: Free installations get
 * a limited number of selected-page scans per day (Free Remote Preview), so "only in PRO" copy is wrong
 * for them, and quota numbers may only come from the cached entitlement status.
 */
final class PageModuleIndicatorServiceTest extends TestCase
{
    private const LABELS = [
        'pageModuleIndicator.freeHint.available' => 'FREE-AVAILABLE',
        'pageModuleIndicator.freeHint.remaining' => 'FREE-LEFT %1$d/%2$d',
        'pageModuleIndicator.freeHint.limitReached' => 'FREE-LIMIT-REACHED',
        'pageModuleIndicator.freeHint.nextScans' => 'NEXT %s',
        'pageModuleIndicator.freeHint.unavailable' => 'FREE-UNAVAILABLE',
        'pageModuleIndicator.freeHint.open' => 'OPEN-FRONTEND-SCAN',
        'pageModuleIndicator.proHint.remoteScanAvailable' => 'PAID-RUN-FRONTEND-SCAN',
        'pageModuleIndicator.metric.errors' => '%d ERRORS',
    ];

    /** @var array<string, mixed> */
    private array $assigned = [];

    #[Test]
    public function freeInstallationWithCachedQuotaShowsRemainingFreeScans(): void
    {
        $free = $this->createMock(FreeRemotePreviewService::class);
        $free->expects(self::once())
            ->method('peekEntitlementStatus')
            ->with('https://example.org/', 'main')
            ->willReturn($this->freeStatus('FREE_AVAILABLE', 1, 5, 4));
        $free->expects(self::never())->method('getEntitlementStatus');

        $this->render($this->freeLicence(), $free);

        $hint = $this->assigned['remoteHint'];
        self::assertSame('FREE', $hint['tag']);
        self::assertSame('FREE-LEFT 4/5', $hint['text']);
        self::assertSame('OPEN-FRONTEND-SCAN', $hint['linkLabel']);
        self::assertStringContainsString('route=web_a11y', $hint['linkUrl']);
        self::assertStringContainsString('aqgSource=remote', $hint['linkUrl']);
        self::assertStringContainsString('id=42', $hint['linkUrl']);
        self::assertSame('local', $this->assigned['scanMode'], 'The Page module scan button must not consume Free credits.');
    }

    #[Test]
    public function freeInstallationWithExhaustedQuotaSaysTheDailyLimitIsReached(): void
    {
        $status = $this->freeStatus('FREE_USED_TODAY', 5, 5, 0);
        $status['resetsAt'] = '2026-09-16T00:00:00Z';
        $free = $this->createMock(FreeRemotePreviewService::class);
        $free->method('peekEntitlementStatus')->willReturn($status);

        $this->render($this->freeLicence(), $free);

        $hint = $this->assigned['remoteHint'];
        self::assertSame('FREE', $hint['tag']);
        self::assertStringStartsWith('FREE-LIMIT-REACHED NEXT ', $hint['text']);
        self::assertStringContainsString('2026', $hint['text']);
    }

    #[Test]
    public function freeInstallationWithoutCachedStatusStaysGenericWithoutCallingTheApi(): void
    {
        $free = $this->createMock(FreeRemotePreviewService::class);
        $free->method('peekEntitlementStatus')->willReturn(null);
        $free->expects(self::never())->method('getEntitlementStatus');

        $this->render($this->freeLicence(), $free);

        self::assertSame('FREE-AVAILABLE', $this->assigned['remoteHint']['text']);
        self::assertSame('FREE', $this->assigned['remoteHint']['tag']);
    }

    #[Test]
    public function unavailableFreeStatusIsReportedWithBoundedWording(): void
    {
        $free = $this->createMock(FreeRemotePreviewService::class);
        $free->method('peekEntitlementStatus')->willReturn([
            'isFree' => true,
            'entitlement' => 'free_daily',
            'state' => 'API_UNAVAILABLE',
            'jobsUsed' => null,
            'jobsLimit' => null,
            'message' => 'Free Remote Preview is temporarily unavailable.',
        ]);

        $this->render($this->freeLicence(), $free);

        self::assertSame('FREE-UNAVAILABLE', $this->assigned['remoteHint']['text']);
    }

    #[Test]
    public function freeInstallationReadsOnlyItsFreePreviewResultForThePage(): void
    {
        $remoteScans = $this->createMock(RemoteScanRepository::class);
        $remoteScans->expects(self::once())
            ->method('findLastCompletedPageScanByPageOrUrl')
            ->with('main', 42, 0, 'https://example.org/page', true)
            ->willReturn(['scan_scope' => 'page', 'finished_at' => 1789000000, 'issues_total' => 3, 'is_free_preview' => 1]);
        $remoteScans->expects(self::never())->method('findLastCompletedRelevantScan');
        $remoteScans->expects(self::never())->method('findLatestPageByUrl');

        $free = $this->createMock(FreeRemotePreviewService::class);
        $free->method('peekEntitlementStatus')->willReturn(null);

        $this->render($this->freeLicence(), $free, $remoteScans);

        self::assertSame('error', $this->assigned['rows'][1]['state']);
        self::assertSame('3 ERRORS', $this->assigned['rows'][1]['headline']);
    }

    #[Test]
    public function paidInstallationKeepsTheFrontendScanHintAndReadsLicensedResultsOnly(): void
    {
        $remoteScans = $this->createMock(RemoteScanRepository::class);
        $remoteScans->expects(self::once())
            ->method('findLastCompletedRelevantScan')
            ->with('main', 42, 0, false)
            ->willReturn(null);
        $remoteScans->expects(self::never())->method('findLastCompletedPageScanByPageOrUrl');

        $free = $this->createMock(FreeRemotePreviewService::class);
        $free->expects(self::never())->method('peekEntitlementStatus');

        $this->render($this->proLicence(), $free, $remoteScans);

        self::assertSame('PRO', $this->assigned['remoteHint']['tag']);
        self::assertSame('PAID-RUN-FRONTEND-SCAN', $this->assigned['remoteHint']['text']);
        self::assertSame('combined', $this->assigned['scanMode']);
    }

    private function render(
        ProStatusViewModel $licence,
        FreeRemotePreviewService $free,
        ?RemoteScanRepository $remoteScans = null,
    ): void {
        $proStatusResolver = $this->createMock(ProStatusResolverService::class);
        $proStatusResolver->method('resolveForSite')->willReturn($licence);

        $issues = $this->createMock(IssueRepository::class);
        $issues->method('countOpenBySeverity')->willReturn(['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0]);

        $scanStatus = $this->createMock(ScanStatusService::class);
        $scanStatus->method('getStatus')->willReturn([]);

        $backendContext = $this->createMock(BackendContextService::class);
        $backendContext->method('translate')->willReturnCallback(static fn (string $key): string => self::LABELS[$key] ?? '');

        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(
            static fn (string $route, array $parameters = []): Uri => new Uri('/typo3/aqg?' . http_build_query(['route' => $route] + $parameters))
        );

        $urls = $this->createMock(FrontendPageUrlService::class);
        $urls->method('resolveForPage')->willReturn('https://example.org/page');
        $urls->method('resolvePublicForPage')->willReturn('https://example.org/page');

        $service = new PageModuleIndicatorService(
            $issues,
            $this->createMock(SourceStateRepository::class),
            $this->createMock(ScanRepository::class),
            $remoteScans ?? $this->createMock(RemoteScanRepository::class),
            $proStatusResolver,
            $this->createMock(RemoteScanRecoveryService::class),
            $scanStatus,
            $backendContext,
            $uriBuilder,
            $this->createMock(ViewFactoryInterface::class),
            $urls,
            $free,
        );

        $variables = $service->buildViewData(42, new Site('main', 1, ['base' => 'https://example.org/']), 0);
        self::assertIsArray($variables);
        $this->assigned = $variables;
    }

    /** @return array<string, mixed> */
    private function freeStatus(string $state, int $jobsUsed, int $jobsLimit, int $remaining): array
    {
        return [
            'isFree' => true,
            'entitlement' => 'free_daily',
            'state' => $state,
            'available' => $state === 'FREE_AVAILABLE',
            'jobsUsed' => $jobsUsed,
            'jobsLimit' => $jobsLimit,
            'scansRemaining' => $remaining,
            'resetsAt' => '',
        ];
    }

    private function freeLicence(): ProStatusViewModel
    {
        return ProStatusViewModel::notConfigured(true, 'example.org');
    }

    private function proLicence(): ProStatusViewModel
    {
        return new ProStatusViewModel(
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
        );
    }
}
