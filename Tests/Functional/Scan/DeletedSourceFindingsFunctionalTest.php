<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * U-09 (2026-11-05 company acceptance): 22 open findings stayed attached to deleted pages and records and were counted
 * in the Overview long after the content was gone. A full site scan resolves open findings whose page or source record
 * is deleted or no longer exists — resolved as history, never deleted. Rows of site identifiers that are no longer
 * configured (a renamed site, a deleted fixture root) are swept too; other configured sites are left to their own scans.
 */
final class DeletedSourceFindingsFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 0, 0], [2, 1, 0], [3, 1, 1]] as [$uid, $pid, $deleted]) {
            $pool->getConnectionForTable('pages')->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => 'P' . $uid, 'deleted' => $deleted]);
        }
        foreach ([[10, 2, 0], [11, 2, 1]] as [$uid, $pid, $deleted]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => $uid, 'pid' => $pid, 'deleted' => $deleted]);
        }
        foreach ([
            [1, 2, 'tt_content', 10, 'northwind'], // live record on a live page: stays open
            [2, 2, 'tt_content', 11, 'northwind'], // deleted record
            [3, 3, 'tt_content', 10, 'northwind'], // deleted page
            [4, 9, 'pages', 9, 'northwind'],       // page row gone entirely
            [5, 2, 'tt_content', 12, 'northwind'], // record row gone entirely
            [6, 3, 'tt_content', 10, 'careers'],   // another site: not this scan's business
            [7, 2, 'tx_unknown_table', 3, 'northwind'], // a table without TCA: left alone
            [8, 3, 'tt_content', 10, 'main'],      // a renamed (no longer configured) site's row on a deleted page
        ] as [$uid, $page, $table, $source, $site]) {
            $pool->getConnectionForTable('tx_a11y_issue')->insert('tx_a11y_issue', [
                'uid' => $uid, 'site_identifier' => $site, 'page_uid' => $page, 'source_type' => 'rte', 'source_table' => $table,
                'source_uid' => $source, 'source_field' => 'bodytext', 'rule_id' => 'rte.non_descriptive_link', 'severity' => 2,
                'fingerprint' => 'fp' . $uid, 'status' => 0, 'message' => 'm', 'hint' => '', 'context_snippet' => '', 'context_path' => '',
                'css_selector' => '', 'frontend_url' => '', 'ignored_reason' => '', 'ignored_by_name' => '', 'ignored_by_username' => '',
                'resolved_by_name' => '', 'resolved_by_username' => '',
            ]);
        }
    }

    #[Test]
    public function findingsOfDeletedPagesAndRecordsAreResolved(): void
    {
        $repository = $this->get(IssueRepository::class);
        self::assertSame(['main'], $repository->findSiteIdentifiersNotIn(['northwind', 'careers']));
        $resolved = $repository->resolveOpenOfDeletedSources(['northwind', 'main'], 40);

        self::assertSame(5, $resolved);
        $rows = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['uid', 'status', 'last_seen_scan_uid'], 'tx_a11y_issue', [], [], ['uid' => 'ASC'])->fetchAllAssociative();
        self::assertSame(
            [[1, 0], [2, 1], [3, 1], [4, 1], [5, 1], [6, 0], [7, 0], [8, 1]],
            array_map(static fn (array $r): array => [(int)$r['uid'], (int)$r['status']], $rows)
        );
        self::assertSame(40, (int)$rows[1]['last_seen_scan_uid']);
    }
}
