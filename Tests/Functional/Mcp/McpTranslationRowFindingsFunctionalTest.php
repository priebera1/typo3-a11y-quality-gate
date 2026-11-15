<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Mcp\McpCallRefused;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Owner MCP pilot QA 2026-11-15 (TYPO3 14, site main): findings 74357–74360 carried `location.pageUid = 745`,
 * `languageUid = 0` — 745 is the German translation record of page 737. `aqg_list_content_findings(pageUid=745)` answered
 * page_not_found (translation uids are no pages for agents), the list of 737 did not contain them, only the site-wide
 * list did.
 *
 * Cause: releases before 3.0.0 scanned a page translation as a page of its own (D-02) and stored its rendered findings as
 * "page <translation uid>, language 0". 3.0.0 no longer writes such rows and resolves them when the translation is
 * rendered again, but the stored rows stay (history is never rewritten). The agent tools now read them as findings of the
 * default page in the translation's language — under the same access rules as that page and language — and never change
 * them.
 */
final class McpTranslationRowFindingsFunctionalTest extends AbstractFunctionalTestCase
{
    private const SCOPES = ['findings:read', 'history:read', 'content:read'];
    private const ADMIN = 1;
    private const EDITOR = 2;
    private const ENGLISH_ONLY = 3;
    private const NO_GERMAN_PAGE = 4;

    /** @var array<string, int> */
    private array $issues = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        foreach ([
            // uid, pid, title, root, language, l10n_parent, perms_everybody
            [1, 0, 'Root', 1, 0, 0, 31],
            [2, 1, 'Cancel local scan', 0, 0, 0, 31],
            // The German translation record of page 2 (same pid as its default page, like 745 → 737).
            [20, 1, '[DE] Cancel local scan', 0, 1, 2, 31],
            [3, 1, 'Other page', 0, 0, 0, 31],
        ] as [$uid, $pid, $title, $isRoot, $language, $parent, $everybody]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'is_siteroot' => $isRoot, 'slug' => '/p' . $uid,
                'sys_language_uid' => $language, 'l10n_parent' => $parent,
                'perms_userid' => 0, 'perms_groupid' => 0, 'perms_user' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody,
                'deleted' => 0, 'hidden' => 0, 'crdate' => 1000, 'tstamp' => 1000,
            ]);
        }
        foreach ([
            // uid, pid, language, l18n_parent
            [10, 2, 0, 0],
            [14, 2, 1, 10],
        ] as $index => [$uid, $pid, $language, $parent]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'header' => 'Element ' . $uid, 'CType' => 'text', 'colPos' => 0, 'sorting' => 256 * ($index + 1),
                'bodytext' => '<p><button></button></p>', 'sys_language_uid' => $language, 'l18n_parent' => $parent,
                'deleted' => 0, 'crdate' => 1000, 'tstamp' => 1000,
            ]);
        }

        $connect = "options.a11y_quality_gate.mcp.connect = 1\n";
        foreach ([[10, 'Editors', ''], [11, 'English only', '0']] as [$uid, $title, $languages]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'pid' => 0, 'title' => $title, 'groupMods' => 'web,web_a11y,web_layout', 'tables_select' => 'pages,tt_content',
                'tables_modify' => 'pages,tt_content', 'db_mountpoints' => '1', 'TSconfig' => $connect, 'allowed_languages' => $languages,
                'non_exclude_fields' => 'tt_content:bodytext', 'explicit_allowdeny' => 'tt_content:CType:text', 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        foreach ([[self::ADMIN, 'admin', 1, ''], [self::EDITOR, 'editor', 0, '10'], [self::ENGLISH_ONLY, 'english', 0, '11'], [self::NO_GERMAN_PAGE, 'no-german-page', 0, '10']] as [$uid, $name, $admin, $groups]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => $name, 'admin' => $admin, 'usergroup' => $groups, 'options' => 3, 'workspace_perms' => 1,
                'TSconfig' => $connect, 'disable' => 0, 'deleted' => 0,
            ]);
        }

        $this->get(SiteWriter::class)->write('main', [
            'rootPageId' => 1,
            'base' => 'https://main.test/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
        ]);
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->get(McpSiteSettingsRepository::class)->save('main', true, self::SCOPES, 1);

        $old = $this->insertScan(['site_identifier' => 'main', 'root_pid' => 1, 'started_at' => 5000, 'finished_at' => 5100, 'rendered_checked' => 3]);
        $base = ['site_identifier' => 'main', 'first_seen_scan_uid' => $old, 'last_seen_scan_uid' => $old, 'crdate' => 5000, 'tstamp' => 5100];
        $rendered = ['source_field' => '__rendered_html', 'source_type' => 'rendered', 'frontend_url' => 'https://main.test/de/p20'];
        // D-02 legacy rows (2.x): the German page rendered as "page 20, language 0".
        $this->issues['legacyButton'] = $this->insertIssue($rendered + $base + ['page_uid' => 20, 'source_lang_uid' => 0, 'source_table' => 'tt_content',
            'source_uid' => 10, 'rule_id' => 'rendered.empty_button', 'severity' => 1, 'message' => 'Rendered button has no accessible name.',
            'css_selector' => 'div#c10 > button:nth-of-type(1)']);
        $this->issues['legacyResolved'] = $this->insertIssue($rendered + $base + ['page_uid' => 20, 'source_lang_uid' => 0, 'source_table' => 'tt_content',
            'source_uid' => 10, 'rule_id' => 'rendered.img_missing_alt', 'severity' => 1, 'status' => 1, 'message' => 'Image has no alt.']);
        // 3.0.0 rows: the default page with the language.
        $this->issues['germanRte'] = $this->insertIssue($base + ['page_uid' => 2, 'source_lang_uid' => 1, 'source_table' => 'tt_content', 'source_uid' => 14,
            'source_field' => 'bodytext', 'rule_id' => 'rte.button_label_missing', 'severity' => 1]);
        $this->issues['englishRte'] = $this->insertIssue($base + ['page_uid' => 2, 'source_lang_uid' => 0, 'source_table' => 'tt_content', 'source_uid' => 10,
            'source_field' => 'bodytext', 'rule_id' => 'rte.button_label_missing', 'severity' => 1]);
        $this->issues['englishRendered'] = $this->insertIssue(['frontend_url' => 'https://main.test/p2'] + $rendered + $base + ['page_uid' => 2, 'source_lang_uid' => 0,
            'source_table' => 'pages', 'source_uid' => 2, 'rule_id' => 'rendered.main_landmark_missing', 'severity' => 2]);
    }

    #[Test]
    public function aLegacyTranslationRowIsAFindingOfTheDefaultPageInTheTranslationsLanguage(): void
    {
        foreach ([self::ADMIN, self::EDITOR] as $user) {
            $german = $this->ids('aqg_list_content_findings', ['pageUid' => 2, 'languageUid' => 1], $user);
            self::assertSame($this->sorted($this->issues['germanRte'], $this->issues['legacyButton']), $german, 'user ' . $user . ': the German page lists its legacy row');
            $everyLanguage = $this->ids('aqg_list_content_findings', ['pageUid' => 2], $user);
            self::assertContains($this->issues['legacyButton'], $everyLanguage);
            $english = $this->ids('aqg_list_content_findings', ['pageUid' => 2, 'languageUid' => 0], $user);
            self::assertSame($this->sorted($this->issues['englishRte'], $this->issues['englishRendered']), $english, 'the legacy row was rendered in German, not in English');

            $listed = $this->finding($this->execute('aqg_list_content_findings', ['pageUid' => 2, 'languageUid' => 1], $user), $this->issues['legacyButton']);
            self::assertSame(2, $listed['location']['pageUid'], 'never the translation record uid an agent cannot ask for');
            self::assertSame(1, $listed['location']['languageUid']);
            self::assertSame('tt_content', $listed['location']['table']);
            self::assertSame(10, $listed['location']['uid'], 'the stored source is reported as stored');
        }
    }

    #[Test]
    public function theSiteWideListIsTheUnionOfThePageListsAndNamesOnlyAddressablePages(): void
    {
        foreach (['open', 'all'] as $status) {
            $siteWide = $this->execute('aqg_list_content_findings', ['status' => $status, 'pageSize' => 50], self::ADMIN);
            $union = [];
            foreach ($siteWide['findings'] as $finding) {
                $location = $finding['location'];
                // Every page a site-wide finding names can be listed, and its page list contains the finding.
                $pageList = $this->ids('aqg_list_content_findings', ['pageUid' => $location['pageUid'], 'languageUid' => $location['languageUid'], 'status' => $status, 'pageSize' => 50], self::ADMIN);
                self::assertContains($finding['findingId'], $pageList, $status . ': finding ' . $finding['findingId'] . ' is on its own page list');
                $union[] = $finding['findingId'];
            }
            self::assertNotContains(20, array_map(static fn (array $finding): int => $finding['location']['pageUid'], $siteWide['findings']));
            self::assertSame($status === 'all' ? 5 : 4, $siteWide['total']);
            self::assertCount($siteWide['total'], array_unique($union));
        }
        // The translation record's uid is still no page for agents.
        $this->assertRefused('page_not_found', 'aqg_list_content_findings', ['pageUid' => 20], self::ADMIN);
        $this->assertRefused('page_not_found', 'aqg_list_content_findings', ['pageUid' => 20, 'languageUid' => 1], self::ADMIN);
    }

    #[Test]
    public function detailGuidanceAndPageSearchUseTheSameAttribution(): void
    {
        $context = $this->execute('aqg_get_finding_context', ['findingId' => $this->issues['legacyButton']], self::EDITOR);
        self::assertSame(2, $context['finding']['location']['pageUid']);
        self::assertSame(1, $context['finding']['location']['languageUid']);
        self::assertSame(2, $context['source']['pageUid']);
        self::assertSame(0, $context['source']['languageUid'], 'the source record itself is the default-language element');
        self::assertSame(2, $context['navigation']['pageUid']);
        self::assertSame('Cancel local scan', $context['untrusted']['pageTitle']);
        self::assertFalse($context['source']['canEdit'], 'rendered output is never edited through a record');

        $guidance = $this->execute('aqg_get_remediation_guidance', ['findingId' => $this->issues['legacyButton']], self::EDITOR);
        self::assertSame($this->issues['legacyButton'], $guidance['forFinding']['findingId']);

        $german = $this->execute('aqg_find_pages', ['query' => 'Cancel', 'languageUid' => 1], self::EDITOR);
        self::assertSame([2], array_column($german['pages'], 'pageUid'));
        self::assertSame(2, $german['pages'][0]['contentFindings']['open'], 'German rich-text finding + legacy rendered row');
        $english = $this->execute('aqg_find_pages', ['query' => 'Cancel'], self::EDITOR);
        self::assertSame(2, $english['pages'][0]['contentFindings']['open'], 'English: rich text + rendered page finding, no legacy row');
    }

    #[Test]
    public function legacyRowsFollowTheLanguageAndTheTranslationRecordsOwnAccess(): void
    {
        // Without German the row is gone everywhere (it was listed site-wide as "language 0" before).
        self::assertNotContains($this->issues['legacyButton'], $this->ids('aqg_list_content_findings', ['pageSize' => 50], self::ENGLISH_ONLY));
        self::assertNotContains($this->issues['legacyButton'], $this->ids('aqg_list_content_findings', ['pageUid' => 2], self::ENGLISH_ONLY));
        $this->assertRefused('finding_not_found', 'aqg_get_finding_context', ['findingId' => $this->issues['legacyButton']], self::ENGLISH_ONLY);

        // The translation record has its own page permissions: unreadable → its rows are not shown, like its page.
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 20]);
        $this->assertRefused('page_not_found', 'aqg_list_content_findings', ['pageUid' => 2, 'languageUid' => 1], self::NO_GERMAN_PAGE);
        self::assertNotContains($this->issues['legacyButton'], $this->ids('aqg_list_content_findings', ['pageSize' => 50], self::NO_GERMAN_PAGE));
        self::assertNotContains($this->issues['legacyButton'], $this->ids('aqg_list_content_findings', ['pageUid' => 2], self::NO_GERMAN_PAGE));
        $this->assertRefused('finding_not_found', 'aqg_get_finding_context', ['findingId' => $this->issues['legacyButton']], self::NO_GERMAN_PAGE);
        self::assertContains($this->issues['englishRte'], $this->ids('aqg_list_content_findings', ['pageUid' => 2], self::NO_GERMAN_PAGE));
    }

    #[Test]
    public function aRowOnATranslationWhoseDefaultPageIsGoneIsWithheld(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['l10n_parent' => 3000], ['uid' => 20]);
        self::assertNotContains($this->issues['legacyButton'], $this->ids('aqg_list_content_findings', ['pageSize' => 50, 'status' => 'all'], self::ADMIN));
        $this->assertRefused('finding_not_found', 'aqg_get_finding_context', ['findingId' => $this->issues['legacyButton']], self::ADMIN);
    }

    #[Test]
    public function readingNeverRewritesTheStoredRows(): void
    {
        $before = $this->storedRows();
        $this->execute('aqg_list_content_findings', ['status' => 'all', 'pageSize' => 50], self::ADMIN);
        $this->execute('aqg_list_content_findings', ['pageUid' => 2, 'languageUid' => 1, 'status' => 'all'], self::EDITOR);
        $this->execute('aqg_get_finding_context', ['findingId' => $this->issues['legacyButton']], self::EDITOR);
        $this->execute('aqg_get_finding_context', ['findingId' => $this->issues['legacyResolved']], self::ADMIN);
        self::assertSame($before, $this->storedRows());
        self::assertSame([20, 0, 0], array_slice($before[$this->issues['legacyButton']], 0, 3), 'page 20, language 0, open: kept as stored');
    }

    /** @return list<int> */
    private function sorted(int ...$ids): array
    {
        sort($ids);

        return $ids;
    }

    /** @return array<int, list<int>> */
    private function storedRows(): array
    {
        $rows = [];
        foreach ($this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['uid', 'page_uid', 'source_lang_uid', 'status', 'tstamp', 'last_seen_scan_uid'], 'tx_a11y_issue', [], [], ['uid' => 'ASC'])
            ->fetchAllAssociative() as $row) {
            $rows[(int)$row['uid']] = [(int)$row['page_uid'], (int)$row['source_lang_uid'], (int)$row['status'], (int)$row['tstamp'], (int)$row['last_seen_scan_uid']];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<int> finding ids, sorted
     */
    private function ids(string $tool, array $arguments, int $user): array
    {
        $ids = array_column($this->execute($tool, $arguments, $user)['findings'], 'findingId');
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function finding(array $result, int $id): array
    {
        foreach ($result['findings'] as $finding) {
            if ($finding['findingId'] === $id) {
                return $finding;
            }
        }
        self::fail('finding ' . $id . ' not listed');
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function execute(string $tool, array $arguments, int $user): array
    {
        return $this->get(McpToolExecutor::class)->execute($tool, ['siteIdentifier' => 'main'] + $arguments, [
            'tokenId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'tokenSuffix' => 'test',
            'clientName' => 'Test agent',
            'beUserUid' => $user,
            'scopes' => self::SCOPES,
            'siteIdentifiers' => ['main'],
            'expiresAt' => '2026-12-01T00:00:00Z',
        ]);
    }

    /** @param array<string, mixed> $arguments */
    private function assertRefused(string $reason, string $tool, array $arguments, int $user): void
    {
        try {
            $this->execute($tool, $arguments, $user);
            self::fail(sprintf('%s should be refused with %s', $tool, $reason));
        } catch (McpCallRefused $refused) {
            self::assertSame($reason, $refused->reason, $tool);
        }
    }
}
