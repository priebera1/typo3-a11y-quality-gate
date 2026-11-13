<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * F11-02 (independent F11 review): while a completed crawl's results were not stored (pending) or could not be stored,
 * the Overview suppressed only its headline totals. The status metrics still read "2 priority fixes" and "9 WCAG-mapped
 * findings", and the crawler's fix list took precedence over the storage message, so an administrator saw actionable
 * report evidence while no saved report existed.
 *
 * The real Overview partials are rendered with the real Fluid engine, a non-empty crawler summary and an unsaved scan,
 * plus the control after storage (recovery): then the stored scan's fixes and counts appear. OverviewController gates
 * the same values before they reach the view (OverviewUnsavedRemoteResultTest); these partials also refuse them.
 */
final class UnsavedRemoteResultRenderingTest extends AbstractFunctionalTestCase
{
    private const FIX = [
        'ruleId' => 'color-contrast', 'displayTitle' => 'CRAWLER_ONLY_FIX_TITLE', 'impact' => 'serious', 'issuesTotal' => 9,
        'affectedPagesTotal' => 3, 'shortFix' => 'CRAWLER_ONLY_SHORT_FIX', 'affectedPagesUrl' => '#',
    ];

    /** @return array<string, array{string}> */
    public static function unsavedTones(): array
    {
        return ['results being saved' => ['pending'], 'results could not be saved' => ['not-stored']];
    }

    #[Test]
    #[DataProvider('unsavedTones')]
    public function theStatusShowsNoCrawlerFindingCountsWhileResultsAreUnsaved(string $tone): void
    {
        $xpath = $this->render('Overview/RemoteStatusHead', $this->arguments($tone));
        $text = $this->bodyText($xpath);

        self::assertStringNotContainsString('priority fixes', $text);
        self::assertStringNotContainsString('WCAG-mapped', $text);
        self::assertStringNotContainsString('failed pages', $text);
        self::assertStringNotContainsString('occurrences', $text);
        self::assertStringNotContainsString('CRAWLER_ONLY_NOT_STORED_NOTE', $text);
        self::assertSame(0, $xpath->query('//*[contains(@class, "aqg-status__counts")]')->length);
        // Crawl progress stays.
        self::assertStringContainsString('7 pages loaded', $text);
        self::assertStringContainsString('UNSAVED_TONE_LABEL', $text);
    }

    #[Test]
    #[DataProvider('unsavedTones')]
    public function thePanelShowsTheStorageStateInsteadOfTheCrawlersFixList(string $tone): void
    {
        $xpath = $this->render('Overview/RemotePanel', $this->arguments($tone));
        $text = $this->bodyText($xpath);

        self::assertSame(0, $xpath->query('//ol[contains(@class, "aqg-fixlist")]')->length, 'no actionable fix list');
        self::assertStringNotContainsString('CRAWLER_ONLY_FIX_TITLE', $text);
        self::assertStringNotContainsString('CRAWLER_ONLY_SHORT_FIX', $text);
        self::assertNotNull($xpath->query('//*[@data-aqg-priority-empty="' . $tone . '"]')->item(0), 'the storage message takes the fix list\'s place');
        self::assertStringContainsString('STORAGE_STATE_TITLE', $text);
        self::assertStringNotContainsString('9 WCAG-mapped findings', $text);
        self::assertStringNotContainsString('CRAWLER_ONLY_GROUP', $text);
        self::assertStringNotContainsString('CRAWLER_ONLY_LANDMARK_SIGNAL', $text);
        self::assertStringNotContainsString('No WCAG breakdown available', $text, 'no "run a scan" advice for a finished crawl');
    }

    #[Test]
    public function afterStorageTheStoredScansFixesAndCountsAppear(): void
    {
        $xpath = $this->render('Overview/RemotePanel', $this->arguments('attention'));
        $text = $this->bodyText($xpath);

        self::assertStringContainsString('2 priority fixes', $text);
        self::assertStringContainsString('9 WCAG-mapped findings', $text);
        self::assertStringContainsString('7 occurrences', $text);
        self::assertSame(1, $xpath->query('//ol[contains(@class, "aqg-fixlist")]')->length);
        self::assertStringContainsString('CRAWLER_ONLY_FIX_TITLE', $text);
        self::assertStringContainsString('CRAWLER_ONLY_GROUP', $text);
        self::assertNull($xpath->query('//*[@data-aqg-priority-empty]')->item(0));
    }

    #[Test]
    public function aRestrictedViewStillShowsNoPriorityCount(): void
    {
        $arguments = $this->arguments('restricted');
        $arguments['remotePriorityFixesVisible'] = [];
        $arguments['remotePriorityFixes'] = [];
        $xpath = $this->render('Overview/RemoteStatusHead', $arguments);

        self::assertStringNotContainsString('priority fixes', $this->bodyText($xpath));
        self::assertStringContainsString('7 pages available to you', $this->bodyText($xpath));
    }

    /**
     * A non-empty crawler summary next to a scan whose results are in the given state.
     *
     * @return array<string, mixed>
     */
    private function arguments(string $tone): array
    {
        $stored = !in_array($tone, ['pending', 'not-stored'], true);

        return [
            'remoteScan' => [
                'uid' => 9, 'job_id' => 'job-9', 'status' => 'completed', 'scan_scope' => 'site', 'pages_scanned' => 7,
                'pages_total' => 7, 'issues_total' => 9, 'issues_new' => 5, 'finished_at' => 1790000000,
                'finished_at_formatted' => '03.10.2026 12:53', 'persisted_at' => $stored ? 1790000100 : 0,
            ],
            'remoteSignalTone' => $tone,
            'remoteSignalLabel' => $stored ? 'Needs attention' : 'UNSAVED_TONE_LABEL',
            'remoteOccurrences' => ['total' => 7, 'notStored' => 2, 'notStoredNote' => 'CRAWLER_ONLY_NOT_STORED_NOTE'],
            'remoteReportSummary' => ['priorityFixesTotal' => 2, 'affectedPagesTotal' => 3, 'wcagCriteriaTotal' => 4],
            'remotePriorityFixes' => [self::FIX, ['displayTitle' => 'CRAWLER_ONLY_FIX_TWO'] + self::FIX],
            'remotePriorityFixesVisible' => [self::FIX, ['displayTitle' => 'CRAWLER_ONLY_FIX_TWO'] + self::FIX],
            'remotePriorityFixesMoreCount' => 0,
            'remoteReportingGroups' => [
                'automatedIssuesTotal' => 9,
                'automatedIssuesLabel' => '9 WCAG-mapped findings',
                'groups' => [['groupId' => 'perceivable', 'title' => 'CRAWLER_ONLY_GROUP', 'automatedIssuesLabel' => '9', 'wcagCriteriaText' => '1.4.3']],
            ],
            'remoteHasAdditionalAutomatedSignals' => true,
            'remoteStructureSummary' => ['landmarkIssuesLabel' => 'CRAWLER_ONLY_LANDMARK_SIGNAL'],
            'remoteScanHasIssues' => true,
            'remoteScore' => ['hasValue' => false],
            'remotePriorityEmpty' => ['state' => $tone, 'title' => 'STORAGE_STATE_TITLE', 'body' => 'STORAGE_STATE_BODY'],
            'totalRemoteFailedPages' => 0,
            'totalRemotePages' => 0,
            'remotePages' => [],
            'freePreview' => ['isFree' => false],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(string $partial, array $arguments): \DOMXPath
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'lang' => ''];
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

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

    private function bodyText(\DOMXPath $xpath): string
    {
        return trim((string)preg_replace('/\s+/u', ' ', (string)$xpath->query('//body')->item(0)?->textContent));
    }
}
