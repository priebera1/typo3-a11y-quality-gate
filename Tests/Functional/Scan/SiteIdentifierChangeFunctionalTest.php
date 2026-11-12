<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * D-03 (2026-11-05 company acceptance): content saved while its site was called `main`, the site then renamed to
 * `northwind`. After the editor fixed the link, the fresh row was resolved but the `main` row stayed open and the save
 * message still said "This content element has 1 accessibility issue".
 */
final class SiteIdentifierChangeFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $issues = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue');
        foreach ([
            [8, 'main', 4, 0],       // stored before the rename: stale
            [56, 'northwind', 4, 1], // the current site's row, already resolved by the fix
            [57, 'careers', 4, 0],   // another configured site: never touched
            [58, 'main', 7, 0],      // another page of the renamed site: resolved only when that page is scanned
        ] as [$uid, $site, $page, $status]) {
            $issues->insert('tx_a11y_issue', [
                'uid' => $uid, 'site_identifier' => $site, 'page_uid' => $page, 'source_type' => 'rte', 'source_table' => 'tt_content',
                'source_uid' => 5, 'source_field' => 'bodytext', 'rule_id' => 'rte.non_descriptive_link', 'severity' => 2,
                'fingerprint' => 'fp' . $uid, 'status' => $status, 'message' => 'Link text "Click here" is not descriptive.', 'hint' => '',
                'context_snippet' => '', 'context_path' => '', 'css_selector' => '', 'frontend_url' => '', 'ignored_reason' => '',
                'ignored_by_name' => '', 'ignored_by_username' => '', 'resolved_by_name' => '', 'resolved_by_username' => '',
            ]);
        }
    }

    #[Test]
    public function theRecordsOpenFindingsAreThoseOfItsCurrentSite(): void
    {
        $repository = $this->get(IssueRepository::class);

        self::assertSame([], $repository->findOpenForRecord('tt_content', 5, 'bodytext', 'northwind'));
        self::assertCount(3, $repository->findOpenForRecord('tt_content', 5, 'bodytext'), 'Without a site the history stays readable.');
    }

    #[Test]
    public function rescanningThePageRetiresRowsOfUnconfiguredSitesOnly(): void
    {
        $resolved = $this->get(IssueRepository::class)->resolveOpenOfUnconfiguredSitesForPage(4, ['northwind', 'careers'], 12);

        self::assertSame(1, $resolved);
        $rows = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['uid', 'status', 'site_identifier'], 'tx_a11y_issue', [], [], ['uid' => 'ASC'])->fetchAllAssociative();
        self::assertSame(
            [[8, 1, 'main'], [56, 1, 'northwind'], [57, 0, 'careers'], [58, 0, 'main']],
            array_map(static fn (array $r): array => [(int)$r['uid'], (int)$r['status'], (string)$r['site_identifier']], $rows),
            'Resolved, not deleted or re-keyed; other sites and other pages untouched.'
        );
    }
}
