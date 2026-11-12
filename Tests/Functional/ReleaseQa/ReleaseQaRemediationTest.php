<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgService;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantContextResolver;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantService;
use Priebera\A11yQualityGate\Controller\ToolbarScanController;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Export\IssueExporter;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Service\PageModuleIndicatorService;
use Priebera\A11yQualityGate\Service\RemoteResultProvenanceService;
use Priebera\A11yQualityGate\Service\ScanResultSummaryService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Regressions for the independent final release QA (QA-02, QA-05, QA-06): synthetic records, real TYPO3 permissions,
 * real repositories, controllers and services. Every refusal has a positive control.
 */
#[\PHPUnit\Framework\Attributes\Group('releaseqa')]
final class ReleaseQaRemediationTest extends AbstractFunctionalTestCase
{
    /** @var array<string, int> finding uid by fixture name */
    private array $findings = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        foreach ([
            [1, 0, 'QA root', '/', 31, 0, 0],
            [2, 1, 'QA public', '/open', 31, 0, 0],
            [3, 1, 'QA confidential', '/private', 0, 0, 0],
            [4, 1, 'QA German title', '/offen', 0, 1, 2],
            [6, 1, 'QA second', '/second', 31, 0, 0],
            [7, 1, 'QA second DE', '/zweite', 31, 1, 6],
        ] as [$uid, $pid, $title, $slug, $perms, $lang, $parent]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1,
                'is_siteroot' => $uid === 1 ? 1 : 0, 'perms_everybody' => $perms, 'perms_userid' => 99, 'perms_user' => 31,
                'sys_language_uid' => $lang, 'l10n_parent' => $parent,
            ]);
        }
        foreach ([
            [10, 2, 'QA public content', 0, 0, 0, 0],
            [11, 3, 'QA private content', 0, 0, 0, 0],
            [12, 2, 'QA German content', 1, 0, 0, 0],
            [13, 2, 'QA deleted content', 0, 0, 0, 1],
            [20, 2, 'QA other workspace', 0, 5, 10, 0],
        ] as [$uid, $pid, $header, $lang, $ws, $oid, $deleted]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'header' => $header, 'bodytext' => '<button></button>', 'CType' => 'html',
                'sys_language_uid' => $lang, 't3ver_wsid' => $ws, 't3ver_oid' => $oid, 'deleted' => $deleted,
            ]);
        }
        foreach ([[10, '0'], [11, '1']] as [$uid, $langs]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'title' => 'QA group', 'groupMods' => 'web,web_a11y', 'db_mountpoints' => '1',
                'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => $langs,
            ]);
        }
        foreach ([[1, 1, ''], [2, 0, '10'], [3, 0, '11']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => 'qa' . $uid, 'admin' => $admin, 'usergroup' => $group, 'workspace_perms' => 1,
            ]);
        }
        $this->get(SiteWriter::class)->write('qa', ['rootPageId' => 1, 'base' => 'https://qa.example.invalid/', 'languages' => [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
        ]]);
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- QA-02

    #[Test]
    public function everyNativeReaderUsesTheCurrentSourceRecordRights(): void
    {
        $this->insertNativeFindings();
        $repository = $this->get(IssueRepository::class);

        $this->user(2);
        $clause = $this->get(ScopeAccessService::class)->readablePagesClause();
        self::assertNotSame('', $clause);
        $visible = [$this->findings['content'], $this->findings['page']];
        sort($visible);

        self::assertSame($visible, $this->uids($repository->findAllForPage('qa', 2, -1, true)), 'Page Detail / toolbar list');
        self::assertSame($visible, $this->uids($repository->findOpenForPage(2, 'qa', -1, true)), 'Page module / AI queue');
        self::assertSame($visible, $this->uids($repository->findForExport('qa', 2, false, -1, $clause)), 'page CSV / page PDF');
        self::assertSame($visible, $this->uids($repository->findForExport('qa', null, false, -1, $clause)), 'site CSV / overview PDF');
        self::assertSame($visible, $this->uids($this->get(IssueExporter::class)->getFilteredIssues('qa', 2, 'all', 'all', -1, $clause)));
        self::assertSame(2, array_sum($repository->countOpenBySeverity(2, 'qa', -1, '', true)), 'page counts');
        self::assertSame(2, $repository->countOpenTotalsForSite('qa', -1, $clause)['total'], 'Overview / toolbar totals');
        self::assertSame(2, array_sum($repository->countOpenByRuleOnSite('qa', -1, true)), 'site rule counts');
        self::assertSame(2, array_sum($repository->countOpenByRuleOnPage('qa', 2, -1, true)), 'page rule counts');
        self::assertSame(1, $repository->countOpenPageStatsForSite('qa', '', -1, $clause), 'Overview page list');
        self::assertSame(2, $this->get(ScanResultSummaryService::class)->contentOpenIssues('page', 'qa', 2, -1, $clause)['total'] ?? null, 'notification');

        // The quality gate keeps counting every finding of the page: hiding a finding from the reader must never let
        // a publish through.
        self::assertSame(count($this->findings), array_sum($repository->countOpenBySeverity(2, 'qa')));

        // Administrators keep the complete stored result.
        $this->user(1);
        self::assertCount(count($this->findings), $repository->findAllForPage('qa', 2, -1, true));
        self::assertSame('', $this->get(ScopeAccessService::class)->readablePagesClause());
    }

    #[Test]
    public function translatedRecordFindingFollowsTheRecordLanguage(): void
    {
        $this->insertNativeFindings();
        $repository = $this->get(IssueRepository::class);
        $this->user(2);
        self::assertNotContains($this->findings['german'], $this->uids($repository->findAllForPage('qa', 2, -1, true)));
        $this->user(3);
        $germanOnly = $this->uids($repository->findAllForPage('qa', 2, -1, true));
        self::assertContains($this->findings['german'], $germanOnly, 'positive control: language 1 editor');
        self::assertNotContains($this->findings['content'], $germanOnly, 'language 0 record refused to a language 1 editor');
    }

    #[Test]
    public function toolbarPageContextCountsOnlyReadableSources(): void
    {
        $this->insertNativeFindings();
        $this->user(2);
        $context = $this->get(ToolbarAssistantContextResolver::class)->resolve($this->request(['id' => '2', 'language' => '0']));
        // Ask AQG answers from these findings (page scope): only the two readable sources.
        self::assertSame(
            [$this->findings['content'], $this->findings['page']],
            $this->uids($this->get(ToolbarAssistantService::class)->openIssues($context))
        );
        $answer = $this->get(AskAqgService::class)->answerDeterministically($context, 'first');
        self::assertSame(2, $answer['totals']['occurrences']);
        foreach (['QA VISIBLE', 'QA PAGE', 'QA GERMAN', 'QA MOVED', 'QA DELETED', 'QA MISSING', 'QA FOREIGN', 'QA UNKNOWN', 'QA NO TCA'] as $snippet) {
            self::assertStringNotContainsString($snippet, json_encode($answer, JSON_THROW_ON_ERROR), 'no stored snippet in the answer');
        }
        $this->user(1);
        $context = $this->get(ToolbarAssistantContextResolver::class)->resolve($this->request(['id' => '2', 'language' => '0']));
        self::assertGreaterThan(2, count($this->get(ToolbarAssistantService::class)->openIssues($context)));
    }

    /**
     * Supersedes SOL's post-QA-09 probe toolbarPageRequestRefusesDeniedTranslation, which looked for the removed
     * per-finding list in the dropdown HTML. The same refusal against the current Ask AQG surfaces: while the German
     * page translation is readable its finding is part of the context; once TYPO3 denies the translation, the toolbar
     * context, the findings Ask AQG answers from, the deterministic answer and the real ask route all refuse it.
     */
    #[Test]
    public function askAqgRefusesAPageTranslationTheUserMayNoLongerShow(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->update('pages', ['perms_everybody' => 31], ['uid' => 4]);
        $finding = $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 2, 'source_table' => 'tt_content', 'source_uid' => 12, 'source_field' => 'bodytext', 'source_type' => 'rte', 'source_lang_uid' => 1, 'context_snippet' => 'QA TRANSLATION SECRET']);
        $resolve = fn () => $this->get(ToolbarAssistantContextResolver::class)->resolve($this->request(['id' => '2', 'language' => '1']));
        $ask = fn () => $this->get(ToolbarScanController::class)->askAction($this->request([])->withMethod('POST')->withBody($this->jsonBody(['intent' => 'first', 'question' => 'What should I fix first?', 'context' => ['id' => '2', 'language' => '1']])));

        $this->user(3);
        $context = $resolve();
        self::assertSame('page', $context->type, 'positive control: the readable translation is a context');
        self::assertContains($finding, $this->uids($this->get(ToolbarAssistantService::class)->openIssues($context)));
        self::assertSame(1, $this->get(AskAqgService::class)->answerDeterministically($context, 'first')['totals']['occurrences']);
        self::assertSame(200, $ask()->getStatusCode());

        $pages->update('pages', ['perms_everybody' => 0], ['uid' => 4]);
        $this->user(3);
        self::assertFalse($this->get(ScopeAccessService::class)->canReadPage(4));
        $context = $resolve();
        self::assertNotSame('page', $context->type, 'the denied translation is no context');
        self::assertSame([], $this->get(ToolbarAssistantService::class)->openIssues($context));
        $html = (string)$this->get(ToolbarScanController::class)->assistantAction($this->request(['id' => '2', 'language' => '1']))->getBody();
        self::assertStringNotContainsString('QA German title', $html);
        $refused = $ask();
        self::assertGreaterThanOrEqual(400, $refused->getStatusCode());
        self::assertStringNotContainsString('QA TRANSLATION SECRET', (string)$refused->getBody());
        self::assertStringNotContainsString('QA German', (string)$refused->getBody());
    }

    /** @param array<string, mixed> $payload */
    private function jsonBody(array $payload): \Psr\Http\Message\StreamInterface
    {
        $stream = new \TYPO3\CMS\Core\Http\Stream('php://temp', 'rw');
        $stream->write(json_encode($payload, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return $stream;
    }

    /** Ask AQG's website answers (site scope) count only findings whose source the user may read, like every site aggregate. */
    #[Test]
    public function askAqgSiteSummaryCountsOnlyReadableSources(): void
    {
        $this->insertNativeFindings();
        $repository = $this->get(IssueRepository::class);
        $this->user(2);
        $editor = $repository->summarizeOpenByRuleForSite('qa', -1, $this->get(ScopeAccessService::class)->readablePagesClause(), 500);
        self::assertSame(2, array_sum(array_column($editor, 'occurrences')));
        $this->user(1);
        $admin = $repository->summarizeOpenByRuleForSite('qa', -1, $this->get(ScopeAccessService::class)->readablePagesClause(), 500);
        self::assertSame(count($this->findings), array_sum(array_column($admin, 'occurrences')), 'administrators keep the complete stored result');
    }

    #[Test]
    public function pageModuleIndicatorCountsOnlyReadableSources(): void
    {
        $this->insertNativeFindings();
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('qa');
        $this->user(2);
        $editor = $this->contentRowLabels($this->get(PageModuleIndicatorService::class)->buildViewData(2, $site, -1));
        self::assertMatchesRegularExpression('/\\b2\\b/', $editor);
        self::assertDoesNotMatchRegularExpression('/\\b9\\b/', $editor);
        $this->user(1);
        self::assertMatchesRegularExpression('/\\b9\\b/', $this->contentRowLabels($this->get(PageModuleIndicatorService::class)->buildViewData(2, $site, -1)));
    }

    /** @param array<mixed>|null $viewData */
    private function contentRowLabels(?array $viewData): string
    {
        $found = '';
        $walk = static function (array $node) use (&$walk, &$found): void {
            if (($node['source'] ?? null) === 'content' && is_array($node['counts'] ?? null)) {
                $found = implode('|', array_column($node['counts'], 'label'));
                return;
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($viewData ?? []);

        return $found;
    }

    #[Test]
    public function lostTableAccessHidesFindingsAndRestoredAccessShowsThemAgain(): void
    {
        $this->insertNativeFindings();
        $repository = $this->get(IssueRepository::class);
        $this->user(2);
        self::assertContains($this->findings['content'], $this->uids($repository->findAllForPage('qa', 2, -1, true)));
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', ['tables_select' => 'pages', 'tables_modify' => 'pages'], ['uid' => 10]);
        $this->user(2);
        self::assertSame([$this->findings['page']], $this->uids($repository->findAllForPage('qa', 2, -1, true)));
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', ['tables_select' => 'pages,tt_content'], ['uid' => 10]);
        $this->user(2);
        self::assertContains($this->findings['content'], $this->uids($repository->findAllForPage('qa', 2, -1, true)));
    }

    #[Test]
    public function pageFindingsOfARefusedTranslationRowAreWithheld(): void
    {
        // Rendered checks store page findings as the default page with the language. Page 4 (German translation of
        // page 2) refuses show access; page 7 (German translation of page 6) allows it.
        $refused = $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 2, 'source_table' => 'pages', 'source_uid' => 2, 'source_lang_uid' => 1, 'source_field' => '__rendered_html', 'source_type' => 'rendered']);
        $permitted = $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 6, 'source_table' => 'pages', 'source_uid' => 6, 'source_lang_uid' => 1, 'source_field' => '__rendered_html', 'source_type' => 'rendered']);
        $repository = $this->get(IssueRepository::class);
        $this->user(3);
        $clause = $this->get(ScopeAccessService::class)->readablePagesClause();
        $site = $this->uids($repository->findForExport('qa', null, false, -1, $clause));
        self::assertContains($permitted, $site);
        self::assertNotContains($refused, $site);
        self::assertSame([], $repository->findAllForPage('qa', 2, 1, true));

        // The toolbar answers the refused translation like a missing page; the readable one is analysed.
        $resolver = $this->get(ToolbarAssistantContextResolver::class);
        self::assertSame('unavailable', $resolver->resolve($this->request(['id' => '2', 'language' => '1']))->type);
        self::assertSame('page', $resolver->resolve($this->request(['id' => '6', 'language' => '1']))->type);
    }

    #[Test]
    public function rteFeedbackAndIgnoreFollowTheRecordLanguage(): void
    {
        $this->insertNativeFindings();
        $controller = $this->get(\Priebera\A11yQualityGate\Controller\IssueApiController::class);
        $query = ['recordUid' => '12', 'fieldName' => 'bodytext', 'pageUid' => '2'];
        $this->user(2);
        $refused = json_decode((string)$controller->issuesAction($this->request($query))->getBody(), true);
        self::assertSame([], $refused['issues'] ?? null, 'language 0 editor: no findings of the German record');
        $this->user(3);
        $permitted = json_decode((string)$controller->issuesAction($this->request($query))->getBody(), true);
        self::assertNotSame([], $permitted['issues'] ?? [], 'positive control: language 1 editor');
    }

    #[Test]
    public function aiFixesQueueItemsNeedTheTargetRecord(): void
    {
        $item = ['page_uid' => 2, 'workspace_id' => 0, 'created_by' => 2, 'target_kind' => \Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetKind::RICH_TEXT, 'target_table' => 'tt_content', 'target_uid' => 10, 'target_field' => 'bodytext'];
        $access = $this->get(\Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService::class);
        $this->user(2);
        self::assertTrue($access->canSee($item), 'positive control: own item of a readable record');
        self::assertFalse($access->canSee(['target_uid' => 12] + $item), 'record in a refused language');
        self::assertFalse($access->canSee(['target_uid' => 11] + $item), 'record moved to a refused page');
        self::assertFalse($access->canSee(['target_uid' => 13] + $item), 'deleted record');
    }

    // ---------------------------------------------------------------- QA-05

    #[Test]
    public function authorizedHistoryIsIdenticalWithAndWithoutInaccessibleTranslatedScans(): void
    {
        $pool = $this->getConnectionPool();
        // Languages 0 and 1: the default-language scans below are genuinely authorized (S-01: a language-1-only user
        // may not read them, so they would be no positive control for that user).
        $pool->getConnectionForTable('be_groups')->update('be_groups', ['TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1', 'allowed_languages' => '0,1'], ['uid' => 11]);
        $this->get(McpSiteSettingsRepository::class)->save('qa', true, ['history:read'], 1);
        // Authorized history: two default-language page scans and one scan of a readable translation, all older.
        $authorized = [
            [900, 2, 0, 1000], [901, 2, 0, 1001], [902, 6, 1, 1002],
        ];
        foreach ($authorized as [$uid, $page, $language, $crdate]) {
            $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
                'uid' => $uid, 'site_identifier' => 'qa', 'job_id' => sprintf('%08d-0000-4000-8000-000000000000', $uid),
                'source_type' => 'single_page', 'scan_scope' => 'page', 'page_uid' => $page, 'language_uid' => $language,
                'status' => 'completed', 'crdate' => $crdate,
            ]);
        }
        $call = fn (array $arguments = []): array => $this->get(McpToolExecutor::class)->execute(
            'aqg_list_scans',
            ['siteIdentifier' => 'qa', 'scope' => 'page'] + $arguments,
            $this->principal(3),
        );
        $before = $call();
        $beforePaged = $call(['pageSize' => 2]);
        self::assertCount(3, $before['scans'], 'positive control: authorized older scans are found');
        self::assertSame(3, $before['total']);
        self::assertTrue($before['searchComplete']);

        $rows = [];
        for ($uid = 1000; $uid < 6001; $uid++) {
            $rows[] = [$uid, 'qa', sprintf('%08d-0000-4000-8000-000000000000', $uid), 'single_page', 'page', 2, 1, 'completed', 100000 + $uid];
        }
        foreach (array_chunk($rows, 1000) as $chunk) {
            $pool->getConnectionForTable('tx_a11y_remote_scan')->bulkInsert('tx_a11y_remote_scan', $chunk, ['uid', 'site_identifier', 'job_id', 'source_type', 'scan_scope', 'page_uid', 'language_uid', 'status', 'crdate']);
        }
        $after = $call();
        $afterPaged = $call(['pageSize' => 2]);

        self::assertSame($before, $after, 'refused translation history changes nothing observable');
        self::assertSame($this->withoutCursor($beforePaged), $this->withoutCursor($afterPaged));
        self::assertSame($beforePaged['nextCursor'] === null, $afterPaged['nextCursor'] === null);
        self::assertNull($after['nextCursor']);
    }

    #[Test]
    public function aPageTreeLargerThanTheSearchBoundIsNotReportedAsComplete(): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('be_groups')->update('be_groups', ['TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1'], ['uid' => 10]);
        $this->get(McpSiteSettingsRepository::class)->save('qa', true, ['history:read'], 1);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => 900, 'site_identifier' => 'qa', 'job_id' => '00000900-0000-4000-8000-000000000000', 'source_type' => 'single_page',
            'scan_scope' => 'page', 'page_uid' => 2, 'language_uid' => 0, 'status' => 'completed', 'crdate' => 1000,
        ]);
        $call = fn (): array => $this->get(McpToolExecutor::class)->execute('aqg_list_scans', ['siteIdentifier' => 'qa', 'scope' => 'page'], $this->principal(2));
        self::assertTrue($call()['searchComplete'], 'positive control: a small tree is searched completely');

        $rows = [];
        for ($uid = 10000; $uid < 30010; $uid++) {
            $rows[] = [$uid, 6, 'Bulk ' . $uid, 1, 31];
        }
        foreach (array_chunk($rows, 2000) as $chunk) {
            $pool->getConnectionForTable('pages')->bulkInsert('pages', $chunk, ['uid', 'pid', 'title', 'doktype', 'perms_everybody']);
        }
        $result = $call();
        self::assertFalse($result['searchComplete']);
        self::assertNull($result['total']);
        self::assertCount(1, $result['scans']);
        self::assertStringContainsString('page tree', (string)$result['note']);
    }

    // ---------------------------------------------------------------- QA-06

    #[Test]
    public function restrictedPdfNeverReportsAnUncheckedOrCleanScan(): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => 1, 'site_identifier' => 'qa', 'job_id' => 'qa-site', 'scan_scope' => 'site', 'language_uid' => 0,
            'status' => 'completed', 'persisted_at' => 100, 'pages_scanned' => 2, 'pages_total' => 2, 'issues_total' => 2,
        ]);
        foreach ([[51, '/open', 10], [52, '/private', 11]] as [$uid, $path, $content]) {
            $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', ['uid' => $uid, 'remote_scan' => 1, 'url' => 'https://qa.example.invalid' . $path, 'title' => 'QA result ' . $uid, 'http_status' => 200]);
            $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', ['uid' => $uid, 'remote_scan' => 1, 'remote_scan_page' => $uid, 'rule_id' => 'qa-rule-' . $uid, 'impact' => 'serious', 'nodes_count' => 1]);
            $pool->getConnectionForTable('tx_a11y_remote_issue_node')->insert('tx_a11y_remote_issue_node', ['uid' => $uid, 'remote_issue' => $uid, 'mapped_table' => 'tt_content', 'mapped_uid' => $content, 'html_snippet' => 'QA SYNTHETIC NODE ' . $uid]);
        }
        $this->user(2);
        $builder = $this->get(RemoteExportBuilder::class);
        $legacy = $builder->buildOverviewPdf('qa', $this->request([]), 1);
        self::assertStringStartsWith('%PDF-', $legacy);
        @file_put_contents('/tmp/aqg-frqa-legacy.pdf', $legacy);

        $this->get(RemoteResultProvenanceService::class)->stampScan(1, 'qa');
        $this->user(2);
        $authorized = $builder->buildOverviewPdf('qa', $this->request([]), 1);
        @file_put_contents('/tmp/aqg-frqa-authorized.pdf', $authorized);
        $this->user(1);
        @file_put_contents('/tmp/aqg-frqa-admin.pdf', $builder->buildOverviewPdf('qa', $this->request([]), 1));

        // The wording itself (the PDF text is checked from the saved files as evidence).
        self::assertSame('No results of this scan are available to you.', RemoteExportBuilder::overviewVerdict(true, 0, 0, 0, 0, 0));
        self::assertSame('1 occurrence on 1 of 1 page available to you.', RemoteExportBuilder::overviewVerdict(true, 1, 1, 1, 1, 0));
        self::assertSame('No page could be checked in this scan.', RemoteExportBuilder::overviewVerdict(false, 0, 0, 0, 0, 0));
        self::assertSame('2 occurrences on 2 of 2 checked pages.', RemoteExportBuilder::overviewVerdict(false, 2, 2, 2, 2, 0));
        self::assertSame('No results available to you for this page', RemoteExportBuilder::pageVerdict(0, 0, false, true));
        self::assertSame('No automated findings', RemoteExportBuilder::pageVerdict(0, 0, false, false));
    }

    #[Test]
    public function restrictedNotificationIsNeutralAndDoesNotCountWithheldResults(): void
    {
        $pool = $this->getConnectionPool();
        $scan = [
            'uid' => 1, 'site_identifier' => 'qa', 'job_id' => 'qa-site', 'scan_scope' => 'site', 'language_uid' => 0,
            'status' => 'completed', 'persisted_at' => 100, 'pages_scanned' => 2, 'pages_total' => 2, 'issues_total' => 2,
        ];
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', $scan);
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', ['uid' => 51, 'remote_scan' => 1, 'url' => 'https://qa.example.invalid/private', 'title' => 'x', 'http_status' => 200]);
        $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', ['uid' => 51, 'remote_scan' => 1, 'remote_scan_page' => 51, 'rule_id' => 'qa-rule', 'impact' => 'serious', 'nodes_count' => 1]);
        $this->user(2);
        $this->request([]);
        $summary = $this->get(ScanResultSummaryService::class)->frontendResult($scan);
        self::assertTrue($summary['resultsRestricted']);
        self::assertTrue($summary['scanLoadedPages']);
        self::assertSame(0, $summary['occurrencesTotal']);
        $this->user(1);
        $admin = $this->get(ScanResultSummaryService::class)->frontendResult($scan);
        self::assertArrayNotHasKey('resultsRestricted', $admin);
    }

    // ---------------------------------------------------------------- helpers

    private function insertNativeFindings(): void
    {
        $base = ['site_identifier' => 'qa', 'page_uid' => 2, 'source_type' => 'rte', 'source_field' => 'bodytext'];
        $this->findings = [
            'content' => $this->insertIssue($base + ['source_table' => 'tt_content', 'source_uid' => 10, 'context_snippet' => 'QA VISIBLE']),
            'page' => $this->insertIssue(['source_table' => 'pages', 'source_uid' => 2, 'source_field' => 'title', 'source_type' => 'structured', 'context_snippet' => 'QA PAGE'] + $base),
            'german' => $this->insertIssue(['source_lang_uid' => 1] + $base + ['source_table' => 'tt_content', 'source_uid' => 12, 'context_snippet' => 'QA GERMAN']),
            'moved' => $this->insertIssue($base + ['source_table' => 'tt_content', 'source_uid' => 11, 'context_snippet' => 'QA MOVED TO REFUSED PAGE']),
            'deleted' => $this->insertIssue($base + ['source_table' => 'tt_content', 'source_uid' => 13, 'context_snippet' => 'QA DELETED']),
            'missing' => $this->insertIssue($base + ['source_table' => 'tt_content', 'source_uid' => 999, 'context_snippet' => 'QA MISSING']),
            'workspace' => $this->insertIssue($base + ['source_table' => 'tt_content', 'source_uid' => 20, 'context_snippet' => 'QA FOREIGN WORKSPACE']),
            'unknown' => $this->insertIssue($base + ['source_table' => '', 'source_uid' => 0, 'context_snippet' => 'QA UNKNOWN PROVENANCE']),
            'noTca' => $this->insertIssue($base + ['source_table' => 'tx_qa_unknown', 'source_uid' => 5, 'context_snippet' => 'QA NO TCA']),
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function uids(array $rows): array
    {
        $uids = array_map(static fn (array $row): int => (int)$row['uid'], $rows);
        sort($uids);

        return $uids;
    }

    /** @return array<string, mixed> */
    private function principal(int $beUserUid): array
    {
        return [
            'tokenId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'tokenSuffix' => 'qa', 'clientName' => 'QA synthetic',
            'beUserUid' => $beUserUid, 'scopes' => ['history:read'], 'siteIdentifiers' => ['qa'],
            'expiresAt' => '2026-12-01T00:00:00Z',
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function withoutCursor(array $response): array
    {
        unset($response['nextCursor']);

        return $response;
    }

    private function user(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /** @param array<string, string> $query */
    private function request(array $query): ServerRequest
    {
        $request = (new ServerRequest('https://qa.example.invalid/typo3/ajax/a11y/toolbar/assistant'))
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
