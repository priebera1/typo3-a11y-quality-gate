<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ScanAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Scan\ScanResult;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SOL audit AUTH-01 / AUTH-02 (F9): native Content scan admission and the Content status/cancel boundary follow the actual
 * translation record, as the frontend and MCP admission do (ScopeAccessService::canScanContentPage()).
 *
 * Fixture: root 1; page 2 (editable) with German translation 20 the editors may neither show nor edit; page 3 (editable)
 * with an editable German translation 30; page 4 without translation. Content with an empty link on every page and
 * translation. Users: 1 admin; 2 editor of languages 0+1; 4 editor without language restriction; 5 editor of language 0.
 *
 * Every refused request changes no scan lock, status, scan run, finding or source state.
 */
final class SolAuditContentScanAuthorizationTest extends AbstractFunctionalTestCase
{
    private const SITE = 'solscan';

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        foreach ([
            [1, 0, 'Root', '/', 31, 0, 0],
            [2, 1, 'Opening hours', '/hours', 31, 0, 0],
            [20, 1, 'Öffnungszeiten', '/hours', 0, 1, 2],
            [3, 1, 'Contact', '/contact', 31, 0, 0],
            [30, 1, 'Kontakt', '/contact', 31, 1, 3],
            [4, 1, 'Imprint', '/imprint', 31, 0, 0],
        ] as [$uid, $pid, $title, $slug, $perms, $language, $parent]) {
            $this->insertPage($uid, $pid, $title, $slug, $perms, $language, $parent);
        }
        foreach ([[10, 2, 0, 0], [403, 2, 1, 10], [12, 3, 0, 0], [13, 3, 1, 12], [14, 4, 0, 0]] as [$uid, $pid, $language, $parent]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'CType' => 'text', 'header' => 'Text ' . $uid, 'sys_language_uid' => $language,
                'l18n_parent' => $parent, 'bodytext' => '<p><a href="/x"></a></p>', 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);
        foreach ([[10, '0,1'], [12, ''], [13, '0']] as [$uid, $languages]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'title' => 'Editors ' . $uid, 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
                'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => $languages,
            ]);
        }
        foreach ([[1, 1, ''], [2, 0, '10'], [4, 0, '12'], [5, 0, '13']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => 'user' . $uid, 'admin' => $admin, 'usergroup' => $group, 'disable' => 0, 'deleted' => 0,
            ]);
        }
        // `.invalid` never resolves: the rendered check fails offline, the content check runs.
        $this->get(SiteWriter::class)->write(self::SITE, ['rootPageId' => 1, 'base' => 'https://solscan.invalid/', 'languages' => [
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

    // ---------------------------------------------------------------- AUTH-01 single page

    #[Test]
    public function aTranslationWhoseRecordTheEditorMayNotEditIsNotScannedThroughItsDefaultPage(): void
    {
        $this->user(2);
        // F9: 200, one scan, a stored language-1 finding of content 403 (SOL reproduction).
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(2, 1));

        $allowed = $this->scan(2, 0);
        self::assertSame(200, $allowed->getStatusCode(), 'positive control: the default language of the same page');
        // STATE-01: the completion answer says the open issues are those this editor may read.
        self::assertTrue($this->json($allowed)['openIssuesRestricted']);
        self::assertSame(200, $this->scan(3, 1)->getStatusCode(), 'positive control: an editable translation');
        self::assertGreaterThan(0, $this->countIssues(3, 1), 'the authorized translation scan stores its findings');
        self::assertSame(0, $this->countIssues(2, 1), 'nothing was stored for the refused translation');
    }

    #[Test]
    public function allLanguagesAreRefusedWhenOneTranslationRecordIsNotEditable(): void
    {
        $this->user(4);
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(2, -1));
        self::assertSame(200, $this->scan(3, -1)->getStatusCode(), 'positive control: every translation editable');
        $this->user(1);
        $admin = $this->scan(2, -1);
        self::assertSame(200, $admin->getStatusCode(), 'administrator');
        self::assertFalse($this->json($admin)['openIssuesRestricted'], 'an administrator reads every result');
        self::assertGreaterThan(0, $this->countIssues(2, 1));
    }

    #[Test]
    public function everyLiveTranslationRowDecidesHiddenOnesIncludedDeletedAndWorkspaceRowsDoNot(): void
    {
        $this->user(2);
        // A deleted and a workspace-only duplicate the editor may not edit do not block the live, editable translation 30.
        $this->insertPage(31, 1, 'Kontakt alt', '/contact', 0, 1, 3, ['deleted' => 1]);
        $this->insertPage(32, 1, 'Kontakt WS', '/contact', 0, 1, 3, ['t3ver_wsid' => 1, 't3ver_oid' => 30]);
        self::assertSame(200, $this->scan(3, 1)->getStatusCode(), 'deleted and workspace rows are not the live translation');

        // A hidden live duplicate is still live: its content would be collected, so it decides too.
        $this->insertPage(33, 1, 'Kontakt (dup)', '/contact', 0, 1, 3, ['hidden' => 1]);
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(3, 1));
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scanSite(3, 1));
    }

    #[Test]
    public function revokedTranslationRightsApplyToTheNextRequest(): void
    {
        $this->user(2);
        self::assertSame(200, $this->scan(3, 1)->getStatusCode());
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 30]);
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scan(3, 1));
    }

    // ---------------------------------------------------------------- AUTH-01 site / subtree

    #[Test]
    public function aSiteScanChecksOnlyAdmittedPagesAndRecordsTheOthersAsNotPermitted(): void
    {
        $this->user(2);
        $response = $this->scanSite(1, 1);
        $payload = $this->json($response);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $payload['pagesNotPermitted'], 'page 2: its German record is refused');
        self::assertSame(0, $this->countIssues(2, 1), 'no finding of the refused translation');
        self::assertGreaterThan(0, $this->countIssues(3, 1), 'the admitted translation is checked');

        $run = $this->getConnectionPool()->getConnectionForTable('tx_a11y_scan')->select(['*'], 'tx_a11y_scan', ['scope' => 'subtree'])->fetchAssociative();
        self::assertSame(1, (int)$run['pages_not_permitted']);
        self::assertSame([2], json_decode((string)$run['not_permitted_json'], true));
        $scans = $this->get(ScanRepository::class);
        self::assertNull($scans->findLastCompletedSubtreeScanCoveringPage(self::SITE, 2, 1), 'the refused page is never covered by it');
        self::assertIsArray($scans->findLastCompletedSubtreeScanCoveringPage(self::SITE, 3, 1));
        self::assertFalse(ScanRepository::coversWholeSite($run, 1), 'a partial scan is no evidence for the whole site');
    }

    #[Test]
    public function anAllLanguageSiteScanLeavesOutPagesWithARefusedTranslation(): void
    {
        $this->user(4);
        $payload = $this->json($this->scanSite(1, -1));
        self::assertSame(1, $payload['pagesNotPermitted']);
        self::assertSame(0, $this->countIssues(2, 1));
        self::assertSame(0, $this->countIssues(2, 0), 'the page is left out in every language');
        self::assertGreaterThan(0, $this->countIssues(4, 0));

        $this->user(1);
        self::assertSame(0, $this->json($this->scanSite(1, -1))['pagesNotPermitted'], 'administrator: the whole site');
        self::assertGreaterThan(0, $this->countIssues(2, 1));
    }

    #[Test]
    public function aSubtreeWithoutAnyAdmittedPageIsRefusedBeforeAnyChange(): void
    {
        $this->user(2);
        $this->assertRefusedWithoutAnyChange(fn (): ResponseInterface => $this->scanSite(2, 1));
        self::assertSame(200, $this->scanSite(3, 1)->getStatusCode(), 'positive control');
    }

    #[Test]
    public function anEditorsSiteScanDoesNotSweepFindingsOutsideItsPages(): void
    {
        $orphan = $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 2, 'source_lang_uid' => 1, 'source_table' => 'tt_content', 'source_uid' => 999, 'status' => 0]);
        $this->user(2);
        $this->scanSite(1, 1);
        self::assertSame(0, (int)$this->getConnectionPool()->getConnectionForTable('tx_a11y_issue')->select(['status'], 'tx_a11y_issue', ['uid' => $orphan])->fetchOne(), 'still open');
        $this->user(1);
        $this->scanSite(1, 1);
        self::assertNotSame(0, (int)$this->getConnectionPool()->getConnectionForTable('tx_a11y_issue')->select(['status'], 'tx_a11y_issue', ['uid' => $orphan])->fetchOne(), 'positive control: the administrator\'s scan retires it');
    }

    // ---------------------------------------------------------------- AUTH-02 status, 409, cancel

    #[Test]
    public function thePageScanStatusOfARefusedTranslationIsGeneric(): void
    {
        $status = $this->get(ScanStatusService::class);
        self::assertNotNull($status->acquireScanLock());
        $status->markRunning(trigger: 'page', triggeredBy: 'user1', pageUid: 2, languageUid: 1);
        $status->markScanRunStarted(77);

        $this->user(2);
        $payload = $this->json($this->statusRequest());
        self::assertTrue($payload['status']['running'], 'that a scan runs is shared');
        self::assertTrue($payload['status']['restricted']);
        foreach (['pageUid', 'rootPid', 'languageUid', 'triggeredBy', 'scanUid', 'summary', 'trigger'] as $key) {
            self::assertArrayNotHasKey($key, $payload['status']);
        }
        // 409 of an authorized request carries the same redacted status.
        $busy = $this->scan(3, 1);
        self::assertSame(409, $busy->getStatusCode());
        self::assertArrayNotHasKey('pageUid', $this->json($busy)['status']);
        self::assertStringNotContainsString('user1', (string)$busy->getBody());

        $this->user(1);
        self::assertSame(2, $this->json($this->statusRequest())['status']['pageUid'], 'positive control: administrator');
    }

    #[Test]
    public function aSiteScanStatusWithholdsCountersAndWarningsOfPagesTheViewerMayNotRead(): void
    {
        $status = $this->get(ScanStatusService::class);
        $status->markRunning(trigger: 'site', triggeredBy: 'user1', rootPid: 1, languageUid: 1);
        $result = new ScanResult(scanUid: 5);
        $result->issuesNew = 7;
        $result->pagesScanned = 4;
        $result->addWarning('rendered_check_skipped', 'Page 2 could not be fetched', ['pageUid' => 2, 'languageUid' => 1]);
        $result->addWarning('rendered_check_skipped', 'Page 3 could not be fetched', ['pageUid' => 3, 'languageUid' => 1]);
        $status->markFinished($result);

        $this->user(2);
        $summary = $this->json($this->statusRequest())['status']['summary'];
        self::assertNull($summary['issuesNew'], 'issue delta of the whole subtree withheld (no number claimed)');
        self::assertNull($summary['pagesScanned']);
        self::assertArrayNotHasKey('triggeredBy', $this->json($this->statusRequest())['status'], 'actor of another user\'s scan');
        self::assertTrue($summary['countsWithheld']);
        self::assertSame([3], array_map(static fn (array $w): int => (int)$w['context']['pageUid'], $summary['warnings']), 'no warning of the refused translation');

        $this->user(5);
        $generic = $this->json($this->statusRequest())['status'];
        self::assertTrue($generic['restricted'], 'a language the viewer may not use: generic state only');
        self::assertArrayNotHasKey('languageUid', $generic);

        $this->user(1);
        self::assertSame(7, $this->json($this->statusRequest())['status']['summary']['issuesNew'], 'positive control: administrator');
    }

    #[Test]
    public function cancellingNeedsTheRightToStartThatScan(): void
    {
        $status = $this->get(ScanStatusService::class);
        self::assertNotNull($status->acquireScanLock());
        $status->markRunning(trigger: 'page', triggeredBy: 'user1', pageUid: 2, languageUid: 1);

        foreach ([2, 4, 5] as $user) {
            $this->user($user);
            self::assertSame(403, $this->cancel()->getStatusCode(), 'user ' . $user . ': root/page rights alone do not stop a refused translation scan');
            self::assertFalse($status->isCancellationRequested());
        }
        $this->user(1);
        self::assertSame(200, $this->cancel()->getStatusCode(), 'positive control: administrator');
        self::assertTrue($status->isCancellationRequested());

        // An administrator's site scan covers pages an editor may not scan: root and language rights do not stop it.
        $status->markRunning(trigger: 'site', triggeredBy: 'user1', rootPid: 1, languageUid: 1);
        $this->user(2);
        self::assertSame(403, $this->cancel()->getStatusCode(), 'another user\'s site scan');
        self::assertFalse($status->isCancellationRequested());
        // The editor's own site scan (it covers only pages they were admitted to).
        $status->markRunning(trigger: 'site', triggeredBy: 'user2', rootPid: 1, languageUid: 1);
        $this->user(5);
        self::assertSame(403, $this->cancel()->getStatusCode(), 'site scan of a language the user may not use');
        self::assertFalse($status->isCancellationRequested());
        $this->user(2);
        self::assertSame(200, $this->cancel()->getStatusCode(), 'positive control: own site scan, root and language');
        self::assertTrue($status->isCancellationRequested());
    }

    /** Review F10-1: the toolbar never attributes a stored scan (here a Scheduler site scan) to the registry's actor. */
    #[Test]
    public function theToolbarDoesNotLendTheRegistryActorToAnotherStoredScan(): void
    {
        $status = $this->get(ScanStatusService::class);
        $status->markRunning(trigger: 'page', triggeredBy: 'user2', pageUid: 3, languageUid: 0);
        $status->markScanRunStarted(5);
        $status->markFinished(new ScanResult(scanUid: 5));
        // A newer Scheduler site scan (no registry entry).
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_scan')->insert('tx_a11y_scan', [
            'uid' => 9, 'site_identifier' => self::SITE, 'root_pid' => 1, 'language_uid' => 0, 'scope' => 'subtree', 'status' => 2,
            'started_at' => time() + 5, 'finished_at' => time() + 10, 'pages_scanned' => 6, 'records_scanned' => 40, 'issues_new' => 3,
        ]);

        $this->user(2);
        $toolbar = $this->get(\Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem::class);
        $request = $this->request('toolbar', [], 'GET')->withQueryParams(['site' => self::SITE, 'id' => '3', 'language' => '0']);
        $toolbar->setRequest($request);
        $local = (new \ReflectionMethod($toolbar, 'resolveLocalStatus'))->invoke($toolbar);
        self::assertSame(9, (int)$local['scanUid']);
        self::assertNotSame('user2', (string)($local['triggeredBy'] ?? ''), 'F10 review: the editor\'s name was put on the Scheduler scan');
        self::assertNull($local['summary']['pagesScanned'], 'whole-site counters of a scan the editor did not start are withheld');
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, int> $extra */
    private function insertPage(int $uid, int $pid, string $title, string $slug, int $perms, int $language, int $parent, array $extra = []): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', $extra + [
            'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $uid === 1 ? 1 : 0,
            'perms_userid' => 99, 'perms_groupid' => 99, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => $perms,
            'sys_language_uid' => $language, 'l10n_parent' => $parent, 'deleted' => 0, 'hidden' => 0,
        ]);
    }

    private function assertRefusedWithoutAnyChange(callable $request): void
    {
        $status = $this->get(ScanStatusService::class);
        $before = [$status->getStatus(), $status->isScanLockHeld(), $this->counts()];
        $response = $request();
        self::assertSame(403, $response->getStatusCode());
        self::assertSame($before, [$status->getStatus(), $status->isScanLockHeld(), $this->counts()], 'no lock, status, scan, finding or source state change');
        foreach (['Öffnungszeiten', 'Kontakt', 'Deutsch'] as $private) {
            self::assertStringNotContainsString($private, (string)$response->getBody());
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'scans' => $this->countRows('tx_a11y_scan'),
            'issues' => $this->countRows('tx_a11y_issue'),
            'sourceStates' => $this->countRows('tx_a11y_source_state'),
        ];
    }

    private function countIssues(int $pageUid, int $languageUid): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable('tx_a11y_issue')
            ->count('uid', 'tx_a11y_issue', ['page_uid' => $pageUid, 'source_lang_uid' => $languageUid]);
    }

    private function scan(int $pageUid, int $languageUid): ResponseInterface
    {
        return $this->get(ScanAjaxController::class)->scanPageAction($this->request('scan/page', ['pageUid' => (string)$pageUid, 'languageUid' => (string)$languageUid]));
    }

    private function scanSite(int $rootPid, int $languageUid): ResponseInterface
    {
        return $this->get(ScanAjaxController::class)->scanSiteAction($this->request('scan/site', ['rootPid' => (string)$rootPid, 'languageUid' => (string)$languageUid]));
    }

    private function statusRequest(): ResponseInterface
    {
        return $this->get(ScanAjaxController::class)->scanStatusAction($this->request('scan/status', [], 'GET'));
    }

    private function cancel(): ResponseInterface
    {
        return $this->get(ScanAjaxController::class)->cancelScanAction($this->request('scan/cancel', []));
    }

    /** @param array<string, string> $body */
    private function request(string $path, array $body, string $method = 'POST'): ServerRequest
    {
        $request = (new ServerRequest('https://solscan.invalid/typo3/ajax/a11y/' . $path, $method))
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    /** @return array<string, mixed> */
    private function json(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
    }

    private function user(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }
}
