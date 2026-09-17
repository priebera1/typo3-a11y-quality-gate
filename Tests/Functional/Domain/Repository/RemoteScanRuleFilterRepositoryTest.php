<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * "View affected pages" for a rule lists the pages of the scan on which that rule has a finding that is
 * not ignored — no other scan's pages, and the page search still applies on top. Every listed page keeps
 * the HTTP status the crawler reported, which the Overview shows in its own column.
 */
final class RemoteScanRuleFilterRepositoryTest extends AbstractFunctionalTestCase
{
    private RemoteScanRepository $scans;

    private RemoteIssueRepository $issues;

    protected function setUp(): void
    {
        parent::setUp();
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->scans = new RemoteScanRepository($connectionPool);
        $this->issues = new RemoteIssueRepository($connectionPool);
    }

    #[Test]
    public function pageListIsLimitedToThePagesWithTheRule(): void
    {
        $scanUid = $this->createScan('site-job', [
            'https://example.test/a-page' => ['issues' => 3, 'rules' => ['color-contrast', 'link-name']],
            'https://example.test/b-page' => ['issues' => 1, 'rules' => ['color-contrast']],
            'https://example.test/c-page' => ['issues' => 2, 'rules' => ['image-alt', 'color-contrast' => 'ignored']],
        ]);
        // Another scan of the same site must never leak into the list.
        $this->createScan('other-job', [
            'https://example.test/d-page' => ['issues' => 9, 'rules' => ['color-contrast']],
        ]);

        self::assertSame(3, $this->scans->countPagesForScan($scanUid, false));
        self::assertSame(2, $this->scans->countPagesForScan($scanUid, false, '', 'color-contrast'));
        self::assertSame(
            ['https://example.test/a-page', 'https://example.test/b-page'],
            array_column($this->scans->findPagesForScanPaginated($scanUid, 10, 0, '', 'color-contrast'), 'url'),
            'Pages keep their issue-type order.'
        );
        self::assertSame(
            ['https://example.test/b-page'],
            array_column($this->scans->findPagesForScan($scanUid, 'b-page', 'color-contrast'), 'url')
        );
        self::assertSame(0, $this->scans->countPagesForScan($scanUid, false, '', 'unknown-rule'));
        self::assertSame(
            ['https://example.test/a-page'],
            array_column($this->scans->findPagesForScanPaginated($scanUid, 1, 0, '', 'color-contrast'), 'url')
        );
    }

    #[Test]
    public function listedPagesKeepTheHttpStatusTheCrawlerReported(): void
    {
        $scanUid = $this->createScan('status-job', [
            'https://example.test/ok' => ['issues' => 3, 'rules' => ['color-contrast'], 'httpStatus' => 200],
            'https://example.test/moved' => ['issues' => 2, 'rules' => ['color-contrast'], 'httpStatus' => 301],
            'https://example.test/unreported' => ['issues' => 1, 'rules' => ['link-name'], 'httpStatus' => null],
        ]);
        $statuses = static fn (array $rows): array => array_combine(
            array_column($rows, 'url'),
            array_map(static fn (array $row): ?int => isset($row['http_status']) ? (int)$row['http_status'] : null, $rows)
        );

        self::assertSame(
            ['https://example.test/ok' => 200, 'https://example.test/moved' => 301, 'https://example.test/unreported' => 0],
            $statuses($this->scans->findPagesForScanPaginated($scanUid, 10, 0)),
            'A page without a reported status is stored as 0, which the Overview renders as a dash.'
        );
        self::assertSame(
            ['https://example.test/ok' => 200, 'https://example.test/moved' => 301],
            $statuses($this->scans->findPagesForScanPaginated($scanUid, 10, 0, '', 'color-contrast'))
        );
    }

    /**
     * @param array<string, array{issues: int, rules: array<int|string, string>, httpStatus?: int|null}> $pages
     */
    private function createScan(string $jobId, array $pages): int
    {
        $this->scans->markSubmitted(
            siteIdentifier: 'main',
            jobId: $jobId,
            sourceType: RemoteScanSourceType::Crawl,
            startUrl: 'https://example.test/',
            sitemapUrl: null,
            status: 'completed',
        );
        $scan = $this->scans->findScanByJobId($jobId);
        self::assertIsArray($scan);
        $scanUid = (int)$scan['uid'];

        $pageRows = [];
        foreach ($pages as $url => $page) {
            $row = ['url' => $url, 'title' => basename($url), 'httpStatus' => 200, 'issuesCount' => $page['issues']];
            if (array_key_exists('httpStatus', $page)) {
                // null: the crawler reported no status for the page
                $row['httpStatus'] = $page['httpStatus'];
            }
            $pageRows[] = $row;
        }
        $pageUids = $this->scans->saveScanPages($scanUid, RemoteScanSourceType::Crawl, $pageRows);

        foreach ($pages as $url => $page) {
            foreach ($page['rules'] as $key => $value) {
                [$ruleId, $status] = is_string($key) ? [$key, $value] : [$value, 'open'];
                $this->issues->saveIssue($scanUid, $pageUids[$url], [
                    'ruleId' => $ruleId,
                    'impact' => 'serious',
                    'nodes' => [['html' => '<a></a>']],
                    'status' => $status,
                ]);
            }
        }

        return $scanUid;
    }
}
