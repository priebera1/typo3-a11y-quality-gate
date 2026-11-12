<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Mcp\McpCallRefused;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Mcp\McpUserContext;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Functional\Security\RemoteResultFixtureTrait;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Sol post-remediation review R01, R02, R03 and R06 for agent access, with the reproductions inverted: an agent receives
 * stored frontend results only as far as its TYPO3 user's page, language, table and record rights reach at call time —
 * findings, elements, snippets, totals, top issue types, pagination, scan metadata and history alike. Real users,
 * groups, permissions, site configuration and rows (RemoteResultFixtureTrait); nothing about permissions is mocked.
 */
final class McpRemoteResultAuthorizationFunctionalTest extends AbstractFunctionalTestCase
{
    use RemoteResultFixtureTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRemoteResultFixture();
        $this->get(McpSiteSettingsRepository::class)->save('client-a', true, ['findings:read', 'history:read', 'scans:run-page'], 1);
    }

    #[Test]
    public function anEditorSeesOnlyFindingsOfReadablePagesAndRecordsWithConsistentCounts(): void
    {
        $this->stamp();
        $result = $this->results(self::EDITOR, ['pageSize' => 50]);

        $this->assertNoneOf(self::NEVER_FOR_EDITOR, $result);
        self::assertSame(['root-rule', 'document-title', 'german-rule', 'mixed-rule'], array_column($result['findings'], 'ruleId'));
        $byRule = array_column($result['findings'], null, 'ruleId');
        self::assertSame(1, $byRule['root-rule']['occurrences'], 'the unmapped template element is withheld and not counted');
        self::assertSame(['<p>ROOT_CONTENT</p>'], array_column($byRule['root-rule']['untrusted']['elements'], 'snippet'));
        self::assertSame(['<p>PUBLIC_CONTENT</p>'], array_column($byRule['mixed-rule']['untrusted']['elements'], 'snippet'));
        self::assertSame(4, $result['total']);
        self::assertSame(4, $result['summary']['issueTypes']);
        self::assertSame(1 + 1 + 1 + 1, $result['summary']['occurrences']);
        self::assertSame(['critical' => 1, 'serious' => 2, 'moderate' => 1, 'minor' => 0, 'unrated' => 0], $result['summary']['occurrencesByImpact']);
        self::assertSame(['root-rule', 'german-rule', 'document-title', 'mixed-rule'], array_column($result['summary']['topIssueTypes'], 'ruleId'));

        $seen = [];
        for ($page = 1; $page <= 5; $page++) {
            $slice = $this->results(self::EDITOR, ['pageSize' => 1, 'page' => $page]);
            self::assertSame(4, $slice['total']);
            $seen = array_merge($seen, array_column($slice['findings'], 'ruleId'));
        }
        self::assertSame(['root-rule', 'document-title', 'german-rule', 'mixed-rule'], $seen);
    }

    #[Test]
    public function anAdministratorStillReadsEverything(): void
    {
        $this->stamp();
        $result = $this->results(self::ADMIN, ['pageSize' => 50]);

        self::assertSame(9, $result['total']);
        self::assertStringContainsString('SYNTHETIC_RESTRICTED_MARKER', (string)json_encode($result));
        self::assertStringContainsString('UNMAPPED_TEMPLATE_MARKER', (string)json_encode($result));
    }

    #[Test]
    public function aSlugReassignedAfterTheScanDoesNotMoveHistoricalResultsToAnotherPage(): void
    {
        // Sol R01 #1 inverted: the owner recorded at persist time (page 3) decides, not today's slug owner (page 2).
        $this->stamp();
        $pages = $this->get(ConnectionPool::class)->getConnectionForTable('pages');
        $pages->update('pages', ['slug' => '/restricted-moved'], ['uid' => 3]);
        $pages->update('pages', ['slug' => '/restricted-page'], ['uid' => 2]);

        $result = $this->results(self::EDITOR, ['pageSize' => 50]);
        $this->assertNoneOf(['SYNTHETIC_RESTRICTED_MARKER', 'restricted-only-rule'], $result);
    }

    #[Test]
    public function aTranslationWithItsOwnDeniedPermissionsIsWithheld(): void
    {
        // Sol R01 #2 inverted.
        $this->stamp();
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 20]);
        self::assertSame(['default' => true, 'translated' => false], $this->runAs(self::EDITOR, fn (): array => [
            'default' => $this->get(ScopeAccessService::class)->canReadPage(2),
            'translated' => $this->get(ScopeAccessService::class)->canReadPage(20),
        ]));

        $result = $this->results(self::EDITOR, ['pageSize' => 50]);
        self::assertNotContains('german-rule', array_column($result['findings'], 'ruleId'));
        $this->assertNoneOf(['GERMAN_CONTENT'], $result);
    }

    #[Test]
    public function aPageScanResultWithoutProvableOwnerIsWithheld(): void
    {
        // Sol R01 #3 inverted: the crawler stored a URL no page of the site owns (redirect, rewrite).
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['url' => 'https://a.test/unmapped-restricted-target'], ['uid' => 60]);
        $this->stamp();

        $result = $this->execute('aqg_get_scan_results', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_PAGE], self::EDITOR);
        self::assertSame([], $result['findings']);
        self::assertSame(0, $result['total']);
        self::assertSame(0, $result['summary']['occurrences']);
        $this->assertNoneOf(['PAGE_SCAN_PUBLIC', 'unmapped-restricted-target'], $result);
        self::assertSame(1, $this->execute('aqg_get_scan_results', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_PAGE], self::ADMIN)['total']);
    }

    #[Test]
    public function aPageScanRedirectedToALanguageTheUserMayNotUseIsWithheld(): void
    {
        // Sol R01 #4 inverted.
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['url' => 'https://a.test/de/oeffentlich'], ['uid' => 60]);
        $this->stamp();
        self::assertFalse($this->runAs(self::DEFAULT_LANGUAGE_ONLY, fn ($user): bool => $user->checkLanguageAccess(1)));

        $result = $this->execute('aqg_get_scan_results', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_PAGE], self::DEFAULT_LANGUAGE_ONLY);
        self::assertSame([], $result['findings']);
        $this->assertNoneOf(['PAGE_SCAN_PUBLIC'], $result);
    }

    #[Test]
    public function aNumericPageIdAndAKnownRedirectTargetStayWithheld(): void
    {
        // The earlier fixes (?id=, redirect to a denied page) are preserved by the stored provenance.
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['url' => 'https://a.test/restricted-page'], ['uid' => 60]);
        $this->stamp();

        $this->assertNoneOf(['QUERY_ID_MARKER', 'query-id-rule'], $this->results(self::EDITOR, ['pageSize' => 50]));
        self::assertSame([], $this->execute('aqg_get_scan_results', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_PAGE], self::EDITOR)['findings']);
    }

    #[Test]
    public function resultsStoredBeforeProvenanceAreForAdministratorsOnly(): void
    {
        // No stamp(): rows as stored by earlier versions. Their owner is unknown, so it is never assumed.
        $editor = $this->results(self::EDITOR, ['pageSize' => 50]);
        self::assertSame([], $editor['findings']);
        self::assertSame(0, $editor['summary']['occurrences']);
        self::assertSame(9, $this->results(self::ADMIN, ['pageSize' => 50])['total']);
    }

    #[Test]
    public function elementsOfATableTheUserMayNotReadAreWithheldEvenOnAReadablePage(): void
    {
        // Sol R02 inverted: read and modify rights on tt_content removed; page 2 stays readable.
        $this->stamp();
        $this->get(ConnectionPool::class)->getConnectionForTable('be_groups')->update('be_groups', ['tables_select' => 'pages', 'tables_modify' => 'pages'], ['uid' => 10]);
        self::assertFalse($this->runAs(self::EDITOR, fn ($user): bool => $user->check('tables_select', 'tt_content')));

        $result = $this->results(self::EDITOR, ['pageSize' => 50]);
        $this->assertNoneOf(['PUBLIC_CONTENT', 'ROOT_CONTENT', 'GERMAN_CONTENT'], $result);
        self::assertSame(['document-title'], array_column($result['findings'], 'ruleId'), 'a page-level finding has no record');
    }

    #[Test]
    public function aMappedRecordInALanguageTheUserMayNotUseIsWithheld(): void
    {
        $this->stamp();
        $result = $this->results(self::DEFAULT_LANGUAGE_ONLY, ['pageSize' => 50]);
        $this->assertNoneOf(['GERMAN_CONTENT', 'german-rule'], $result);
        self::assertContains('mixed-rule', array_column($result['findings'], 'ruleId'));
    }

    #[Test]
    public function permissionsRemovedAfterConsentApplyToTheNextCall(): void
    {
        $this->stamp();
        self::assertContains('mixed-rule', array_column($this->results(self::EDITOR)['findings'], 'ruleId'));
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 2]);

        $result = $this->results(self::EDITOR, ['pageSize' => 50]);
        self::assertSame(['root-rule'], array_column($result['findings'], 'ruleId'));
    }

    #[Test]
    public function aPageScanOfADeletedPageIsNotReadableAnywhereNorFallsBackToTheSiteRoot(): void
    {
        $this->stamp();
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['start_url' => 'https://a.test/PRIVATE_DELETED_PAGE'], ['uid' => 2]);
        $pool->getConnectionForTable('pages')->update('pages', ['deleted' => 1], ['uid' => 2]);

        $list = $this->execute('aqg_list_scans', ['siteIdentifier' => 'client-a'], self::EDITOR);
        $this->assertNoneOf(['PRIVATE_DELETED_PAGE', self::JOB_PAGE], $list);
        $this->assertRefused('scan_not_found', 'aqg_get_scan_status', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_PAGE], self::EDITOR);
        $this->assertRefused('scan_not_found', 'aqg_get_scan_results', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_PAGE], self::EDITOR);
        $scan = $pool->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => 2])->fetchAssociative();
        self::assertFalse($this->runAs(self::EDITOR, fn (): bool => $this->get(ScopeAccessService::class)->canReadRemoteScan($scan)), 'module paths share the predicate');
        self::assertTrue($this->runAs(self::ADMIN, fn (): bool => $this->get(ScopeAccessService::class)->canReadRemoteScan($scan)));
    }

    #[Test]
    public function aPageScanOfAPageMovedToAnotherSiteIsNotReadable(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 100, 'pid' => 0, 'title' => 'Client B', 'doktype' => 1, 'is_siteroot' => 1, 'slug' => '/', 'perms_everybody' => 31, 'deleted' => 0,
        ]);
        $this->get(SiteWriter::class)->createNewBasicSite('client-b', 100, 'https://b.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $pool->getConnectionForTable('pages')->update('pages', ['pid' => 100], ['uid' => 2]);
        $this->assertRefused('scan_not_found', 'aqg_get_scan_status', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_PAGE], self::EDITOR);
    }

    #[Test]
    public function aScanTooLargeToCheckShowsNothingRatherThanEverything(): void
    {
        $this->stamp();
        $rows = [];
        for ($uid = 1000; $uid < 1000 + RemoteResultAuthorizationService::MAX_RESULT_PAGES + 1; $uid++) {
            $rows[] = [$uid, 1, 'https://a.test/bulk-' . $uid];
        }
        foreach (array_chunk($rows, 2000) as $chunk) {
            $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan_page')->bulkInsert('tx_a11y_remote_scan_page', $chunk, ['uid', 'remote_scan', 'url']);
        }
        $this->assertRefused('results_too_large', 'aqg_get_scan_results', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_SITE], self::EDITOR);
        $csv = $this->runAs(self::EDITOR, fn (): string => $this->get(RemoteExportBuilder::class)->buildOverviewCsv('client-a', 1));
        $this->assertNoneOf(['ROOT_CONTENT', 'PUBLIC_CONTENT'], ['csv' => $csv]);
    }

    #[Test]
    public function inaccessibleHistoryDoesNotChangeTheSubtreeUsersListing(): void
    {
        $before = $this->execute('aqg_list_scans', ['siteIdentifier' => 'client-a'], self::SUBTREE);
        $rows = [];
        for ($uid = 1000; $uid < 6000; $uid++) {
            $rows[] = [$uid, 'client-a', sprintf('%08d-0000-4000-8000-000000000000', $uid), 'crawl', 'site', 0, 0, 'https://a.test/', 'completed', 1, 100000 + $uid, time()];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->bulkInsert('tx_a11y_remote_scan', $chunk, [
                'uid', 'site_identifier', 'job_id', 'source_type', 'scan_scope', 'page_uid', 'language_uid', 'start_url', 'status', 'finished_at', 'crdate', 'tstamp',
            ]);
        }
        // Page scans of a page the subtree user cannot open (page 3), newer than everything.
        $this->insertScanRow($this->get(ConnectionPool::class), 7000, 'page', 3, '77777777-7777-4777-8777-777777777777', 300000);
        $after = $this->execute('aqg_list_scans', ['siteIdentifier' => 'client-a'], self::SUBTREE);

        $shape = static fn (array $list): array => array_intersect_key($list, array_flip(['scans', 'total', 'listed', 'hasMore', 'searchComplete', 'note']));
        self::assertSame($shape($before), $shape($after), 'identical authorized history, whatever inaccessible history exists');
        self::assertSame([self::JOB_PAGE], array_column($after['scans'], 'jobId'));
        self::assertTrue($after['searchComplete']);
        self::assertNull($after['nextCursor']);
    }

    private function runAs(int $beUserUid, \Closure $callback): mixed
    {
        return $this->get(McpUserContext::class)->runAs($beUserUid, $callback);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function results(int $beUserUid, array $arguments = []): array
    {
        return $this->execute('aqg_get_scan_results', ['siteIdentifier' => 'client-a', 'jobId' => self::JOB_SITE] + $arguments, $beUserUid);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function execute(string $tool, array $arguments, int $beUserUid): array
    {
        return $this->get(McpToolExecutor::class)->execute($tool, $arguments, [
            'tokenId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'tokenSuffix' => 'test',
            'clientName' => 'Test agent',
            'beUserUid' => $beUserUid,
            'scopes' => ['findings:read', 'history:read', 'scans:run-page'],
            'siteIdentifiers' => ['client-a'],
            'expiresAt' => '2026-12-01T00:00:00Z',
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertRefused(string $reason, string $tool, array $arguments, int $beUserUid): void
    {
        try {
            $this->execute($tool, $arguments, $beUserUid);
            self::fail(sprintf('%s should be refused with %s', $tool, $reason));
        } catch (McpCallRefused $refused) {
            self::assertSame($reason, $refused->reason, $tool);
        }
    }
}
