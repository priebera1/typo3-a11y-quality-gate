<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Security;

use Priebera\A11yQualityGate\Service\RemoteResultProvenanceService;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Shared fixture of the stored-frontend-result authorization tests (Sol post-remediation review R01/R02/R03/R05/R06).
 *
 * Site "client-a" (https://a.test/, German under /de/):
 *   1 root "/" (tt_content 401) · 2 "/public" (tt_content 400; German translation 20 "/oeffentlich" with tt_content 403)
 *   3 "/restricted-page" (no access for editors) · 5 "/storage" (no access for editors; tt_content 500)
 * Users: 2 editor (mount 1), 3 admin, 4 default language only, 5 subtree (mount 2).
 * Site scan 1 / page scan 2 (page 2) with result pages 50–57 / 60.
 */
trait RemoteResultFixtureTrait
{
    private const JOB_SITE = '11111111-1111-4111-8111-111111111111';
    private const JOB_PAGE = '22222222-2222-4222-8222-222222222222';
    private const EDITOR = 2;
    private const ADMIN = 3;
    private const DEFAULT_LANGUAGE_ONLY = 4;
    private const SUBTREE = 5;

    /** Content an editor must never receive, in any output. */
    private const NEVER_FOR_EDITOR = [
        'SYNTHETIC_RESTRICTED_MARKER', 'restricted-page', 'restricted-only-rule', 'SECRET_STORAGE_MARKER', 'storage-only',
        'SECRET_UNMAPPED_STORAGE_MARKER', 'UNMAPPED_TEMPLATE_MARKER', 'UNMAPPED_PUBLIC_MARKER', 'QUERY_ID_MARKER',
    ];

    private function createRemoteResultFixture(): void
    {
        $pool = $this->get(ConnectionPool::class);
        foreach ([
            [1, 0, 'Client A', 1, '/', 31, 0, 0],
            [2, 1, 'Public', 0, '/public', 31, 0, 0],
            [3, 1, 'Restricted', 0, '/restricted-page', 0, 0, 0],
            [5, 1, 'Storage', 0, '/storage', 0, 0, 0],
            [20, 1, 'Öffentlich', 0, '/oeffentlich', 31, 1, 2],
        ] as [$uid, $pid, $title, $isRoot, $slug, $perms, $language, $parent]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'is_siteroot' => $isRoot, 'slug' => $slug,
                'sys_language_uid' => $language, 'l10n_parent' => $parent,
                'perms_userid' => 0, 'perms_groupid' => 0, 'perms_user' => 0, 'perms_group' => 0, 'perms_everybody' => $perms,
                'deleted' => 0, 'hidden' => 0,
            ]);
        }
        foreach ([[400, 2, 0], [401, 1, 0], [403, 2, 1], [500, 5, 0]] as [$uid, $pid, $language]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => $uid, 'pid' => $pid, 'CType' => 'text', 'header' => 'Content ' . $uid, 'sys_language_uid' => $language]);
        }

        $connect = "options.a11y_quality_gate.mcp.connect = 1\n";
        $this->insertGroup($pool, 10, 'Editors', '1', $connect, '');
        $this->insertGroup($pool, 11, 'Default language only', '1', $connect, '0');
        $this->insertGroup($pool, 12, 'Public subtree', '2', $connect, '');
        $this->insertUser($pool, self::EDITOR, 'editor', '10');
        $this->insertUser($pool, self::ADMIN, 'admin', '10', 1);
        $this->insertUser($pool, self::DEFAULT_LANGUAGE_ONLY, 'default-language', '11');
        $this->insertUser($pool, self::SUBTREE, 'subtree', '12');

        $this->get(SiteWriter::class)->write('client-a', [
            'rootPageId' => 1,
            'base' => 'https://a.test/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
        ]);
        $this->get(SiteFinder::class)->getAllSites(false);

        $this->insertScanRow($pool, 1, 'site', 0, self::JOB_SITE);
        $this->insertScanRow($pool, 2, 'page', 2, self::JOB_PAGE);

        // [finding uid, rule, impact, nodes_count, [[mapped_table, mapped_uid, snippet], …]]
        $this->insertResultPage($pool, 1, 50, 'https://a.test/', [
            [70, 'root-rule', 'critical', 2, [['tt_content', 401, '<p>ROOT_CONTENT</p>'], ['', 0, '<nav>UNMAPPED_TEMPLATE_MARKER</nav>']]],
        ]);
        $this->insertResultPage($pool, 1, 51, 'https://a.test/restricted-page', [
            [99, 'restricted-synthetic-finding', 'serious', 1, [['pages', 3, '<p>SYNTHETIC_RESTRICTED_MARKER</p>']]],
            [98, 'restricted-only-rule', 'critical', 4, [['', 0, '<p>SYNTHETIC_RESTRICTED_MARKER 2</p>']]],
        ]);
        $this->insertResultPage($pool, 1, 52, 'https://a.test/public', [
            [80, 'mixed-rule', 'moderate', 3, [['tt_content', 400, '<p>PUBLIC_CONTENT</p>'], ['tt_content', 500, '<p>SECRET_STORAGE_MARKER</p>'], ['', 0, '<p>UNMAPPED_PUBLIC_MARKER</p>']]],
            [81, 'storage-only', 'minor', 1, [['tt_content', 500, '<p>SECRET_STORAGE_MARKER only</p>']]],
            [82, 'unmapped-only', 'minor', 1, [['', 0, '<p>SECRET_UNMAPPED_STORAGE_MARKER</p>']]],
            [83, 'document-title', 'serious', 0, []],
        ]);
        $this->insertResultPage($pool, 1, 54, 'https://a.test/de/oeffentlich', [[85, 'german-rule', 'serious', 1, [['tt_content', 403, '<p>GERMAN_CONTENT</p>']]]]);
        $this->insertResultPage($pool, 1, 57, 'https://a.test/?id=3', [[93, 'query-id-rule', 'serious', 1, [['tt_content', 401, '<p>QUERY_ID_MARKER</p>']]]]);
        $this->insertResultPage($pool, 2, 60, 'https://a.test/public', [
            [110, 'page-scan-rule', 'serious', 2, [['tt_content', 400, '<p>PAGE_SCAN_PUBLIC</p>'], ['tt_content', 500, '<p>SECRET_STORAGE_MARKER page scan</p>']]],
        ]);
    }

    /**
     * @param list<string> $markers
     * @param array<string, mixed> $output
     */
    private function assertNoneOf(array $markers, array $output): void
    {
        $encoded = (string)json_encode($output, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        foreach ($markers as $marker) {
            self::assertStringNotContainsString($marker, $encoded, 'withheld: ' . $marker);
        }
    }

    /** What persistence does when the crawler's results are stored. */
    private function stamp(): void
    {
        $this->get(RemoteResultProvenanceService::class)->stampScan(1, 'client-a');
        $this->get(RemoteResultProvenanceService::class)->stampScan(2, 'client-a');
    }





    private function insertGroup(ConnectionPool $pool, int $uid, string $title, string $mounts, string $tsConfig, string $languages): void
    {
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => $uid, 'pid' => 0, 'title' => $title, 'groupMods' => 'web,web_a11y', 'tables_select' => 'pages,tt_content',
            'tables_modify' => 'pages,tt_content', 'db_mountpoints' => $mounts, 'TSconfig' => $tsConfig, 'allowed_languages' => $languages,
            'deleted' => 0, 'hidden' => 0,
        ]);
    }

    private function insertUser(ConnectionPool $pool, int $uid, string $username, string $groups, int $admin = 0): void
    {
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => $uid, 'username' => $username, 'admin' => $admin, 'usergroup' => $groups, 'options' => 3,
            'workspace_perms' => 1, 'disable' => 0, 'deleted' => 0,
        ]);
    }

    private function insertScanRow(ConnectionPool $pool, int $uid, string $scope, int $pageUid, string $jobId, int $crdate = 0): void
    {
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => $uid, 'site_identifier' => 'client-a', 'job_id' => $jobId, 'source_type' => $scope === 'page' ? 'single_page' : 'crawl',
            'scan_scope' => $scope, 'page_uid' => $pageUid, 'language_uid' => 0, 'start_url' => 'https://a.test/',
            'status' => 'completed', 'finished_at' => 1000 + $uid, 'persisted_at' => 2000 + $uid, 'crdate' => $crdate > 0 ? $crdate : 1000 + $uid, 'tstamp' => time(),
            'pages_scanned' => $scope === 'page' ? 1 : 7, 'pages_total' => $scope === 'page' ? 1 : 7, 'issues_total' => 14,
        ]);
    }

    /**
     * @param list<array{0: int, 1: string, 2: string, 3: int, 4: list<array{0: string, 1: int, 2: string}>}> $findings
     */
    private function insertResultPage(ConnectionPool $pool, int $scanUid, int $uid, string $url, array $findings): void
    {
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', [
            'uid' => $uid, 'remote_scan' => $scanUid, 'url' => $url, 'title' => 'Page ' . $url, 'http_status' => 200, 'crdate' => time(), 'tstamp' => time(),
        ]);
        foreach ($findings as [$issueUid, $ruleId, $impact, $nodesCount, $nodes]) {
            $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', [
                'uid' => $issueUid, 'remote_scan' => $scanUid, 'remote_scan_page' => $uid, 'rule_id' => $ruleId, 'impact' => $impact,
                'help' => 'Help for ' . $ruleId, 'nodes_count' => $nodesCount, 'crdate' => time(), 'tstamp' => time(),
            ]);
            foreach ($nodes as [$table, $recordUid, $snippet]) {
                $pool->getConnectionForTable('tx_a11y_remote_issue_node')->insert('tx_a11y_remote_issue_node', [
                    'remote_issue' => $issueUid, 'target_json' => '["main"]', 'html_snippet' => $snippet,
                    'mapped_table' => $table, 'mapped_uid' => $recordUid, 'crdate' => time(), 'tstamp' => time(),
                ]);
            }
        }
    }
}
