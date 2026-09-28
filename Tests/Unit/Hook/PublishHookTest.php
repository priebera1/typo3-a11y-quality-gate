<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Hook;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Hook\PublishHook;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\QualityGate\QualityGateChecker;
use Priebera\A11yQualityGate\QualityGate\QualityGateVerdict;
use Priebera\A11yQualityGate\Scan\ContentCollector;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Scan\ScanResult;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * A publish decision must say which scan it rests on, and a publish without a decision must not pass
 * silently while the Quality Gate is switched on. The decision logic itself is QualityGateChecker's.
 */
final class PublishHookTest extends TestCase
{
    /** @var list<array{message: string, severity: ContextualFeedbackSeverity}> */
    private array $flashMessages = [];

    #[Test]
    public function warningNamesTheContentScanTheDecisionIsBasedOn(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('check')->willReturn(QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 2, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['2 critical issue(s) exceed threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 2, 'threshold' => 0]],
        ));

        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));

        $this->unhidePage($checker, $scans);

        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::WARNING, $this->flashMessages[0]['severity']);
        self::assertStringContainsString('2 critical issue(s) exceed threshold 0', $this->flashMessages[0]['message']);
        self::assertMatchesRegularExpression(
            '/Decision based on the content scan from \d{2}\.\d{2}\.\d{4} \d{2}:\d{2}\.$/',
            $this->flashMessages[0]['message']
        );
    }

    #[Test]
    public function failedScanIsReportedInsteadOfPublishingWithoutADecision(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->with('main')->willReturn(true);
        $checker->expects(self::never())->method('check');

        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->method('scanPage')->willThrowException(new \RuntimeException('Rendered page fetch failed at https://internal.example/'));

        $this->unhidePage($checker, $scans);

        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::WARNING, $this->flashMessages[0]['severity']);
        self::assertStringContainsString('could not check this page', $this->flashMessages[0]['message']);
        self::assertStringNotContainsString('internal.example', $this->flashMessages[0]['message']);
    }

    #[Test]
    public function failedScanStaysQuietWhileTheQualityGateIsDisabled(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('isEnabledForSite')->willReturn(false);
        $checker->expects(self::never())->method('check');

        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->method('scanPage')->willThrowException(new \RuntimeException('scan failed'));

        $this->unhidePage($checker, $scans);

        self::assertSame([], $this->flashMessages);
    }

    #[Test]
    public function editorWithoutAqgModuleAccessIsStillWarnedByTheGate(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('check')->willReturn(QualityGateVerdict::fail(
            mode: 1,
            counts: ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['1 critical issue(s) exceed threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 1, 'threshold' => 0]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));

        $this->unhidePage($checker, $scans, false);

        self::assertCount(1, $this->flashMessages);
        self::assertStringContainsString('1 critical issue(s) exceed threshold 0', $this->flashMessages[0]['message']);
    }

    #[Test]
    public function blockingGateRehidesThePageForAnEditorWithoutAqgModuleAccess(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $checker->method('check')->willReturn(QualityGateVerdict::fail(
            mode: 2,
            counts: ['critical' => 3, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            reasons: ['3 critical issue(s) exceed threshold 0'],
            reasonDetails: [['severity' => 'critical', 'count' => 3, 'threshold' => 0]],
        ));
        $scans = $this->createMock(ScanOrchestrator::class);
        $scans->method('scanPage')->willReturn(new ScanResult(11));

        $connection = $this->createMock(\TYPO3\CMS\Core\Database\Connection::class);
        $connection->expects(self::once())->method('update')->with('pages', ['hidden' => 1], ['uid' => 42]);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $this->unhidePage($checker, $scans, false, $pool, true);

        self::assertCount(1, $this->flashMessages);
        self::assertSame(ContextualFeedbackSeverity::ERROR, $this->flashMessages[0]['severity']);
    }

    #[Test]
    public function contentFeedbackStaysWithAqgModuleUsers(): void
    {
        $checker = $this->createMock(QualityGateChecker::class);
        $scans = $this->createMock(ScanOrchestrator::class);
        // Only the page unhide in the datamap is scanned; the content element change is not.
        $scans->expects(self::once())->method('scanPage')->willReturn(new ScanResult(11));
        $checker->method('check')->willReturn(QualityGateVerdict::pass());

        $this->unhidePage($checker, $scans, false, null, false, ['tt_content' => [7 => ['pid' => 42, 'bodytext' => '<p>x</p>']]]);

        self::assertSame([], $this->flashMessages);
    }

    /**
     * @param array<string, mixed> $extraDatamap
     */
    private function unhidePage(
        QualityGateChecker $checker,
        ScanOrchestrator $scans,
        bool $moduleAccess = true,
        ?ConnectionPool $pool = null,
        bool $licenceValid = false,
        array $extraDatamap = [],
    ): void {
        $users = $this->createMock(BackendUserService::class);
        $users->method('canAccessAccessibilityModule')->willReturn($moduleAccess);
        $users->method('getBackendUserSnapshot')->willReturn(['uid' => 1]);

        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteByPageId')->willReturn(new Site('main', 1, ['base' => 'https://example.org/']));

        $context = $this->createMock(BackendContextService::class);
        // No backend language in unit tests: the hook falls back to its English texts.
        $context->method('translate')->willReturn('');
        $context->method('addFlashMessage')->willReturnCallback(
            function (string $message, ContextualFeedbackSeverity $severity): void {
                $this->flashMessages[] = ['message' => $message, 'severity' => $severity];
            }
        );

        $capabilities = $this->createMock(ProCapabilityService::class);
        $capabilities->method('getStatus')->willReturn(new ProStatusViewModel(
            configured: true,
            valid: $licenceValid,
            proAvailable: $licenceValid,
            plan: $licenceValid ? 'pro' : '',
            features: [],
            reason: null,
            reasonLabel: null,
            statusLabel: '',
            showProHints: false,
            hasCrawler: $licenceValid,
            hasExportPdf: $licenceValid,
            hasMultiSite: false,
            hasProRules: $licenceValid,
        ));

        $hook = new PublishHook(
            $checker,
            $scans,
            $this->createMock(IssueRepository::class),
            $sites,
            $this->createMock(ContentCollector::class),
            $users,
            $pool ?? $this->createMock(ConnectionPool::class),
            $context,
            $capabilities,
            $this->createMock(ExtensionContextService::class),
        );

        $dataHandler = $this->createMock(DataHandler::class);
        $dataHandler->datamap = ['pages' => [42 => ['hidden' => 0]]] + $extraDatamap;

        $hook->processDatamap_afterAllOperations($dataHandler);
    }
}
