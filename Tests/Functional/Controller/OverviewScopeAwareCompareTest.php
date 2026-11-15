<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\OverviewController;
use Priebera\A11yQualityGate\Service\RemoteScanHistoryService;
use Priebera\A11yQualityGate\Service\RemoteScanPairingService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Functional\Security\RemoteResultFixtureTrait;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * D-1 (AQG 3.0 production acceptance, 2026-11-13): the Overview's scan comparison showed the AQG service's counts for
 * two scans of different scope — a 4-page budgeted sitemap scan against a 19-page one read "297 new" and "Regression
 * signal". It now follows the service's compatibility verdict, and without one (crawler before 032) shows counts only
 * for stored scans that loaded the same pages.
 *
 * Fixture (RemoteResultFixtureTrait): stored crawl scan 1 (JOB_SITE, 5 loaded pages). Scan 4 is a later compatible
 * scan with a subset of 2 of those pages, scan 5 a later one with the same 5 pages. The AQG service's answer goes
 * through the real normalisation (the crawler client is final and cannot be doubled here; loadCompare()'s request is
 * covered by the unit test RemoteScanHistoryScopeAwareCompareTest).
 */
final class OverviewScopeAwareCompareTest extends AbstractFunctionalTestCase
{
    use RemoteResultFixtureTrait;

    private const JOB_SUBSET = '44444444-4444-4444-8444-444444444444';
    private const JOB_SAME = '55555555-5555-4555-8555-555555555555';

    /** @var array<string, mixed> */
    private array $answer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRemoteResultFixture();
        $pool = $this->get(ConnectionPool::class);
        $this->insertLaterScan($pool, 4, self::JOB_SUBSET, ['https://a.test/', 'https://a.test/public']);
        $this->insertLaterScan($pool, 5, self::JOB_SAME, ['https://a.test/', 'https://a.test/restricted-page', 'https://a.test/public', 'https://a.test/de/oeffentlich', 'https://a.test/?id=3']);
    }

    #[Test]
    public function theServicesNotComparableVerdictShowsNoCountsButBothReports(): void
    {
        $this->answer = $this->productionAnswer(['sameScope' => false, 'samePageBudget' => false, 'directlyComparable' => false, 'reason' => 'different_page_budget']);

        $comparison = $this->compare(self::JOB_SITE, self::JOB_SUBSET);

        self::assertTrue($comparison['available']);
        self::assertFalse($comparison['comparable']);
        self::assertSame('not_comparable', $comparison['status']);
        self::assertStringContainsString('The page limit changed', $comparison['statusMessage']);
        self::assertSame([], $comparison['summary']);
        self::assertSame([], $comparison['acceptanceEvidence']);
        self::assertStringContainsString('remoteScanUid=1', urldecode((string)$comparison['previousScan']['reportUrl']));
        self::assertStringContainsString('remoteScanUid=4', urldecode((string)$comparison['currentScan']['reportUrl']));
        self::assertStringNotContainsString('297', (string)json_encode($comparison));
    }

    #[Test]
    public function anOlderServiceWithoutAVerdictIsGuardedByTheStoredPages(): void
    {
        // Crawler before 032: no sameScope, directlyComparable true, counts over both scans.
        $this->answer = $this->productionAnswer(['sameSite' => true, 'sameSourceType' => true, 'sameStartUrl' => true, 'sameLanguage' => true, 'directlyComparable' => true, 'reason' => null]);

        $comparison = $this->compare(self::JOB_SITE, self::JOB_SUBSET);

        self::assertFalse($comparison['comparable']);
        self::assertSame('different_pages', $comparison['notComparableReason']);
        self::assertStringContainsString('(3 only in the earlier scan, 0 only in the later scan)', $comparison['statusMessage']);
        self::assertStringNotContainsString('297', (string)json_encode($comparison));
    }

    #[Test]
    public function anOlderServiceWithoutAVerdictKeepsCountsForScansOfTheSamePages(): void
    {
        $this->answer = $this->productionAnswer(['directlyComparable' => true]);
        $this->answer['summary'] = ['newIssues' => 12, 'resolvedIssues' => 3, 'unchangedIssues' => 2, 'scoreDelta' => -4];

        $comparison = $this->compare(self::JOB_SITE, self::JOB_SAME);

        self::assertTrue($comparison['comparable']);
        self::assertSame('regression', $comparison['status']);
        self::assertSame(12, $comparison['summary']['newIssues']);
        self::assertSame(3, $comparison['summary']['resolvedIssues']);
        // The comparison itself is shown; the per-scan report links belong to the not-comparable state only.
        self::assertArrayNotHasKey('reportUrl', $comparison['previousScan']);
    }

    #[Test]
    public function theServicesComparableVerdictIsTrustedEvenWhenTheSiteGrew(): void
    {
        // Same scope, budget not reached, a page added to the site: the service's numbers are the real change.
        $this->answer = $this->productionAnswer(['sameScope' => true, 'samePageBudget' => true, 'directlyComparable' => true, 'reason' => null]);
        $this->answer['summary'] = ['newIssues' => 5, 'resolvedIssues' => 0, 'unchangedIssues' => 9, 'scoreDelta' => -1];

        $comparison = $this->compare(self::JOB_SITE, self::JOB_SUBSET);

        self::assertTrue($comparison['comparable']);
        self::assertSame(5, $comparison['summary']['newIssues']);
    }

    /**
     * @param array<string, mixed> $compatibility
     * @return array<string, mixed>
     */
    private function productionAnswer(array $compatibility): array
    {
        return [
            'from' => ['jobId' => self::JOB_SITE, 'pagesScanned' => 4, 'issuesTotal' => 79],
            'to' => ['jobId' => self::JOB_SUBSET, 'pagesScanned' => 19, 'issuesTotal' => 376],
            'compatibility' => $compatibility,
            'summary' => ['newIssues' => 297, 'resolvedIssues' => 0, 'unchangedIssues' => 79, 'improvedPages' => 0, 'worsenedPages' => 15, 'scoreDelta' => -9],
            'byPage' => [['url' => 'https://a.test/only-in-later', 'fromIssuesTotal' => 0, 'toIssuesTotal' => 35, 'delta' => 35, 'status' => 'new']],
        ];
    }

    /**
     * @param list<string> $urls
     */
    private function insertLaterScan(ConnectionPool $pool, int $uid, string $jobId, array $urls): void
    {
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => $uid, 'site_identifier' => 'client-a', 'job_id' => $jobId, 'source_type' => 'crawl', 'scan_scope' => 'site',
            'page_uid' => 0, 'language_uid' => 0, 'start_url' => 'https://a.test/', 'status' => 'completed',
            'finished_at' => 3000 + $uid, 'persisted_at' => 4000 + $uid, 'crdate' => 3000 + $uid, 'tstamp' => time(),
            'pages_scanned' => count($urls), 'pages_total' => count($urls), 'issues_total' => 0,
        ]);
        foreach ($urls as $i => $url) {
            $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', [
                'uid' => $uid * 100 + $i, 'remote_scan' => $uid, 'url' => $url, 'http_status' => 200, 'crdate' => time(), 'tstamp' => time(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(string $fromJobId, string $toJobId): array
    {
        return $this->as(self::ADMIN, function () use ($fromJobId, $toJobId): array {
            $request = (new ServerRequest('https://a.test/typo3/module/web/a11y'))
                ->withQueryParams(['compareFromJobId' => $fromJobId, 'compareToJobId' => $toJobId, 'id' => 1, 'language' => 0])
                ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
            $pair = $this->get(RemoteScanPairingService::class)->resolveComparePair('client-a', $fromJobId, $toJobId);
            self::assertNotNull($pair, 'the fixture scans are a compatible pair');
            $comparison = (new \ReflectionMethod(RemoteScanHistoryService::class, 'normalizeComparePayload'))
                ->invoke($this->get(RemoteScanHistoryService::class), $this->answer, $fromJobId, $toJobId);

            return (new \ReflectionMethod(OverviewController::class, 'presentRemoteScanCompare'))
                ->invoke($this->get(OverviewController::class), $request, 'client-a', $pair, $comparison);
        });
    }

    private function as(int $beUserUid, \Closure $callback): mixed
    {
        $previous = $GLOBALS['BE_USER'] ?? null;
        $this->setUpBackendUser($beUserUid);
        try {
            return $callback();
        } finally {
            $GLOBALS['BE_USER'] = $previous;
        }
    }
}
