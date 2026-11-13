<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Scan\ContentCollector;
use Priebera\A11yQualityGate\Scan\PageCollector;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The Content Scan reads live records only. Workspace drafts keep the positive pid of their live page (a changed
 * version) or of their page (a record or page created in a workspace); none of them may become a live finding, and the
 * live tree has no draft pages.
 */
final class WorkspaceIsolationContentScanFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'fixture';
    private const ROOT = 1;
    private const PAGE = 2;
    private const WORKSPACE = 7;

    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pages = $pool->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => self::ROOT, 'pid' => 0, 'title' => 'Fixture', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => self::PAGE, 'pid' => self::ROOT, 'title' => 'Live', 'slug' => '/live', 'doktype' => 1]);
        // A version of the live page and a page created in the workspace: no pages of the live tree.
        $pages->insert('pages', ['uid' => 3, 'pid' => self::ROOT, 'title' => 'Live (draft)', 'slug' => '/live', 'doktype' => 1, 't3ver_oid' => self::PAGE, 't3ver_wsid' => self::WORKSPACE]);
        $pages->insert('pages', ['uid' => 4, 'pid' => self::ROOT, 'title' => 'New draft page', 'slug' => '/new', 'doktype' => 1, 't3ver_wsid' => self::WORKSPACE, 't3ver_state' => 1]);

        $content = $pool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 10, 'pid' => self::PAGE, 'CType' => 'text', 'bodytext' => '<p><a href="/contact"></a></p>']);
        // The draft version of element 10 and an element created in the workspace, both on the live page.
        $content->insert('tt_content', ['uid' => 11, 'pid' => self::PAGE, 'CType' => 'text', 'bodytext' => '<p><a href="https://example.test/private">click here</a></p>', 't3ver_oid' => 10, 't3ver_wsid' => self::WORKSPACE]);
        $content->insert('tt_content', ['uid' => 12, 'pid' => self::PAGE, 'CType' => 'text', 'bodytext' => '<p><a href="https://example.test/draft">read more</a></p>', 't3ver_wsid' => self::WORKSPACE, 't3ver_state' => 1]);
        $content->insert('tt_content', ['uid' => 13, 'pid' => 4, 'CType' => 'text', 'bodytext' => '<p><a href="https://example.test/new">here</a></p>', 't3ver_wsid' => self::WORKSPACE, 't3ver_state' => 1]);

        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, self::ROOT, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function onlyLiveRecordsAndPagesAreCollected(): void
    {
        self::assertSame([self::ROOT, self::PAGE], $this->get(PageCollector::class)->collectSubtree(self::ROOT));
        self::assertSame([10], array_map(
            static fn (array $envelope): int => (int)$envelope['record']['uid'],
            $this->get(ContentCollector::class)->collectForPage(self::PAGE),
        ));
    }

    #[Test]
    public function aSiteScanStoresNoDraftAsALiveFinding(): void
    {
        $this->get(ScanOrchestrator::class)->scanSubtree(self::SITE, self::ROOT);

        $findings = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->fetchAllAssociative('SELECT source_table, source_uid, page_uid, context_snippet FROM tx_a11y_issue WHERE deleted = 0');
        $sources = array_values(array_unique(array_map(static fn (array $row): string => $row['source_table'] . ':' . $row['source_uid'], $findings)));

        self::assertContains('tt_content:10', $sources, 'The live element is checked.');
        self::assertSame([], array_values(array_intersect($sources, ['tt_content:11', 'tt_content:12', 'tt_content:13', 'pages:3', 'pages:4'])));
        foreach ($findings as $finding) {
            self::assertStringNotContainsString('example.test', (string)$finding['context_snippet'], 'No draft text reaches the stored results.');
            self::assertContains((int)$finding['page_uid'], [self::ROOT, self::PAGE]);
        }
    }

    #[Test]
    public function findingsStoredOnDraftPagesByAnEarlierVersionAreNotCountedOrExported(): void
    {
        $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => self::PAGE, 'context_snippet' => '<img src="live.jpg">']);
        foreach ([3, 4] as $draftPage) {
            $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => $draftPage, 'context_snippet' => '<img src="draft.jpg">']);
        }

        $issues = $this->get(IssueRepository::class);
        self::assertSame(1, $issues->countOpenTotalsForSite(self::SITE)['total']);
        self::assertSame([self::PAGE], array_values(array_unique(array_map(
            static fn (array $issue): int => (int)$issue['page_uid'],
            $issues->findForExport(self::SITE),
        ))));
    }

    #[Test]
    public function aFindingStoredFromADraftByAnEarlierVersionIsResolvedByTheNextScan(): void
    {
        $draftFinding = $this->insertIssue([
            'site_identifier' => self::SITE,
            'page_uid' => self::PAGE,
            'source_uid' => 11,
            'source_type' => 'rte',
            'rule_id' => 'rte.non_descriptive_link',
            'context_snippet' => '<a href="https://example.test/private">click here</a>',
        ]);

        $this->get(ScanOrchestrator::class)->scanPage(self::SITE, self::PAGE);

        self::assertNotSame(0, (int)$this->findByUid('tx_a11y_issue', $draftFinding)['status'], 'No longer an open live finding.');
    }
}
