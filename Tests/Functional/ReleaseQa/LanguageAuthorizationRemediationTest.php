<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ExportController;
use Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Mcp\McpCallRefused;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Service\LocalFindingSourceAccessService;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\RemoteResultProvenanceService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * S-01 / S-02 (independent post-QA-09 security re-audit): AQG's read language policy applies to every concrete
 * language, the default language 0 included, and a translated page source row is decided by its CURRENT language,
 * never by a finding's stored language. The refusal cases are SOL's reproductions (same fixture, same consumers);
 * every refusal has a positive control.
 *
 * Users: 1 administrator; 2 = language 0 only; 3 = language 1 only; 4 = languages 0 and 1.
 */
final class LanguageAuthorizationRemediationTest extends AbstractFunctionalTestCase
{
    private const JOB_DEFAULT = '11111111-1111-4111-8111-111111111111';
    private const JOB_TRANSLATED_PAGE = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        foreach ([
            [1, 0, 'QA root', '/', 31, 0, 0],
            [2, 1, 'QA public', '/open', 31, 0, 0],
            [3, 1, 'QA confidential', '/private', 0, 0, 0],
            [4, 1, 'QA German title', '/offen', 31, 1, 2],
        ] as [$uid, $pid, $title, $slug, $perms, $lang, $parent]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1,
                'is_siteroot' => $uid === 1 ? 1 : 0, 'perms_everybody' => $perms, 'perms_userid' => 99, 'perms_user' => 31,
                'sys_language_uid' => $lang, 'l10n_parent' => $parent,
            ]);
        }
        foreach ([[10, 2, 'QA public content', 0], [11, 3, 'QA private content', 0], [12, 2, 'QA German content', 1]] as [$uid, $pid, $header, $lang]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'header' => $header, 'bodytext' => '<button></button>', 'CType' => 'html',
                'sys_language_uid' => $lang,
            ]);
        }
        foreach ([[10, '0'], [11, '1'], [12, '0,1']] as [$uid, $langs]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'title' => 'QA group ' . $langs, 'groupMods' => 'web,web_a11y', 'db_mountpoints' => '1',
                'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => $langs,
                'TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1',
            ]);
        }
        foreach ([[1, 1, ''], [2, 0, '10'], [3, 0, '11'], [4, 0, '12']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => 'qa' . $uid, 'admin' => $admin, 'usergroup' => $group, 'workspace_perms' => 1,
                'TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1',
            ]);
        }
        $this->get(SiteWriter::class)->write('qa', ['rootPageId' => 1, 'base' => 'https://qa.example.invalid/', 'languages' => [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
        ]]);
        $this->get(SiteFinder::class)->getAllSites(false);

        // SOL's default-language site scan: a public (51) and a confidential (52) result page, each with a mapped node.
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => 1, 'site_identifier' => 'qa', 'job_id' => self::JOB_DEFAULT, 'scan_scope' => 'site', 'language_uid' => 0,
            'status' => 'completed', 'persisted_at' => 100, 'pages_scanned' => 2, 'issues_total' => 2, 'crdate' => 1000,
            'start_url' => 'https://qa.example.invalid/default-private',
        ]);
        foreach ([[51, '/open', 10], [52, '/private', 11]] as [$uid, $path, $content]) {
            $this->remoteResult(1, $uid, 'https://qa.example.invalid' . $path, $content);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    // ---- S-01: remote results -------------------------------------------------------------------------------------

    #[Test]
    public function remoteCsvRejectsLanguageZeroForTranslationOnlyUser(): void
    {
        $this->get(RemoteResultProvenanceService::class)->stampScan(1, 'qa');
        $builder = $this->get(RemoteExportBuilder::class);
        $this->user(2);
        self::assertStringContainsString('QA SYNTHETIC NODE 51', $builder->buildOverviewCsv('qa', 1), 'Language-0 user: positive control.');
        $this->user(4);
        self::assertStringContainsString('QA SYNTHETIC NODE 51', $builder->buildOverviewCsv('qa', 1), 'Languages 0+1: positive control.');
        $this->user(1);
        self::assertStringContainsString('QA SYNTHETIC NODE 52', $builder->buildOverviewCsv('qa', 1), 'Administrator reads everything.');
        $this->user(3);
        self::assertFalse($GLOBALS['BE_USER']->checkLanguageAccess(0));
        $denied = $builder->buildOverviewCsv('qa', 1);
        self::assertStringNotContainsString('QA SYNTHETIC NODE 51', $denied);
        self::assertStringNotContainsString('qa-rule-51', $denied);
        self::assertStringNotContainsString('/open', $denied);
    }

    #[Test]
    public function mcpResultsRejectLanguageZeroForTranslationOnlyUser(): void
    {
        $this->get(RemoteResultProvenanceService::class)->stampScan(1, 'qa');
        $this->get(McpSiteSettingsRepository::class)->save('qa', true, ['findings:read'], 1);
        $exec = $this->get(McpToolExecutor::class);
        $args = ['siteIdentifier' => 'qa', 'jobId' => self::JOB_DEFAULT];
        self::assertStringContainsString('QA SYNTHETIC NODE 51', (string)json_encode($exec->execute('aqg_get_scan_results', $args, $this->principal(2, ['findings:read']))));
        self::assertStringContainsString('QA SYNTHETIC NODE 51', (string)json_encode($exec->execute('aqg_get_scan_results', $args, $this->principal(4, ['findings:read']))));

        // The language-1-only user may not learn that the default-language scan exists: the same answer as an unknown job.
        $denied = $this->refusal(fn () => $exec->execute('aqg_get_scan_results', $args, $this->principal(3, ['findings:read'])));
        $unknown = $this->refusal(fn () => $exec->execute('aqg_get_scan_results', ['siteIdentifier' => 'qa', 'jobId' => '99999999-9999-4999-8999-999999999999'], $this->principal(3, ['findings:read'])));
        self::assertSame('scan_not_found', $denied->reason);
        self::assertSame([$unknown->reason, $unknown->getMessage()], [$denied->reason, $denied->getMessage()]);
    }

    #[Test]
    public function allLanguageCrawlShowsOnlyResultPagesInTheUsersLanguages(): void
    {
        // -1 = an all-language crawl: readable by site readers, every result page decided by its owner's language.
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['language_uid' => -1], ['uid' => 1]);
        $this->remoteResult(1, 53, 'https://qa.example.invalid/de/offen', 12);
        $this->get(RemoteResultProvenanceService::class)->stampScan(1, 'qa');
        $this->get(McpSiteSettingsRepository::class)->save('qa', true, ['findings:read'], 1);
        $exec = $this->get(McpToolExecutor::class);
        $args = ['siteIdentifier' => 'qa', 'jobId' => self::JOB_DEFAULT];

        $translationOnly = (string)json_encode($exec->execute('aqg_get_scan_results', $args, $this->principal(3, ['findings:read'])));
        self::assertStringContainsString('QA SYNTHETIC NODE 53', $translationOnly, 'Permitted language-1 result stays.');
        self::assertStringNotContainsString('QA SYNTHETIC NODE 51', $translationOnly);
        self::assertStringNotContainsString('qa-rule-51', $translationOnly);

        $defaultOnly = (string)json_encode($exec->execute('aqg_get_scan_results', $args, $this->principal(2, ['findings:read'])));
        self::assertStringContainsString('QA SYNTHETIC NODE 51', $defaultOnly);
        self::assertStringNotContainsString('QA SYNTHETIC NODE 53', $defaultOnly);

        $this->user(3);
        self::assertSame(['qa-rule-53'], array_column($this->get(RemoteResultAuthorizationService::class)->authorize(['uid' => 1])->findings, 'ruleId'));
        $this->user(4);
        self::assertSame(['qa-rule-51', 'qa-rule-53'], $this->sorted(array_column($this->get(RemoteResultAuthorizationService::class)->authorize(['uid' => 1])->findings, 'ruleId')));
        $this->user(1);
        self::assertSame(['qa-rule-51', 'qa-rule-52', 'qa-rule-53'], $this->sorted(array_column($this->get(RemoteResultAuthorizationService::class)->authorize(['uid' => 1])->findings, 'ruleId')));
    }

    #[Test]
    public function mappedDefaultLanguageRecordIsDecidedByItsTranslationOnATranslatedPage(): void
    {
        // TYPO3's overlay renders a connected translation under the default uid, so the crawler maps nodes of a
        // language-1 page to default rows: 10 has a live translation (16) and is decided by it; 15 has none (fallback
        // content), so the default row itself was rendered and needs language 0.
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 16, 'pid' => 2, 'header' => 'QA connected DE', 'bodytext' => '<button></button>', 'CType' => 'html', 'sys_language_uid' => 1, 'l18n_parent' => 10]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 15, 'pid' => 2, 'header' => 'QA fallback EN', 'bodytext' => '<button></button>', 'CType' => 'html', 'sys_language_uid' => 0]);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['language_uid' => 1], ['uid' => 1]);
        $this->remoteResult(1, 54, 'https://qa.example.invalid/de/offen', 10);
        $this->remoteResult(1, 55, 'https://qa.example.invalid/de/offen', 15);
        $this->get(RemoteResultProvenanceService::class)->stampScan(1, 'qa');
        $rules = fn (): array => $this->sorted(array_column($this->get(RemoteResultAuthorizationService::class)->authorize(['uid' => 1])->findings, 'ruleId'));

        $this->user(3);
        self::assertSame(['qa-rule-54'], $rules(), 'Connected translation readable; untranslated default record withheld.');
        $this->user(4);
        self::assertSame(['qa-rule-51', 'qa-rule-54', 'qa-rule-55'], $rules());
        $this->user(2);
        self::assertSame(['qa-rule-51'], $rules(), 'Language-0 user: the language-1 result pages stay withheld.');

        // The translation decides: deleted, it no longer stands in; the default row (language 0) then decides.
        $pool->getConnectionForTable('tt_content')->update('tt_content', ['deleted' => 1], ['uid' => 16]);
        $this->user(3);
        self::assertSame([], $rules());
        $this->user(1);
        self::assertSame(['qa-rule-51', 'qa-rule-52', 'qa-rule-54', 'qa-rule-55'], $rules());
    }

    #[Test]
    public function scanAdmissionAppliesTheLanguagePolicy(): void
    {
        $scope = $this->get(ScopeAccessService::class);
        $this->user(3);
        self::assertFalse($scope->canUseLanguage(0));
        self::assertTrue($scope->canUseLanguage(1));
        self::assertTrue($scope->canUseLanguage(-1), 'The all-language sentinel is not a concrete language.');
        self::assertFalse($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'site', 'language_uid' => 0]));
        self::assertTrue($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'site', 'language_uid' => 1]));
        self::assertTrue($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'site', 'language_uid' => -1]));
        self::assertFalse($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'page', 'page_uid' => 2, 'language_uid' => 0]));
        self::assertTrue($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'page', 'page_uid' => 2, 'language_uid' => 1]));
        self::assertFalse($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'page', 'page_uid' => 2, 'language_uid' => -1]), 'A legacy page scan of unknown language fails closed for a restricted user.');
        $this->user(2);
        self::assertTrue($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'page', 'page_uid' => 2, 'language_uid' => 0]));
        self::assertFalse($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'page', 'page_uid' => 2, 'language_uid' => 1]));
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', ['allowed_languages' => ''], ['uid' => 10]);
        $this->user(2);
        self::assertTrue($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'page', 'page_uid' => 2, 'language_uid' => -1]), 'No language restriction: legacy rows stay readable.');
        $this->user(1);
        self::assertTrue($scope->canReadRemoteScan(['site_identifier' => 'qa', 'scan_scope' => 'page', 'page_uid' => 2, 'language_uid' => -1]));

        // The actual page-scan route refuses a forbidden language before anything is submitted. (A language-1-only
        // editor cannot edit the default page record natively, so the language-0 editor asks for language 1 here.)
        $controller = $this->get(ProCrawlerAjaxController::class);
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', ['allowed_languages' => '0'], ['uid' => 10]);
        $this->user(2);
        $refused = $controller->submitPageAction($this->request([])->withMethod('POST')->withParsedBody(['pageUid' => 2, 'languageUid' => 1]));
        self::assertSame(403, $refused->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Access denied'], json_decode((string)$refused->getBody(), true));
        $this->user(4);
        $permitted = $controller->submitPageAction($this->request([])->withMethod('POST')->withParsedBody(['pageUid' => 2, 'languageUid' => 1]));
        // Admitted: the request reaches the licence check, which this fixture has no licence for.
        self::assertSame('pro_crawler_required', json_decode((string)$permitted->getBody(), true)['code'] ?? null, 'Languages 0+1 pass the admission check.');
        $this->user(2);
        $default = $controller->submitPageAction($this->request([])->withMethod('POST')->withParsedBody(['pageUid' => 2, 'languageUid' => 0]));
        self::assertSame('pro_crawler_required', json_decode((string)$default->getBody(), true)['code'] ?? null, 'The language-0 editor may scan the default language.');
    }

    // ---- S-01: MCP history ---------------------------------------------------------------------------------------

    #[Test]
    public function mcpHistoryRejectsDefaultLanguageForTranslationOnlyUser(): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => 2, 'site_identifier' => 'qa', 'job_id' => self::JOB_TRANSLATED_PAGE, 'scan_scope' => 'page', 'page_uid' => 2,
            'language_uid' => 1, 'status' => 'completed', 'crdate' => 2000,
        ]);
        $this->get(McpSiteSettingsRepository::class)->save('qa', true, ['history:read'], 1);
        $exec = $this->get(McpToolExecutor::class);
        $args = ['siteIdentifier' => 'qa'];

        self::assertSame([self::JOB_DEFAULT], array_column($exec->execute('aqg_list_scans', $args, $this->principal(2, ['history:read']))['scans'], 'jobId'));
        self::assertSame([self::JOB_TRANSLATED_PAGE, self::JOB_DEFAULT], array_column($exec->execute('aqg_list_scans', $args, $this->principal(4, ['history:read']))['scans'], 'jobId'));
        self::assertSame([self::JOB_TRANSLATED_PAGE, self::JOB_DEFAULT], array_column($exec->execute('aqg_list_scans', $args, $this->principal(1, ['history:read']))['scans'], 'jobId'));

        $translated = $exec->execute('aqg_list_scans', $args, $this->principal(3, ['history:read']));
        self::assertSame([self::JOB_TRANSLATED_PAGE], array_column($translated['scans'], 'jobId'));
        self::assertSame(1, $translated['total']);
        self::assertTrue($translated['searchComplete']);
        self::assertStringNotContainsString('default-private', (string)json_encode($translated));
    }

    /**
     * The complete authorized answer (all pages, ordering, total, hasMore, searchComplete, cursor) is identical before
     * and after more inaccessible language-0 scans than the whole search budget (10 × 500) are stored, newer and
     * interleaved — they are excluded by the candidate query, not only after it.
     */
    #[Test]
    public function mcpHistoryAnswerIsUnchangedByInaccessibleDefaultLanguageScans(): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('tx_a11y_remote_scan')->delete('tx_a11y_remote_scan', ['uid' => 1]);
        $permitted = [
            [10, 'site', 0, 1, 1100], [11, 'site', 0, -1, 1200], [12, 'page', 2, 1, 1300], [13, 'page', 2, 1, 1400], [14, 'site', 0, 1, 1500],
        ];
        foreach ($permitted as [$uid, $scope, $page, $lang, $crdate]) {
            $this->historyScan($uid, $scope, $page, $lang, $crdate);
        }
        $this->get(McpSiteSettingsRepository::class)->save('qa', true, ['history:read'], 1);
        $before = $this->completeHistory(3);
        self::assertSame(5, $before['pages'][0]['total']);
        self::assertSame(
            ['00000000-0000-4000-8000-000000000014', '00000000-0000-4000-8000-000000000013', '00000000-0000-4000-8000-000000000012', '00000000-0000-4000-8000-000000000011', '00000000-0000-4000-8000-000000000010'],
            $before['jobs']
        );

        // 5,100 forbidden default-language scans: site and page scans, newer than and between the permitted ones.
        $rows = [];
        for ($i = 0; $i < 5100; $i++) {
            $rows[] = [
                'uid' => 1000 + $i, 'pid' => 0, 'site_identifier' => 'qa', 'job_id' => sprintf('d%07d-0000-4000-8000-000000000000', $i),
                'scan_scope' => $i % 2 === 0 ? 'site' : 'page', 'page_uid' => $i % 2 === 0 ? 0 : 2, 'language_uid' => 0,
                'status' => 'completed', 'crdate' => $i % 3 === 0 ? 1250 : 9000 + $i, 'start_url' => 'https://qa.example.invalid/default-private',
            ];
        }
        $connection = $pool->getConnectionForTable('tx_a11y_remote_scan');
        foreach (array_chunk($rows, 500) as $chunk) {
            $connection->bulkInsert('tx_a11y_remote_scan', $chunk, array_keys($chunk[0]));
        }

        self::assertSame($before, $this->completeHistory(3), 'Authorized history must be identical.');
        // Positive controls: the default-language user sees the new scans (bounded search, incomplete, with cursor).
        $defaultUser = $this->get(McpToolExecutor::class)->execute('aqg_list_scans', ['siteIdentifier' => 'qa', 'pageSize' => 50], $this->principal(2, ['history:read']));
        self::assertFalse($defaultUser['searchComplete']);
        self::assertNull($defaultUser['total']);
        self::assertIsString($defaultUser['nextCursor']);
        $admin = $this->get(McpToolExecutor::class)->execute('aqg_list_scans', ['siteIdentifier' => 'qa', 'pageSize' => 50], $this->principal(1, ['history:read']));
        self::assertSame(50, count($admin['scans']));
        self::assertTrue($admin['hasMore']);
    }

    // ---- S-02: native page findings ------------------------------------------------------------------------------

    #[Test]
    public function localPageRecordLanguageCannotBeReplacedByStaleFindingLanguage(): void
    {
        $this->user(2);
        $ctrl = $this->get(ExportController::class);
        $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 2, 'source_table' => 'pages', 'source_uid' => 2, 'source_field' => 'title', 'source_type' => 'structured', 'source_lang_uid' => 0, 'context_snippet' => 'POSTQA PAGE RECORD LANGUAGE SECRET']);
        self::assertStringContainsString('POSTQA PAGE RECORD LANGUAGE SECRET', $this->contentCsv($ctrl));
        // Historical finding still says 0 after the page record is converted into a translation.
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['sys_language_uid' => 1, 'l10n_parent' => 1], ['uid' => 2]);
        self::assertFalse($GLOBALS['BE_USER']->checkLanguageAccess(1));
        self::assertStringNotContainsString('POSTQA PAGE RECORD LANGUAGE SECRET', $this->contentCsv($ctrl));
    }

    #[Test]
    public function staleFindingLanguageOnATranslatedPageRowIsWithheldEvenWhenBothLanguagesAreAllowed(): void
    {
        $uid = $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 2, 'source_table' => 'pages', 'source_uid' => 4, 'source_field' => 'title', 'source_type' => 'structured', 'source_lang_uid' => 0, 'context_snippet' => 'POSTQA INCONSISTENT PAGE SECRET']);
        $issue = $this->findByUid('tx_a11y_issue', $uid);
        $this->user(4);
        $access = $this->get(LocalFindingSourceAccessService::class);
        self::assertFalse($access->canReadFinding($issue), 'Inconsistent provenance fails closed.');
        self::assertSame([], $access->filter([$issue]));
        self::assertStringNotContainsString('POSTQA INCONSISTENT PAGE SECRET', $this->contentCsv($this->get(ExportController::class)));
        self::assertSame(0, $this->openCount(2));
        $this->user(1);
        self::assertTrue($this->get(LocalFindingSourceAccessService::class)->canReadFinding($issue), 'Administrators are unrestricted.');
    }

    #[Test]
    public function legitimatePageFindingsKeepTheirAccess(): void
    {
        // (1) default page row rendered in language 1; (2) translated page row in its own language 1; (3) default page in 0.
        $rendered = $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 2, 'source_table' => 'pages', 'source_uid' => 2, 'source_field' => '__rendered_html', 'source_type' => 'rendered', 'source_lang_uid' => 1, 'context_snippet' => 'POSTQA RENDERED DE']);
        $translated = $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 2, 'source_table' => 'pages', 'source_uid' => 4, 'source_field' => 'title', 'source_type' => 'structured', 'source_lang_uid' => 1, 'context_snippet' => 'POSTQA TRANSLATED ROW DE']);
        $default = $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 2, 'source_table' => 'pages', 'source_uid' => 2, 'source_field' => 'title', 'source_type' => 'structured', 'source_lang_uid' => 0, 'context_snippet' => 'POSTQA DEFAULT EN']);
        $rows = array_map(fn (int $uid): array => (array)$this->findByUid('tx_a11y_issue', $uid), [$rendered, $translated, $default]);

        $this->user(3);
        self::assertSame([$rendered, $translated], array_column($this->get(LocalFindingSourceAccessService::class)->filter($rows), 'uid'));
        $this->user(2);
        self::assertSame([$default], array_column($this->get(LocalFindingSourceAccessService::class)->filter($rows), 'uid'));
        $this->user(4);
        self::assertSame([$rendered, $translated, $default], array_column($this->get(LocalFindingSourceAccessService::class)->filter($rows), 'uid'));
        self::assertSame(3, $this->openCount(2));
        $this->user(1);
        self::assertSame(3, $this->openCount(2));

        // The live translation of the default page decides for the rendered-language finding (own page permissions).
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 4]);
        $this->user(4);
        self::assertSame([$default], array_column($this->get(LocalFindingSourceAccessService::class)->filter($rows), 'uid'));
    }

    #[Test]
    public function aRecordFieldFindingOfADefaultPageRowStoredWithAnotherLanguageIsWithheld(): void
    {
        // Reverse of S-02: page 4 was a translation (title finding stored in language 1), then became a default page.
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['sys_language_uid' => 0, 'l10n_parent' => 0], ['uid' => 4]);
        $stale = $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 4, 'source_table' => 'pages', 'source_uid' => 4, 'source_field' => 'title', 'source_type' => 'structured', 'source_lang_uid' => 1, 'context_snippet' => 'POSTQA REVERSE STALE']);
        $allLanguages = $this->insertIssue(['site_identifier' => 'qa', 'page_uid' => 4, 'source_table' => 'pages', 'source_uid' => 4, 'source_field' => '__rendered_html', 'source_type' => 'rendered', 'source_lang_uid' => -1, 'context_snippet' => 'POSTQA ALL LANGUAGES PAGE']);
        $rows = array_map(fn (int $uid): array => (array)$this->findByUid('tx_a11y_issue', $uid), [$stale, $allLanguages]);
        foreach ([3, 4] as $user) {
            $this->user($user);
            self::assertSame([], $this->get(LocalFindingSourceAccessService::class)->filter($rows));
            self::assertSame(0, $this->openCount(4));
        }
        $this->user(1);
        self::assertSame(2, $this->openCount(4));
    }

    #[Test]
    public function pageRecordReadUsesTheCurrentRowLanguage(): void
    {
        $access = $this->get(LocalFindingSourceAccessService::class);
        $this->user(3);
        self::assertTrue($access->canReadRecord('pages', 4));
        self::assertFalse($access->canReadRecord('pages', 2), 'A default-language page row is language 0.');
        $this->user(2);
        self::assertTrue($access->canReadRecord('pages', 2));
        self::assertFalse($access->canReadRecord('pages', 4));
        // A translation row without a parent is inconsistent provenance.
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['l10n_parent' => 0], ['uid' => 4]);
        $this->user(4);
        self::assertFalse($access->canReadRecord('pages', 4));
        $this->user(1);
        self::assertTrue($access->canReadRecord('pages', 4));
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    private function remoteResult(int $scanUid, int $uid, string $url, int $contentUid): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', ['uid' => $uid, 'remote_scan' => $scanUid, 'url' => $url, 'title' => 'QA result ' . $uid, 'http_status' => 200]);
        $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', ['uid' => $uid, 'remote_scan' => $scanUid, 'remote_scan_page' => $uid, 'rule_id' => 'qa-rule-' . $uid, 'impact' => 'serious', 'nodes_count' => 1]);
        $pool->getConnectionForTable('tx_a11y_remote_issue_node')->insert('tx_a11y_remote_issue_node', ['uid' => $uid, 'remote_issue' => $uid, 'mapped_table' => 'tt_content', 'mapped_uid' => $contentUid, 'html_snippet' => 'QA SYNTHETIC NODE ' . $uid]);
    }

    private function historyScan(int $uid, string $scope, int $pageUid, int $languageUid, int $crdate): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => $uid, 'site_identifier' => 'qa', 'job_id' => sprintf('00000000-0000-4000-8000-%012d', $uid), 'scan_scope' => $scope,
            'page_uid' => $pageUid, 'language_uid' => $languageUid, 'status' => 'completed', 'crdate' => $crdate,
        ]);
    }

    /** @return array{pages: list<array<string, mixed>>, jobs: list<string>} every page of the answer, pageSize 2 */
    private function completeHistory(int $beUserUid): array
    {
        $exec = $this->get(McpToolExecutor::class);
        $pages = [];
        $jobs = [];
        for ($page = 1; $page <= 10; $page++) {
            $answer = $exec->execute('aqg_list_scans', ['siteIdentifier' => 'qa', 'page' => $page, 'pageSize' => 2], $this->principal($beUserUid, ['history:read']));
            $pages[] = $answer;
            $jobs = array_merge($jobs, array_column($answer['scans'], 'jobId'));
            if (!$answer['hasMore']) {
                break;
            }
            self::assertNull($answer['nextCursor'], 'A complete search hands out no cursor.');
        }

        return ['pages' => $pages, 'jobs' => $jobs];
    }

    private function openCount(int $pageUid): int
    {
        $counts = $this->get(IssueRepository::class)->countOpenBySeverity($pageUid, 'qa', -1, '', true);

        return (int)array_sum(array_map('intval', $counts));
    }

    private function contentCsv(ExportController $ctrl): string
    {
        return (string)$ctrl->csvAction($this->request(['scope' => 'content', 'site' => 'qa', 'pageUid' => '2', 'id' => '2', 'language' => '0']))->getBody();
    }

    /** @param list<string> $scopes @return array<string, mixed> */
    private function principal(int $beUserUid, array $scopes): array
    {
        return [
            'tokenId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'tokenSuffix' => 'synthetic', 'clientName' => 'S01 synthetic',
            'beUserUid' => $beUserUid, 'scopes' => $scopes, 'siteIdentifiers' => ['qa'], 'expiresAt' => '2099-12-01T00:00:00Z',
        ];
    }

    private function refusal(callable $call): McpCallRefused
    {
        try {
            $call();
        } catch (McpCallRefused $refused) {
            return $refused;
        }
        self::fail('Expected a refusal.');
    }

    /** @param list<string> $values @return list<string> */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    private function user(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /** @param array<string, string> $query */
    private function request(array $query): ServerRequest
    {
        $request = (new ServerRequest('https://qa.example.invalid/typo3/ajax/a11y/test'))->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
