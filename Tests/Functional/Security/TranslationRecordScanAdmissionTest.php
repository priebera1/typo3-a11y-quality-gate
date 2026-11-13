<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Security;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\FreePreview\FreeSubmitIntentService;
use Priebera\A11yQualityGate\Mcp\McpCallRefused;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * I-01 (MCP final integration acceptance, 2026-11-07): a single-page scan target is a default-language page uid plus a
 * language. The uid of a page TRANSLATION record is not a scan target in any language — not as "language 0" (the frontend
 * URL resolver then builds the translation's URL, so an editor without that language scanned it and the result was stored
 * as a default-language scan), and not with its own language either (the public contract names default-language pages;
 * translations are selected with the language). The refusal happens before any idempotency reservation and crawler request,
 * for MCP `aqg_start_scan`, native "Scan this page" and the Free Remote Preview alike, and a legacy row stored with such a
 * uid is read under the record's real language.
 *
 * Site qa (root 1): 2 "/open" + German 4 "/offen"; 6 "/other" + German 9 "/andere".
 * Site other (root 20): 21 "/x" + German 22 "/x-de".
 * Users: 1 admin; 2 editor languages 0,1 (mount 1); 4 editor language 0 only (mount 1); 5 editor languages 0,1 (mount 20 only).
 */
final class TranslationRecordScanAdmissionTest extends AbstractFunctionalTestCase
{
    /** @var list<array<string, mixed>> */
    private array $crawlerSubmits = [];

    /** @var list<string> */
    private array $outboundPaths = [];

    private int $jobCounter = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->crawlerSubmits = [];
        $this->outboundPaths = [];
        $this->jobCounter = 0;
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL=http://aqg-api.invalid');
        putenv('A11Y_QUALITY_GATE_PRO_CRAWLER_BASE_URL=http://aqg-crawler.invalid');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['licenceKey'] = 'aqg_live_' . str_repeat('t', 32);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(function (RequestInterface $request, array $options) {
            $path = $request->getUri()->getPath();
            $this->outboundPaths[] = $request->getUri()->getHost() . $path;
            $body = json_decode((string)$request->getBody(), true) ?: [];
            $answer = match (true) {
                str_ends_with($path, '/licence/validate') => [200, ['success' => true, 'valid' => true, 'plan' => 'pro', 'features' => ['crawler']]],
                str_ends_with($path, '/auth/token') => [200, ['access_token' => 'synthetic-access-token', 'expires_in' => 3600, 'plan' => 'pro', 'features' => ['crawler'], 'entitlement' => 'paid']],
                str_ends_with($path, '/crawl/submit') => $this->crawlerSubmit($body),
                default => [404, ['success' => false, 'error' => ['code' => 'route_not_found']]],
            };

            return Create::promiseFor(new Response($answer[0], ['Content-Type' => 'application/json'], (string)json_encode($answer[1])));
        });

        $pool = $this->getConnectionPool();
        foreach ([
            [1, 0, 'I01 root', '/', 0, 0, 1],
            [2, 1, 'I01 base', '/open', 0, 0, 0],
            [4, 1, 'I01 German of 2', '/offen', 1, 2, 0],
            [6, 1, 'I01 other', '/other', 0, 0, 0],
            [9, 1, 'I01 German of 6', '/andere', 1, 6, 0],
            [20, 0, 'I01 other site root', '/', 0, 0, 1],
            [21, 20, 'I01 other site page', '/x', 0, 0, 0],
            [22, 20, 'I01 German of 21', '/x-de', 1, 21, 0],
        ] as [$uid, $pid, $title, $slug, $lang, $parent, $root]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $root,
                'perms_everybody' => 31, 'perms_userid' => 99, 'perms_user' => 31, 'sys_language_uid' => $lang, 'l10n_parent' => $parent,
            ]);
        }
        foreach ([[10, '1', '0,1'], [12, '1', '0'], [13, '20', '0,1']] as [$gid, $mount, $languages]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $gid, 'title' => 'I01 group ' . $gid, 'groupMods' => 'web,web_a11y', 'db_mountpoints' => $mount,
                'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => $languages,
                'TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1',
            ]);
        }
        foreach ([[1, 1, ''], [2, 0, '10'], [4, 0, '12'], [5, 0, '13']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => 'i01user' . $uid, 'admin' => $admin, 'usergroup' => $group, 'workspace_perms' => 1,
                'TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1',
            ]);
        }
        $languages = [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
        ];
        $this->get(SiteWriter::class)->write('qa', ['rootPageId' => 1, 'base' => 'https://qa.example.invalid/', 'languages' => $languages]);
        $this->get(SiteWriter::class)->write('other', ['rootPageId' => 20, 'base' => 'https://other.example.invalid/', 'languages' => $languages]);
        $this->get(SiteFinder::class)->getAllSites(false);
        foreach (['qa', 'other'] as $site) {
            $this->get(McpSiteSettingsRepository::class)->save($site, true, ['findings:read', 'history:read', 'scans:run-page'], 1);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'], $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['licenceKey'], $GLOBALS['TYPO3_REQUEST']);
        putenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        putenv('A11Y_QUALITY_GATE_PRO_CRAWLER_BASE_URL');
        foreach ($this->outboundPaths as $path) {
            self::assertStringNotContainsString('priebera.sk', $path, 'no request may name a production host');
        }
        parent::tearDown();
    }

    // ---- A / D: legitimate scans keep working --------------------------------------------------------------------------

    #[Test]
    public function aDefaultLanguageEditorScansTheDefaultPageInLanguageZero(): void
    {
        $result = $this->startScan(4, 2, 0, 'i01-a-mcp');
        self::assertSame('submitted', $result['status']);
        self::assertCount(1, $this->crawlerSubmits);
        self::assertSame('https://qa.example.invalid/open', (string)$this->crawlerSubmits[0]['startUrl']);
        $this->assertStoredScan($result['scan']['jobId'], 2, 0);
        $this->finishScan($result['scan']['jobId']);

        $native = $this->nativeSubmit(4, 2, 0);
        self::assertSame(200, $native->getStatusCode(), (string)$native->getBody());
        self::assertCount(2, $this->crawlerSubmits);

        // Free Remote Preview control (review F-2): an admitted page passes the admission rule and asks the Free service.
        $this->finishScan((string)$this->crawlerSubmits[1]['jobId']);
        // The site has no FREE terms acceptance, so an admitted request stops at that LATER gate — proof that it passed
        // the admission rule the refused cases above stop at.
        $free = $this->freeSubmit(4, 2, 0);
        self::assertSame('free_terms_acceptance_required', json_decode((string)$free->getBody(), true)['code'] ?? '', (string)$free->getBody());
    }

    #[Test]
    public function aGermanEditorScansTheTranslationThroughTheDefaultPageAndLanguageOne(): void
    {
        $result = $this->startScan(2, 2, 1, 'i01-d-mcp');
        self::assertSame('submitted', $result['status']);
        self::assertSame('https://qa.example.invalid/de/offen', (string)$this->crawlerSubmits[0]['startUrl']);
        self::assertSame(1, (int)$this->crawlerSubmits[0]['languageId']);
        $this->assertStoredScan($result['scan']['jobId'], 2, 1);
        $this->finishScan($result['scan']['jobId']);

        $native = $this->nativeSubmit(2, 2, 1);
        self::assertSame(200, $native->getStatusCode(), (string)$native->getBody());
        self::assertSame('https://qa.example.invalid/de/offen', (string)$this->crawlerSubmits[1]['startUrl']);
    }

    // ---- B / C / E: a translation record uid is never a scan target --------------------------------------------------

    #[Test]
    public function aDefaultLanguageEditorCannotScanATranslationRecordAsLanguageZero(): void
    {
        $this->assertTranslationUidRefused(4, 4, 0);
    }

    #[Test]
    public function aDefaultLanguageEditorCannotScanATranslationRecordInItsLanguage(): void
    {
        $this->assertTranslationUidRefused(4, 4, 1);
    }

    #[Test]
    public function aGermanEditorIsRefusedTheTranslationRecordUidInEveryLanguage(): void
    {
        // Not a supported representation: the default page uid with languageUid selects a translation (case D).
        $this->assertTranslationUidRefused(2, 4, 1);
        $this->assertTranslationUidRefused(2, 4, 0);
    }

    #[Test]
    public function anAdministratorIsRefusedTheTranslationRecordUidToo(): void
    {
        // Integrity, not only permission: a German crawl must never be stored as a language-0 scan of a translation uid.
        $this->assertTranslationUidRefused(1, 4, 0);
        $this->assertTranslationUidRefused(1, 4, 1);
    }

    /** Review F-2: the Free Remote Preview path alone, so its refusal is proven independently of MCP and native. */
    #[Test]
    public function theFreeRemotePreviewRefusesATranslationRecordUidAtAdmission(): void
    {
        $free = $this->freeSubmit(4, 4, 0);
        self::assertSame(['success' => false, 'error' => 'Access denied'], json_decode((string)$free->getBody(), true), (string)$free->getBody());
        self::assertSame([], array_values(array_filter($this->outboundPaths, static fn (string $p): bool => str_contains($p, 'free'))));
    }

    // ---- F / G: translations of other pages and other sites --------------------------------------------------------

    #[Test]
    public function aTranslationOfAnotherPageIsRefused(): void
    {
        $this->assertTranslationUidRefused(2, 9, 1);
        $this->assertTranslationUidRefused(4, 9, 0);
    }

    #[Test]
    public function aTranslationInAnotherSiteIsRefusedWithoutDisclosingIt(): void
    {
        // Outside the editor's mounts: indistinguishable from a missing page.
        $this->assertRefusedWith('page_not_found', fn () => $this->startScan(2, 22, 0, 'i01-g-mcp'));
        self::assertSame(403, $this->nativeSubmit(2, 22, 0)->getStatusCode());
        // An editor of that site and an administrator are refused the record uid as well.
        $this->assertTranslationUidRefused(5, 22, 0);
        $this->assertTranslationUidRefused(1, 22, 1);
        self::assertSame([], $this->crawlerSubmits);
    }

    // ---- H / I: deleted, hidden, workspace-only, duplicate translations --------------------------------------------

    #[Test]
    public function deletedHiddenAndWorkspaceOnlyTranslationRecordsAreRefused(): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->update('pages', ['hidden' => 1], ['uid' => 4]);
        $this->assertTranslationUidRefused(2, 4, 0);
        $pages->update('pages', ['hidden' => 0, 'deleted' => 1], ['uid' => 4]);
        // A deleted record is unknown, also to an administrator.
        $this->assertTranslationUidRefused(1, 4, 0, 'page_not_found');
        $pages->insert('pages', [
            'uid' => 7, 'pid' => 1, 'title' => 'I01 draft German', 'slug' => '/entwurf', 'doktype' => 1, 'perms_everybody' => 31,
            'sys_language_uid' => 1, 'l10n_parent' => 2, 't3ver_wsid' => 1, 't3ver_state' => 1,
        ]);
        // A workspace-only record does not exist in the live site.
        $this->assertTranslationUidRefused(1, 7, 0, 'page_not_found');
        self::assertSame([], $this->crawlerSubmits);
    }

    #[Test]
    public function aDuplicateTranslationRowIsRefusedByUidAndStillGuardsTheDefaultPage(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 8, 'pid' => 1, 'title' => 'I01 duplicate German', 'slug' => '/doppelt', 'doktype' => 1, 'perms_everybody' => 0,
            'perms_userid' => 99, 'perms_user' => 31, 'sys_language_uid' => 1, 'l10n_parent' => 2,
        ]);
        // The duplicate is not readable for the editor: refused like a missing page (no disclosure), natively 403.
        $this->assertRefusedWith('page_not_found', fn () => $this->startScan(2, 8, 1, 'i01-i-record'));
        self::assertSame(403, $this->nativeSubmit(2, 8, 1)->getStatusCode());
        // An administrator, who can read it, is refused the record uid by the target rule.
        $this->assertTranslationUidRefused(1, 8, 1);
        // The existing live-translation rule still applies to the default page: the denied duplicate refuses language 1.
        $this->assertRefusedWith('permission_denied', fn () => $this->startScan(2, 2, 1, 'i01-i-default'));
        self::assertSame([], $this->crawlerSubmits);
    }

    // ---- J: revoked rights, replay of an earlier key -----------------------------------------------------------------

    #[Test]
    public function afterTheLanguageIsRevokedNeitherTheReplayNorTheRecordUidScans(): void
    {
        $first = $this->startScan(2, 2, 1, 'i01-j-key');
        self::assertSame('submitted', $first['status']);
        $this->finishScan($first['scan']['jobId']);
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', ['allowed_languages' => '0'], ['uid' => 10]);

        $this->assertRefusedWith('permission_denied', fn () => $this->startScan(2, 2, 1, 'i01-j-key'));
        $this->assertTranslationUidRefused(2, 4, 0);
        self::assertCount(1, $this->crawlerSubmits);
    }

    // ---- stored rows: a legacy mislabelled scan is read under its real language ------------------------------------

    #[Test]
    public function aLegacyScanOfATranslationUidStoredAsLanguageZeroIsReadUnderTheRecordsLanguage(): void
    {
        $jobId = 'eeeeeeee-0000-4000-8000-0000000000aa';
        $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)->insert(Tables::REMOTE_SCAN, [
            'uid' => 70, 'site_identifier' => 'qa', 'job_id' => $jobId, 'scan_scope' => 'page', 'source_type' => 'single_page',
            'page_uid' => 4, 'language_uid' => 0, 'status' => 'completed', 'persisted_at' => time(), 'finished_at' => time(),
            'crdate' => time(), 'start_url' => 'https://qa.example.invalid/de/offen', 'pages_scanned' => 1, 'issues_total' => 4,
        ]);
        $row = $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)
            ->select(['*'], Tables::REMOTE_SCAN, ['uid' => 70])->fetchAssociative();
        $scope = $this->get(ScopeAccessService::class);

        $this->user(4);
        self::assertFalse($scope->canReadRemoteScan($row), 'a German crawl is not readable without German');
        self::assertFalse($scope->canEditRemoteScan($row));
        $this->assertRefusedWith('scan_not_found', fn () => $this->execute('aqg_get_scan_status', ['siteIdentifier' => 'qa', 'jobId' => $jobId], 4));
        $this->assertRefusedWith('scan_not_found', fn () => $this->execute('aqg_get_scan_results', ['siteIdentifier' => 'qa', 'jobId' => $jobId], 4));

        $this->user(2);
        self::assertTrue($scope->canReadRemoteScan($row), 'readable for a German editor with access to the translation');
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 4]);
        $this->user(2);
        self::assertFalse($scope->canReadRemoteScan($row), 'the translation record keeps its own page permissions');

        $this->user(1);
        self::assertTrue($scope->canReadRemoteScan($row), 'administrators read it');
    }

    #[Test]
    public function thePageModuleIndicatorShowsNoPanelForATranslationRecordUid(): void
    {
        // F4 final review: read as language 0, the translation's German findings were filtered out and the panel said
        // "No issues found" with the German scan time to an editor without German.
        $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)->insert(Tables::REMOTE_SCAN, [
            'uid' => 71, 'site_identifier' => 'qa', 'job_id' => 'eeeeeeee-0000-4000-8000-0000000000ab', 'scan_scope' => 'page',
            'source_type' => 'single_page', 'page_uid' => 4, 'language_uid' => 0, 'status' => 'completed', 'persisted_at' => time(),
            'finished_at' => time(), 'crdate' => time(), 'start_url' => 'https://qa.example.invalid/de/offen', 'pages_scanned' => 1,
            'issues_total' => 4,
        ]);
        foreach ([4, 2, 1] as $beUserUid) {
            $this->user($beUserUid);
            $response = $this->indicator(4, 0);
            self::assertSame(404, $response->getStatusCode(), 'user ' . $beUserUid . ': ' . (string)$response->getBody());
            self::assertSame(['success' => false, 'code' => 'not_found'], json_decode((string)$response->getBody(), true));
        }
        $this->user(4);
        self::assertSame(200, $this->indicator(2, 0)->getStatusCode(), 'the default page keeps its panel');
    }

    #[Test]
    public function aRemoteExportWithoutAScanRefusesATranslationRecordUidAndAForbiddenLanguage(): void
    {
        // F4 final review: an Overview export without a scan or result page picks the latest scan of the requested page and
        // language itself; for a translation uid (the legacy row) or a forbidden language that was a scan the user may not read.
        $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)->insert(Tables::REMOTE_SCAN, [
            'uid' => 73, 'site_identifier' => 'qa', 'job_id' => 'eeeeeeee-0000-4000-8000-0000000000ad', 'scan_scope' => 'page',
            'source_type' => 'single_page', 'page_uid' => 4, 'language_uid' => 0, 'status' => 'completed', 'persisted_at' => time(),
            'finished_at' => time(), 'crdate' => time(), 'start_url' => 'https://qa.example.invalid/de/offen', 'pages_scanned' => 1,
            'issues_total' => 4,
        ]);
        foreach ([[1, 4, 0], [2, 4, 0], [4, 4, 0], [4, 2, 1]] as [$beUserUid, $pageUid, $languageUid]) {
            $this->user($beUserUid);
            $response = $this->remoteExport($pageUid, $languageUid);
            self::assertSame(403, $response->getStatusCode(), sprintf('user %d, page %d, language %d', $beUserUid, $pageUid, $languageUid));
            self::assertStringNotContainsString('offen', (string)$response->getBody());
        }
        // Naming a readable scan that is not completed lets the builder fall back to the page lookup: still refused.
        $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)->insert(Tables::REMOTE_SCAN, [
            'uid' => 74, 'site_identifier' => 'qa', 'job_id' => 'eeeeeeee-0000-4000-8000-0000000000ae', 'scan_scope' => 'page',
            'source_type' => 'single_page', 'page_uid' => 2, 'language_uid' => 0, 'status' => 'running', 'crdate' => time(),
            'start_url' => 'https://qa.example.invalid/open',
        ]);
        foreach ([1, 2] as $beUserUid) {
            $this->user($beUserUid);
            $response = $this->remoteExport(4, 0, 74);
            self::assertSame(403, $response->getStatusCode(), 'user ' . $beUserUid . ' via a running scan');
            self::assertStringNotContainsString('offen', (string)$response->getBody());
        }
        $this->user(2);
        self::assertNotSame(403, $this->remoteExport(2, 0)->getStatusCode(), 'the default page in an allowed language is still exported');
    }

    // ---- helpers ----------------------------------------------------------------------------------------------------

    private function remoteExport(int $pageUid, int $languageUid, int $remoteScanUid = 0): \Psr\Http\Message\ResponseInterface
    {
        $query = ['scope' => 'remote', 'site' => 'qa', 'id' => (string)$pageUid, 'language' => (string)$languageUid];
        if ($remoteScanUid > 0) {
            $query['remoteScanUid'] = (string)$remoteScanUid;
        }
        $request = (new ServerRequest('https://qa.example.invalid/typo3/module/web/a11y/export/csv?' . http_build_query($query), 'GET'))
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(\Priebera\A11yQualityGate\Controller\ExportController::class)->csvAction($request);
    }

    private function indicator(int $pageUid, int $languageUid): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequest('https://qa.example.invalid/typo3/ajax/a11y/page-module-indicator?id=' . $pageUid . '&language=' . $languageUid, 'GET'))
            ->withQueryParams(['id' => (string)$pageUid, 'language' => (string)$languageUid])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $this->get(\Priebera\A11yQualityGate\Controller\PageModuleIndicatorAjaxController::class)->stateAction($request);
    }

    /** MCP, native "Scan this page" and the Free Remote Preview refuse the same target alike, before anything is reserved or sent. */
    private function assertTranslationUidRefused(int $beUserUid, int $pageUid, int $languageUid, string $reason = 'permission_denied'): void
    {
        $submits = count($this->crawlerSubmits);
        $reservations = $this->tableRows(Tables::MCP_SCAN_REQUEST);
        $scans = $this->tableRows(Tables::REMOTE_SCAN);
        $label = sprintf('user %d, pageUid %d, language %d', $beUserUid, $pageUid, $languageUid);

        $withKey = $this->refused(fn () => $this->startScan($beUserUid, $pageUid, $languageUid, 'i01-' . $beUserUid . '-' . $pageUid . '-' . $languageUid));
        $withoutKey = $this->refused(fn () => $this->startScan($beUserUid, $pageUid, $languageUid, ''));
        foreach ([$withKey, $withoutKey] as $refusal) {
            self::assertSame($reason, $refusal->reason, $label . ': ' . $refusal->getMessage());
            self::assertSame($withKey->getMessage(), $refusal->getMessage());
            foreach (['offen', 'andere', 'x-de', 'example.invalid', '/de/', 'translation'] as $needle) {
                self::assertStringNotContainsString($needle, $refusal->getMessage(), $label);
            }
        }

        $native = $this->nativeSubmit($beUserUid, $pageUid, $languageUid);
        // An unknown record (deleted, workspace-only) may also be refused as 400 unknown_site_context; never accepted.
        self::assertContains($native->getStatusCode(), $reason === 'permission_denied' ? [403] : [400, 403], $label . ': ' . (string)$native->getBody());

        $free = $this->freeSubmit($beUserUid, $pageUid, $languageUid);
        // A valid intent (review F-2): the request reaches the admission rule, so only that rule can refuse it here.
        if ($reason === 'permission_denied') {
            // The admission refusal itself, not a later gate (the FREE terms gate also answers 403, with its own code).
            self::assertSame(403, $free->getStatusCode(), $label . ' (Free Remote Preview): ' . (string)$free->getBody());
            self::assertSame(['success' => false, 'error' => 'Access denied'], json_decode((string)$free->getBody(), true), $label . ' (Free Remote Preview)');
        } else {
            self::assertContains($free->getStatusCode(), [400, 403], $label . ' (Free Remote Preview): ' . (string)$free->getBody());
            self::assertNotSame('free_terms_acceptance_required', json_decode((string)$free->getBody(), true)['code'] ?? '', $label);
        }
        self::assertSame([], array_values(array_filter($this->outboundPaths, static fn (string $p): bool => str_contains($p, 'free'))), $label);

        self::assertCount($submits, $this->crawlerSubmits, $label . ': no crawler job');
        self::assertSame($reservations, $this->tableRows(Tables::MCP_SCAN_REQUEST), $label . ': no idempotency reservation');
        self::assertSame($scans, $this->tableRows(Tables::REMOTE_SCAN), $label . ': no stored scan row');
    }

    /** Free Remote Preview of one page without a licence, with a freshly issued submit intent for that page. */
    private function freeSubmit(int $beUserUid, int $pageUid, int $languageUid): \Psr\Http\Message\ResponseInterface
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['licenceKey']);
        try {
            $this->user($beUserUid);
            $site = $pageUid >= 20 ? 'other' : 'qa';
            $intent = $this->get(FreeSubmitIntentService::class)->create($site, $pageUid);
            self::assertNotSame('', $intent, 'a submit intent was issued');

            return $this->get(ProCrawlerAjaxController::class)->submitSiteAction($this->post([
                'rootPid' => $site === 'other' ? 20 : 1, 'pageUid' => $pageUid, 'languageUid' => $languageUid, 'freeSubmitIntent' => $intent,
            ]));
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['licenceKey'] = 'aqg_live_' . str_repeat('t', 32);
        }
    }

    private function assertStoredScan(string $jobId, int $pageUid, int $languageUid): void
    {
        $row = $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)
            ->select(['page_uid', 'language_uid'], Tables::REMOTE_SCAN, ['job_id' => $jobId])->fetchAssociative();
        self::assertSame([$pageUid, $languageUid], [(int)($row['page_uid'] ?? -9), (int)($row['language_uid'] ?? -9)], 'stored provenance');
    }

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function crawlerSubmit(array $body): array
    {
        $jobId = sprintf('%08d-0000-4000-8000-%012d', ++$this->jobCounter, $this->jobCounter);
        $this->crawlerSubmits[] = $body + ['jobId' => $jobId];

        return [202, ['jobId' => $jobId, 'status' => 'queued', 'sourceType' => 'single_page']];
    }

    /** @return array<string, mixed> */
    private function startScan(int $beUserUid, int $pageUid, int $languageUid, string $key): array
    {
        $arguments = ['pageUid' => $pageUid, 'languageUid' => $languageUid];
        if ($key !== '') {
            $arguments['idempotencyKey'] = $key;
        }

        return $this->execute('aqg_start_scan', $arguments, $beUserUid);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function execute(string $tool, array $arguments, int $beUserUid): array
    {
        return $this->get(McpToolExecutor::class)->execute($tool, $arguments, [
            'tokenId' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'tokenSuffix' => 'synthetic', 'clientName' => 'I01 synthetic',
            'beUserUid' => $beUserUid, 'scopes' => ['findings:read', 'history:read', 'scans:run-page'], 'siteIdentifiers' => ['qa', 'other'],
            'expiresAt' => '2099-12-01T00:00:00Z',
        ]);
    }

    private function nativeSubmit(int $beUserUid, int $pageUid, int $languageUid): \Psr\Http\Message\ResponseInterface
    {
        $this->user($beUserUid);

        return $this->get(ProCrawlerAjaxController::class)->submitPageAction($this->post(['pageUid' => $pageUid, 'languageUid' => $languageUid]));
    }

    private function finishScan(string $jobId): void
    {
        $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)
            ->update(Tables::REMOTE_SCAN, ['status' => 'completed', 'finished_at' => time()], ['job_id' => $jobId]);
    }

    private function tableRows(string $table): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable($table)->count('*', $table, []);
    }

    private function refused(callable $call): McpCallRefused
    {
        try {
            $result = $call();
        } catch (McpCallRefused $refused) {
            return $refused;
        }
        self::fail('Expected a refusal, got: ' . json_encode($result));
    }

    private function assertRefusedWith(string $reason, callable $call): void
    {
        $refused = $this->refused($call);
        self::assertSame($reason, $refused->reason, $refused->getMessage());
    }

    private function user(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): ServerRequest
    {
        $request = (new ServerRequest('https://qa.example.invalid/typo3/ajax/a11y/test', 'POST'))->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
