<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\OverviewController;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteScanSignal;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * F11-02: OverviewController builds no finding-derived view value from the crawler while a completed scan's results are
 * not stored. The crawler's summary for the job is primed in the Overview's own summary cache (what a successful
 * /crawl/summary fetch leaves there), so the controller would show it if it did not gate it — and, after storage, does.
 */
final class OverviewUnsavedRemoteResultTest extends AbstractFunctionalTestCase
{
    private const SITE_BASE = 'https://a.test/';
    private const JOB = 'job-unsaved-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $this->setUpBackendUser(1);
        $this->get(CacheManager::class)->getCache(ProConstants::CACHE_IDENTIFIER)->set(
            'overview_api_summary_' . sha1(rtrim(self::SITE_BASE, '/') . '|' . self::JOB . '|en'),
            ['summary' => [
                'priorityFixes' => [['ruleId' => 'color-contrast', 'displayTitle' => 'CRAWLER_FIX']],
                'priorityFixesVisible' => [['ruleId' => 'color-contrast', 'displayTitle' => 'CRAWLER_FIX']],
                'priorityFixesMoreCount' => 0,
                'wcagSummary' => [['criterion' => '1.4.3', 'issuesTotal' => 9]],
                'reportSummary' => ['priorityFixesTotal' => 2],
                'reportingGroups' => ['automatedIssuesTotal' => 9],
                'score' => ['hasValue' => true, 'value' => 61, 'max' => 100],
                'keyboardSummary' => ['tested' => true],
                'structureSummary' => ['landmarkIssuesLabel' => 'CRAWLER_SIGNAL'],
                'manualReviewChecklist' => [],
            ]]
        );
    }

    #[Test]
    public function noCrawlerSummaryIsUsedWhileResultsAreBeingSaved(): void
    {
        $this->assertNoFindingData($this->summaryFor($this->scan(0, '')));
    }

    #[Test]
    public function noCrawlerSummaryIsUsedWhenStoringFailed(): void
    {
        $this->assertNoFindingData($this->summaryFor($this->scan(0, 'Results could not be stored.')));
    }

    #[Test]
    public function afterStorageOrRecoveryTheSummaryIsShownAgain(): void
    {
        $summary = $this->summaryFor($this->scan(1790000100, ''));

        self::assertSame('CRAWLER_FIX', $summary['priorityFixesVisible'][0]['displayTitle'] ?? null);
        self::assertSame(9, $summary['reportingGroups']['automatedIssuesTotal'] ?? null);
        self::assertSame(2, $summary['reportSummary']['priorityFixesTotal'] ?? null);
    }

    #[Test]
    public function aRestrictedViewStillGetsNoSummary(): void
    {
        $this->assertNoFindingData($this->call('remoteReportingSummaryForView', $this->scan(1790000100, ''), true, self::SITE_BASE));
    }

    #[Test]
    public function theViewRowLosesOnlyTheCrawlersFindingCountersWhileUnsaved(): void
    {
        $unsaved = $this->call('remoteScanForView', $this->scan(0, ''), null);
        foreach (['issues_total', 'issues_new', 'issues_resolved'] as $counter) {
            self::assertArrayNotHasKey($counter, $unsaved, $counter);
        }
        self::assertSame(7, $unsaved['pages_scanned'], 'crawl progress stays');
        self::assertSame('completed', $unsaved['status']);

        $stored = $this->call('remoteScanForView', $this->scan(1790000100, ''), null);
        self::assertSame(5, $stored['issues_new']);
        self::assertSame(9, $stored['issues_total']);

        $restricted = ['uid' => 9, 'status' => 'completed', 'issues_total' => 2, 'pages_scanned' => 1];
        self::assertSame($restricted, $this->call('remoteScanForView', $this->scan(1790000100, ''), $restricted), 'restricted counters stay as they are');
        self::assertArrayNotHasKey('issues_total', $this->call('remoteScanForView', $this->scan(0, ''), $restricted));
    }

    #[Test]
    public function theCrawlersExtraOccurrenceCountIsNotShownWhileUnsaved(): void
    {
        self::assertSame(['total' => 0, 'notStored' => 0, 'notStoredNote' => ''], $this->call('buildRemoteOccurrenceView', $this->scan(0, 'x')));
        self::assertSame(['total' => 0, 'notStored' => 0, 'notStoredNote' => ''], $this->call('buildRemoteOccurrenceView', $this->scan(0, '')));
    }

    #[Test]
    public function theSignalAndTheGateAgreeOnTheStorageState(): void
    {
        self::assertSame(RemoteScanSignal::TONE_PENDING, RemoteScanSignal::unsavedResultState($this->scan(0, '')));
        self::assertSame(RemoteScanSignal::TONE_NOT_STORED, RemoteScanSignal::unsavedResultState($this->scan(0, 'failed')));
        self::assertNull(RemoteScanSignal::unsavedResultState($this->scan(1, '')));
        self::assertNull(RemoteScanSignal::unsavedResultState(['status' => 'running', 'persisted_at' => 0]), 'a running crawl is progress, not an unsaved result');
        self::assertNull(RemoteScanSignal::unsavedResultState(['status' => 'completed']), 'legacy rows without the column');
        self::assertSame(RemoteScanSignal::TONE_PENDING, RemoteScanSignal::resolve($this->scan(0, ''), [], true, [])->tone);
        self::assertSame(RemoteScanSignal::TONE_NOT_STORED, RemoteScanSignal::resolve(['uid' => 9, 'status' => 'completed'], [], true, [], 0, true, $this->scan(0, 'failed'))->tone);
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function assertNoFindingData(array $summary): void
    {
        self::assertSame([], $summary['priorityFixes']);
        self::assertSame([], $summary['priorityFixesVisible']);
        self::assertSame(0, $summary['priorityFixesMoreCount']);
        self::assertSame([], $summary['wcagSummary']);
        self::assertSame([], $summary['reportSummary'] ?? []);
        self::assertSame([], $summary['reportingGroups'] ?? []);
        self::assertSame([], $summary['score'] ?? []);
        self::assertSame([], $summary['keyboardSummary'] ?? []);
        self::assertSame([], $summary['structureSummary'] ?? []);
        self::assertStringNotContainsString('CRAWLER', (string)json_encode($summary));
    }

    /**
     * @param array<string, mixed> $scan
     * @return array<string, mixed>
     */
    private function summaryFor(array $scan): array
    {
        return $this->call('remoteReportingSummaryForView', $scan, false, self::SITE_BASE);
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(int $persistedAt, string $syncError): array
    {
        return [
            'uid' => 9, 'site_identifier' => 'main', 'job_id' => self::JOB, 'status' => 'completed', 'scan_scope' => 'site',
            'pages_scanned' => 7, 'pages_total' => 7, 'issues_total' => 9, 'issues_new' => 5, 'issues_resolved' => 1,
            'persisted_at' => $persistedAt, 'sync_error' => $syncError,
        ];
    }

    private function call(string $method, mixed ...$arguments): mixed
    {
        return (new \ReflectionMethod(OverviewController::class, $method))->invoke($this->get(OverviewController::class), ...$arguments);
    }
}
