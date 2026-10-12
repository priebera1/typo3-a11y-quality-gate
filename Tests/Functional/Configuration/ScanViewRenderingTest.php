<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The scan views' status panels say one true sentence first: what the stored results are, where they come from and
 * how complete they are, without stat tiles that repeat it and without "no scan" claims above open issues.
 */
final class ScanViewRenderingTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function openIssuesFromPageScansAreNeverHiddenBehindANoScanState(): void
    {
        $xpath = $this->render('Overview/LocalActions', $this->localArguments([
            'lastScan' => null,
            'totalCounts' => ['critical' => 11, 'warning' => 14, 'info' => 3, 'needs_review' => 1, 'total' => 29],
            'totalLocalPages' => 3,
        ]));

        self::assertSame('29 open issues on 3 pages', $this->text($xpath, '//h2[@id="aqg-local-status-title"]'));
        self::assertSame(
            ['11 Critical', '14 Warnings', '3 Info', '1 Needs review'],
            $this->texts($xpath, '//ul[contains(@class, "aqg-status__counts")]/li')
        );
        self::assertSame(['From earlier page scans', 'No site scan yet'], $this->texts($xpath, '//*[@data-aqg-local-results-without-scan]/li'));
        self::assertStringNotContainsString('No scan on record', (string)$xpath->document->saveHTML());
        // Severity is never colour alone: every count carries its word and its own icon shape.
        self::assertSame(4, $xpath->query('//ul[contains(@class, "aqg-status__counts")]/li/*[name()="svg"][@aria-hidden="true"]')->length);
    }

    #[Test]
    public function aCompletedScanNamesItsTimeAndCoverageAndShowsNewIssuesPerSeverity(): void
    {
        $xpath = $this->render('Overview/LocalActions', $this->localArguments([
            'lastScan' => ['uid' => 4, 'finished_at_formatted' => '03.10.2026 12:53', 'pages_scanned' => 12, 'issues_resolved' => 2],
            'totalCounts' => ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 1],
            'localNewCounts' => ['critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            'totalLocalPages' => 1,
            'lastScanScopeLabel' => 'Site scan',
        ]));

        self::assertSame('1 open issue on 1 page', $this->text($xpath, '//h2[@id="aqg-local-status-title"]'));
        self::assertSame('1 Critical +1 new', $this->texts($xpath, '//ul[contains(@class, "aqg-status__counts")]/li')[0]);
        self::assertSame(
            ['Site scan', 'Last scan 03.10.2026 12:53', '12 pages scanned', '2 resolved by this scan'],
            $this->texts($xpath, '//ul[contains(@class, "aqg-status__meta")]/li')
        );
    }

    #[Test]
    public function withoutAnyResultTheContentTabIsOneEmptyPanelWithItsNextSteps(): void
    {
        $xpath = $this->render('Overview/LocalActions', $this->localArguments([
            'lastScan' => null,
            'totalCounts' => ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 0],
            'gettingStarted' => ['isFree' => true, 'siteAddressLocal' => false, 'siteHost' => ''],
        ]));

        self::assertSame('No content scan yet', $this->text($xpath, '//h2[@id="aqg-local-status-title"]'));
        self::assertSame(1, $xpath->query('//*[@data-aqg-getting-started="free"]')->length);
        self::assertSame(0, $xpath->query('//ul[contains(@class, "aqg-status__counts")]')->length);
        self::assertSame(['Scan this page', 'Scan site'], $this->texts($xpath, '//div[contains(@class, "aqg-status__actions")]/button'));
        self::assertSame(1, $xpath->query('//div[contains(@class, "aqg-status__actions")]//*[contains(@class, "btn-primary")]')->length, 'Scanning is the one primary action.');
    }

    #[Test]
    public function theFrontendStatusStatesCoverageFailedPagesAccessAndTheCaveatOnce(): void
    {
        $xpath = $this->render('Overview/RemoteStatus', [
            'remoteScan' => ['uid' => 77, 'issues_total' => 377, 'pages_scanned' => 19, 'scan_scope' => 'site', 'finished_at_formatted' => '03.10.2026 12:50'],
            'remoteSignalTone' => 'critical',
            'remoteSignalLabel' => 'Critical',
            'remoteScore' => ['hasValue' => true, 'value' => 5, 'max' => 100],
            'remoteScoreTone' => 'critical',
            'remoteReportSummary' => ['affectedPagesTotal' => 19, 'priorityFixesTotal' => 10],
            'remoteReportingGroups' => ['automatedIssuesTotal' => 78],
            'totalRemoteFailedPages' => 2,
            'currentLanguageOption' => ['title' => 'English'],
            'remoteManualReviewChecklist' => [['title' => 'Keyboard'], ['title' => 'Zoom']],
            'showRemoteScannerTokenNotice' => true,
            'remoteScanAccessSettingsUrl' => '/settings',
            'proStatus' => ['isTrial' => false],
            'canScanAll' => true,
            'freePreview' => ['isFree' => false],
        ]);

        self::assertSame('Critical · 377 occurrences · 19 affected pages', $this->text($xpath, '//h2[@id="aqg-remote-status-title"]'));
        self::assertSame('AQG signal 5 / 100', $this->text($xpath, '//*[contains(@class, "aqg-status__score")]'));
        self::assertSame(['Site scan', '19 pages loaded', 'English', '03.10.2026 12:50'], $this->texts($xpath, '//ul[@class="aqg-status__meta"]/li'));
        $failed = $xpath->query('//a[@href="#a11y-remote-failed-pages"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $failed);
        self::assertSame('2 failed pages', trim((string)preg_replace('/\s+/', ' ', $failed->textContent)));
        self::assertStringContainsString('no Scanner Token is configured', $this->text($xpath, '//li[contains(@class, "aqg-status__access")]'));
        self::assertSame(1, $xpath->query('//*[contains(@class, "aqg-status__caveat")]')->length);
        self::assertSame('Manual review checklist (2 tasks)', $this->text($xpath, '//a[@href="#aqg-evidence-manual-review"]'));
        self::assertSame(1, $xpath->query('//*[contains(@class, "btn-primary")]')->length);
    }

    #[Test]
    public function theOverviewTabsAreAnAriaTablistBoundToTheirPanels(): void
    {
        $xpath = $this->render('Overview/SourceTabs', ['remoteScan' => ['issues_new' => 3]]);

        $tabs = $xpath->query('//*[@role="tablist"]/button[@role="tab"]');
        self::assertSame(2, $tabs->length);
        self::assertSame(['true', 'false'], array_map(static fn (\DOMElement $tab): string => $tab->getAttribute('aria-selected'), iterator_to_array($tabs)));
        self::assertSame(['0', '-1'], array_map(static fn (\DOMElement $tab): string => $tab->getAttribute('tabindex'), iterator_to_array($tabs)));
        self::assertSame(['aqg-overview-panel-local', 'aqg-overview-panel-remote'], array_map(static fn (\DOMElement $tab): string => $tab->getAttribute('aria-controls'), iterator_to_array($tabs)));
        self::assertSame('Frontend scan 3 new', $this->text($xpath, '//button[@id="aqg-overview-tab-remote"]'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function localArguments(array $overrides): array
    {
        return $overrides + [
            'localNewCounts' => ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0],
            'totalLocalPages' => 0,
            'localQuery' => '',
            'canScanAll' => true,
            'canScanNow' => true,
            'siteRootPid' => 1,
            'currentPageUid' => 1,
            'currentLanguageUid' => 0,
            'scanStatus' => ['running' => false],
            'isRelevantLocalScanRunning' => false,
            'exportLocalCsvUrl' => '/export.csv',
            'exportLocalPdfUrl' => '/export.pdf',
            'showPdfExport' => false,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(string $partial, array $arguments): \DOMXPath
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [
                GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/'),
            ],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
        ));
        $view->assignMultiple(['partial' => $partial, 'arguments' => $arguments]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $view->render() . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function text(\DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)->item(0);
        self::assertNotNull($node, 'Not rendered: ' . $query);

        return trim((string)preg_replace('/\s+/', ' ', $node->textContent));
    }

    /**
     * @return list<string>
     */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) as $node) {
            $texts[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }
}
