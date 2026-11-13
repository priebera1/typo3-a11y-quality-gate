<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Security;

use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController;
use Priebera\A11yQualityGate\Database\Tables;
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
 * SOL Hetzner preflight P1-01 / P1-02: admission of a single-page scan (MCP `aqg_start_scan` and the native "Scan this
 * page" routes) re-checks the CURRENT effective rights on every call — site, page mount, page edit/show permission,
 * language and the page's current live translation record — before an idempotent replay is answered and before any
 * crawler job is requested. A refusal never names the earlier job, the resolved URL or the translation.
 *
 * The AQG licence API and crawler are scripted in-process (no request leaves the test); every crawler submit is counted.
 *
 * Pages: 1 site root, 2 base page "/open" with live German translation 4 "/offen-geheim", 6 another default page.
 * Users: 1 administrator; 2 editor (languages 0,1); 3 editor of the same group, used for per-user changes.
 */
final class McpScanAdmissionAuthorizationTest extends AbstractFunctionalTestCase
{
    private const TRANSLATED_SLUG = '/offen-geheim';

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
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['licenceKey'] = 'aqg_live_' . str_repeat('s', 32);
        $GLOBALS['TYPO3_CONF_VARS']['HTTP']['handler'] = HandlerStack::create(function (RequestInterface $request, array $options) {
            $path = $request->getUri()->getPath();
            $this->outboundPaths[] = $request->getUri()->getHost() . $path;
            $body = json_decode((string)$request->getBody(), true) ?: [];
            $answer = match (true) {
                str_ends_with($path, '/licence/validate') => [200, ['success' => true, 'valid' => true, 'plan' => 'pro', 'features' => ['crawler']]],
                str_ends_with($path, '/auth/token') => [200, ['access_token' => 'synthetic-access-token', 'expires_in' => 3600, 'plan' => 'pro', 'features' => ['crawler'], 'entitlement' => 'paid']],
                str_ends_with($path, '/crawl/submit') => $this->crawlerSubmit($body, $request->getHeaderLine('Idempotency-Key')),
                default => [404, ['success' => false, 'error' => ['code' => 'route_not_found']]],
            };

            return Create::promiseFor(new Response($answer[0], ['Content-Type' => 'application/json'], (string)json_encode($answer[1])));
        });

        $pool = $this->getConnectionPool();
        foreach ([
            [1, 0, 'P1 root', '/', 0, 0],
            [2, 1, 'P1 base page', '/open', 0, 0],
            [4, 1, 'P1 German translation', self::TRANSLATED_SLUG, 1, 2],
            [6, 1, 'P1 other page', '/other', 0, 0],
        ] as [$uid, $pid, $title, $slug, $lang, $parent]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $uid === 1 ? 1 : 0,
                'perms_everybody' => 31, 'perms_userid' => 99, 'perms_user' => 31, 'sys_language_uid' => $lang, 'l10n_parent' => $parent,
            ]);
        }
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'title' => 'P1 editors', 'groupMods' => 'web,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => '0,1',
            'TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1',
        ]);
        foreach ([[1, 1, ''], [2, 0, '10'], [3, 0, '10']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => 'p1user' . $uid, 'admin' => $admin, 'usergroup' => $group, 'workspace_perms' => 1,
                'TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1',
            ]);
        }
        $this->get(SiteWriter::class)->write('qa', ['rootPageId' => 1, 'base' => 'https://qa.example.invalid/', 'languages' => [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
        ]]);
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->get(McpSiteSettingsRepository::class)->save('qa', true, ['findings:read', 'history:read', 'scans:run-page'], 1);
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

    // ---- positive baseline -----------------------------------------------------------------------------------------

    #[Test]
    public function anAuthorizedRetryWithTheSameKeyReturnsTheOriginalJobAndCreatesOneCrawlerJob(): void
    {
        $first = $this->startScan(2, 2, 1, 'p1-key-authorized-01');
        self::assertSame('submitted', $first['status']);
        $jobId = $first['scan']['jobId'];
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $jobId);
        self::assertCount(1, $this->crawlerSubmits);
        self::assertStringEndsWith('/de' . self::TRANSLATED_SLUG, (string)$this->crawlerSubmits[0]['startUrl'], 'the translated page is scanned');

        $this->finishScan($jobId);
        $retry = $this->startScan(2, 2, 1, 'p1-key-authorized-01');
        self::assertSame('replayed', $retry['status']);
        self::assertSame($jobId, $retry['scan']['jobId']);
        self::assertCount(1, $this->crawlerSubmits, 'an authorized replay never submits again');
        self::assertSame('completed', $this->execute('aqg_get_scan_status', ['siteIdentifier' => 'qa', 'jobId' => $jobId], 2)['scan']['status']);
    }

    #[Test]
    public function aReplayWhoseRunIsNoLongerStoredNamesNoJob(): void
    {
        $jobId = $this->startScan(2, 2, 1, 'p1-key-gone-01')['scan']['jobId'];
        $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)->delete(Tables::REMOTE_SCAN, ['job_id' => $jobId]);

        $refusal = $this->refusedWith('scan_not_found', fn () => $this->startScan(2, 2, 1, 'p1-key-gone-01'));
        $this->assertNoMetadata($refusal, $jobId);
        self::assertCount(1, $this->crawlerSubmits, 'the reservation is never resubmitted as a new job');
    }

    // ---- P1-01: replay after the language was removed -------------------------------------------------------------

    #[Test]
    public function removingTheLanguageRefusesTheOldKeyAndAFreshKeyWithoutNamingTheJob(): void
    {
        $jobId = $this->startScan(2, 2, 1, 'p1-key-language-01')['scan']['jobId'];
        $this->finishScan($jobId);
        $this->setGroup(['allowed_languages' => '0']);

        $replay = $this->refused(fn () => $this->startScan(2, 2, 1, 'p1-key-language-01'));
        $fresh = $this->refused(fn () => $this->startScan(2, 2, 1, 'p1-key-language-02'));
        $noKey = $this->refused(fn () => $this->startScan(2, 2, 1, ''));
        $this->assertSameUndisclosingRefusal([$replay, $fresh, $noKey], $jobId);
        self::assertCount(1, $this->crawlerSubmits, 'no crawler job after the language was removed');
        $this->assertRefusedWith('scan_not_found', fn () => $this->execute('aqg_get_scan_status', ['siteIdentifier' => 'qa', 'jobId' => $jobId], 2));
        $this->assertRefusedWith('scan_not_found', fn () => $this->execute('aqg_get_scan_results', ['siteIdentifier' => 'qa', 'jobId' => $jobId], 2));

        // Restored: the original key reaches the original run again, without a second job.
        $this->setGroup(['allowed_languages' => '0,1']);
        $restored = $this->startScan(2, 2, 1, 'p1-key-language-01');
        self::assertSame(['replayed', $jobId], [$restored['status'], $restored['scan']['jobId']]);
        self::assertCount(1, $this->crawlerSubmits);
    }

    // ---- P1-02: the current live translation record decides -------------------------------------------------------

    #[Test]
    public function aDeniedLiveTranslationRefusesAFreshScanBeforeAnyCrawlerJob(): void
    {
        $this->denyTranslation(4);
        $refusal = $this->refused(fn () => $this->startScan(2, 2, 1, 'p1-key-translation-01'));
        $this->assertSameUndisclosingRefusal([$refusal], '');
        self::assertSame([], $this->crawlerSubmits, 'refused before the crawler is asked');
        self::assertSame(0, $this->tableRows(Tables::REMOTE_SCAN), 'no local scan row');
        self::assertSame(0, $this->tableRows(Tables::MCP_SCAN_REQUEST), 'refused before the idempotency key is reserved');
        // The base page and language alone still admit the default language.
        self::assertSame('submitted', $this->startScan(2, 2, 0, 'p1-key-translation-02')['status']);
        self::assertCount(1, $this->crawlerSubmits);

        $this->allowTranslation(4);
        $this->finishScan((string)$this->crawlerSubmits[0]['jobId']);
        self::assertSame('submitted', $this->startScan(2, 2, 1, 'p1-key-translation-01')['status'], 'restored: the same key now starts the scan');
        self::assertCount(2, $this->crawlerSubmits);
    }

    #[Test]
    public function aTranslationDeniedAfterSubmissionRefusesTheReplayWithoutMetadata(): void
    {
        $jobId = $this->startScan(2, 2, 1, 'p1-key-translation-03')['scan']['jobId'];
        $this->finishScan($jobId);
        $this->denyTranslation(4);

        $replay = $this->refused(fn () => $this->startScan(2, 2, 1, 'p1-key-translation-03'));
        $fresh = $this->refused(fn () => $this->startScan(2, 2, 1, 'p1-key-translation-04'));
        $this->assertSameUndisclosingRefusal([$replay, $fresh], $jobId);
        self::assertCount(1, $this->crawlerSubmits);
        $this->assertRefusedWith('scan_not_found', fn () => $this->execute('aqg_get_scan_status', ['siteIdentifier' => 'qa', 'jobId' => $jobId], 2));

        $this->allowTranslation(4);
        self::assertSame(['replayed', $jobId], [($r = $this->startScan(2, 2, 1, 'p1-key-translation-03'))['status'], $r['scan']['jobId']]);
        self::assertCount(1, $this->crawlerSubmits);
    }

    #[Test]
    public function aShowOnlyTranslationIsNotEnoughToScanIt(): void
    {
        // Scanning acts on the page (CLAUDE.md: scanning needs edit access); the translation record has its own rights.
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 1], ['uid' => 4]);
        $this->assertSameUndisclosingRefusal([$this->refused(fn () => $this->startScan(2, 2, 1, 'p1-key-showonly-01'))], '');
        self::assertSame([], $this->crawlerSubmits);
    }

    #[Test]
    public function theNativeSubmitRoutesRefuseADeniedLiveTranslation(): void
    {
        $this->denyTranslation(4);
        $controller = $this->get(ProCrawlerAjaxController::class);
        $this->user(2);
        $page = $controller->submitPageAction($this->post(['pageUid' => 2, 'languageUid' => 1]));
        self::assertSame(403, $page->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Access denied'], json_decode((string)$page->getBody(), true));
        self::assertSame([], $this->crawlerSubmits);

        // Free Remote Preview of the same translated page (no licence): refused before any Free request.
        unset($GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['licenceKey']);
        $this->user(2);
        $free = $controller->submitSiteAction($this->post(['rootPid' => 1, 'pageUid' => 2, 'languageUid' => 1, 'freeSubmitIntent' => 'x']));
        self::assertSame(403, $free->getStatusCode());
        self::assertSame([], array_values(array_filter($this->outboundPaths, static fn (string $p): bool => str_contains($p, 'free'))));
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['licenceKey'] = 'aqg_live_' . str_repeat('s', 32);

        // Positive controls: the same user, the translation allowed again; and the default language meanwhile.
        $this->user(2);
        $default = $controller->submitPageAction($this->post(['pageUid' => 2, 'languageUid' => 0]));
        self::assertSame(200, $default->getStatusCode(), (string)$default->getBody());
        $this->finishScan((string)$this->crawlerSubmits[0]['jobId']);
        $this->allowTranslation(4);
        $this->user(2);
        $translated = $controller->submitPageAction($this->post(['pageUid' => 2, 'languageUid' => 1]));
        self::assertSame(200, $translated->getStatusCode(), (string)$translated->getBody());
        self::assertCount(2, $this->crawlerSubmits);
    }

    #[Test]
    public function aStoredResultUrlIsAdmittedByItsOwnerTranslationNotByTheSiteRoot(): void
    {
        // Independent review P2: a stored site-crawl result page without mapped elements maps to the site root, but its
        // stored URL is the denied translation's. Scanning it through the root's edit right must be refused.
        $this->storedTranslatedResult();
        $this->denyTranslation(4);
        $controller = $this->get(ProCrawlerAjaxController::class);
        $this->user(2);
        $refused = $controller->submitPageAction($this->post(['pageUid' => 1, 'remotePageUid' => 60]));
        self::assertSame(403, $refused->getStatusCode(), (string)$refused->getBody());
        self::assertStringNotContainsString('geheim', (string)$refused->getBody());
        self::assertSame([], $this->crawlerSubmits);

        // The owner recorded nowhere (legacy row): the URL's own page decides.
        $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN_PAGE)->update(Tables::REMOTE_SCAN_PAGE, ['owner_state' => 0, 'owner_page_uid' => 0, 'owner_translation_uid' => 0], ['uid' => 60]);
        $this->user(2);
        self::assertSame(403, $controller->submitPageAction($this->post(['pageUid' => 1, 'remotePageUid' => 60]))->getStatusCode());
        self::assertSame([], $this->crawlerSubmits);

        $this->allowTranslation(4);
        $this->user(2);
        $allowed = $controller->submitPageAction($this->post(['pageUid' => 1, 'remotePageUid' => 60]));
        self::assertSame(200, $allowed->getStatusCode(), (string)$allowed->getBody());
        self::assertCount(1, $this->crawlerSubmits);
    }

    #[Test]
    public function aStoredUrlIsAdmittedInItsOwnLanguageNotTheScansLanguage(): void
    {
        // A default-language crawl that stored a link into the German translation: the translation decides.
        $this->storedTranslatedResult();
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable(Tables::REMOTE_SCAN)->update(Tables::REMOTE_SCAN, ['language_uid' => 0], ['uid' => 50]);
        $this->denyTranslation(4);
        $controller = $this->get(ProCrawlerAjaxController::class);
        $this->user(2);
        self::assertSame(403, $controller->submitPageAction($this->post(['pageUid' => 1, 'remotePageUid' => 60]))->getStatusCode(), 'recorded owner language');
        $pool->getConnectionForTable(Tables::REMOTE_SCAN_PAGE)->update(Tables::REMOTE_SCAN_PAGE, ['owner_state' => 2, 'owner_page_uid' => 0, 'owner_language_uid' => -1, 'owner_translation_uid' => 0], ['uid' => 60]);
        $this->user(2);
        self::assertSame(403, $controller->submitPageAction($this->post(['pageUid' => 1, 'remotePageUid' => 60]))->getStatusCode(), 'language from the URL base');
        self::assertSame([], $this->crawlerSubmits);

        // A URL that maps to no TYPO3 page (e.g. an extension route under the denied translation): its content cannot be
        // bound to page or translation rights, so only administrators re-scan it (owner decision), whatever the
        // editor's languages and site-root rights.
        $pool->getConnectionForTable(Tables::REMOTE_SCAN_PAGE)->update(Tables::REMOTE_SCAN_PAGE, ['url' => 'https://qa.example.invalid/de/news/detail/p1'], ['uid' => 60]);
        foreach (['0', '0,1'] as $languages) {
            $this->setGroup(['allowed_languages' => $languages]);
            $this->user(2);
            $refused = $controller->submitPageAction($this->post(['pageUid' => 1, 'remotePageUid' => 60]));
            self::assertSame(403, $refused->getStatusCode(), $languages);
            self::assertStringNotContainsString('news/detail', (string)$refused->getBody());
        }
        self::assertSame([], $this->crawlerSubmits);
        $this->user(1);
        $admin = $controller->submitPageAction($this->post(['pageUid' => 1, 'remotePageUid' => 60]));
        self::assertSame(200, $admin->getStatusCode(), (string)$admin->getBody());
        self::assertStringEndsWith('/de/news/detail/p1', (string)$this->crawlerSubmits[0]['startUrl']);
    }

    #[Test]
    public function aStoredUrlOfTheEditorsOwnPageIsStillRescanned(): void
    {
        // Positive control for the stored-URL path: a mapped default-language result page of the editor's own page.
        $this->storedTranslatedResult();
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable(Tables::REMOTE_SCAN)->update(Tables::REMOTE_SCAN, ['language_uid' => 0], ['uid' => 50]);
        $pool->getConnectionForTable(Tables::REMOTE_SCAN_PAGE)->update(Tables::REMOTE_SCAN_PAGE, [
            'url' => 'https://qa.example.invalid/open', 'owner_state' => 2, 'owner_page_uid' => 0, 'owner_language_uid' => -1, 'owner_translation_uid' => 0,
        ], ['uid' => 60]);
        $this->user(2);
        $response = $this->get(ProCrawlerAjaxController::class)->submitPageAction($this->post(['pageUid' => 1, 'remotePageUid' => 60]));
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertStringEndsWith('/open', (string)$this->crawlerSubmits[0]['startUrl']);
    }

    #[Test]
    public function verifyFixNeedsEditAccessToTheFindingsTranslation(): void
    {
        $this->storedTranslatedResult();
        $controller = $this->get(ProCrawlerAjaxController::class);
        // Readable, not editable: the finding is visible, but re-scanning the translation is not allowed.
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 1], ['uid' => 4]);
        $this->user(2);
        $refused = $controller->verifyFixAction($this->post(['findingId' => 71]));
        self::assertNotSame(200, $refused->getStatusCode(), (string)$refused->getBody());
        self::assertSame([], $this->crawlerSubmits);

        $this->allowTranslation(4);
        $this->user(2);
        $allowed = $controller->verifyFixAction($this->post(['findingId' => 71]));
        self::assertSame(200, $allowed->getStatusCode(), (string)$allowed->getBody());
        self::assertCount(1, $this->crawlerSubmits);
        self::assertStringEndsWith('/de' . self::TRANSLATED_SLUG, (string)$this->crawlerSubmits[0]['startUrl']);
    }

    #[Test]
    public function aDeniedDuplicateTranslationRowRefusesTheScan(): void
    {
        // Broken data: two live German rows. The readable lowest uid must not open the other one, whichever slug the URL
        // resolver picks.
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 8, 'pid' => 1, 'title' => 'P1 duplicate', 'slug' => '/doppelt-geheim', 'doktype' => 1, 'perms_everybody' => 0,
            'perms_userid' => 99, 'perms_user' => 31, 'sys_language_uid' => 1, 'l10n_parent' => 2, 'hidden' => 0,
        ]);
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['hidden' => 1], ['uid' => 4]);
        $this->assertSameUndisclosingRefusal([$this->refused(fn () => $this->startScan(2, 2, 1, 'p1-key-dup-01'))], '');
        self::assertSame([], $this->crawlerSubmits);
        $this->allowTranslation(8);
        self::assertSame('submitted', $this->startScan(2, 2, 1, 'p1-key-dup-01')['status']);
    }

    // ---- translation lifecycle ------------------------------------------------------------------------------------

    #[Test]
    public function aDeniedReplacementTranslationRefusesAndAReadableOneIsScanned(): void
    {
        $jobId = $this->startScan(2, 2, 1, 'p1-key-replace-01')['scan']['jobId'];
        $this->finishScan($jobId);
        // The translation is deleted and a new, denied one takes its place (a readable deleted "historical" row stays).
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->update('pages', ['deleted' => 1], ['uid' => 4]);
        $pages->insert('pages', [
            'uid' => 5, 'pid' => 1, 'title' => 'P1 replacement', 'slug' => '/ersatz-geheim', 'doktype' => 1, 'perms_everybody' => 0,
            'perms_userid' => 99, 'perms_user' => 31, 'sys_language_uid' => 1, 'l10n_parent' => 2,
        ]);
        self::assertSame(5, $this->get(ScopeAccessService::class)->liveTranslationUid(2, 1));

        $replay = $this->refused(fn () => $this->startScan(2, 2, 1, 'p1-key-replace-01'));
        $fresh = $this->refused(fn () => $this->startScan(2, 2, 1, 'p1-key-replace-02'));
        $this->assertSameUndisclosingRefusal([$replay, $fresh], $jobId);
        self::assertStringNotContainsString('ersatz', $replay->getMessage() . $fresh->getMessage());
        self::assertCount(1, $this->crawlerSubmits);

        // The replacement made readable: scanned under its own slug, never the deleted translation's.
        $pages->update('pages', ['perms_everybody' => 31], ['uid' => 5]);
        self::assertSame('submitted', $this->startScan(2, 2, 1, 'p1-key-replace-02')['status']);
        self::assertStringEndsWith('/de/ersatz-geheim', (string)$this->crawlerSubmits[1]['startUrl']);
    }

    #[Test]
    public function aDeletedTranslationFallsBackToTheBasePageAndAMovedOneFollowsItsNewParent(): void
    {
        // Deleted: the page has no translation in that language; the base page (readable, editable) decides, and the
        // deleted translation's slug is never sent.
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->update('pages', ['deleted' => 1, 'perms_everybody' => 0], ['uid' => 4]);
        self::assertSame('submitted', $this->startScan(2, 2, 1, 'p1-key-deleted-01')['status']);
        self::assertStringNotContainsString('geheim', (string)$this->crawlerSubmits[0]['startUrl']);
        $this->finishScan((string)$this->crawlerSubmits[0]['jobId']);

        // Moved to another parent, denied there: the old parent no longer has it; the new parent refuses it.
        $pages->update('pages', ['deleted' => 0, 'l10n_parent' => 6], ['uid' => 4]);
        self::assertSame('submitted', $this->startScan(2, 2, 1, 'p1-key-moved-01')['status']);
        self::assertStringNotContainsString('geheim', (string)$this->crawlerSubmits[1]['startUrl']);
        $this->finishScan((string)$this->crawlerSubmits[1]['jobId']);
        $this->assertSameUndisclosingRefusal([$this->refused(fn () => $this->startScan(2, 6, 1, 'p1-key-moved-02'))], '');
        self::assertCount(2, $this->crawlerSubmits);
    }

    #[Test]
    public function aWorkspaceOnlyTranslationIsNeitherScannedNorAuthorizing(): void
    {
        // Only a workspace version exists in German: the live site renders the base page, so the workspace slug must not
        // become the crawler's start URL.
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        $pages->update('pages', ['deleted' => 1], ['uid' => 4]);
        $pages->insert('pages', [
            'uid' => 7, 'pid' => 1, 'title' => 'P1 draft translation', 'slug' => '/entwurf-geheim', 'doktype' => 1, 'perms_everybody' => 31,
            'sys_language_uid' => 1, 'l10n_parent' => 2, 't3ver_wsid' => 1, 't3ver_state' => 1,
        ]);
        self::assertSame('submitted', $this->startScan(2, 2, 1, 'p1-key-ws-01')['status']);
        self::assertStringNotContainsString('entwurf', (string)$this->crawlerSubmits[0]['startUrl']);
    }

    // ---- other boundaries -----------------------------------------------------------------------------------------

    #[Test]
    public function mountPagePermissionSiteCapabilityAndScopeAreRecheckedOnReplay(): void
    {
        $jobId = $this->startScan(3, 2, 1, 'p1-key-boundary-01')['scan']['jobId'];
        $this->finishScan($jobId);
        $retry = fn () => $this->startScan(3, 2, 1, 'p1-key-boundary-01');
        $pool = $this->getConnectionPool();

        // Page mount removed.
        $pool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '', 'db_mountpoints' => '6'], ['uid' => 3]);
        $pool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '11'], ['uid' => 3]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 11, 'title' => 'P1 other mount', 'groupMods' => 'web,web_a11y', 'db_mountpoints' => '6',
            'tables_select' => 'pages', 'tables_modify' => 'pages', 'allowed_languages' => '0,1', 'TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1',
        ]);
        $this->assertNoMetadata($this->refusedWith('page_not_found', $retry), $jobId);
        $pool->getConnectionForTable('be_users')->update('be_users', ['usergroup' => '10'], ['uid' => 3]);
        self::assertSame('replayed', $retry()['status']);

        // Page permission removed (show only): no edit access.
        $pool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 1], ['uid' => 2]);
        $this->assertNoMetadata($this->refusedWith('permission_denied', $retry), $jobId);
        $pool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 31], ['uid' => 2]);

        // Scan now removed.
        $this->setGroup(['TSconfig' => "options.a11y_quality_gate.mcp.connect = 1\noptions.a11y_quality_gate.showScanNow = 0"]);
        $this->assertNoMetadata($this->refusedWith('permission_denied', $retry), $jobId);
        $this->setGroup(['TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1']);

        // Site capability disabled, then agent access off for the site.
        $settings = $this->get(McpSiteSettingsRepository::class);
        $settings->save('qa', true, ['findings:read', 'history:read'], 1);
        $this->assertNoMetadata($this->refusedWith('capability_off', $retry), $jobId);
        $settings->save('qa', false, ['findings:read', 'history:read', 'scans:run-page'], 1);
        $this->assertNoMetadata($this->refusedWith('site_not_available', $retry), $jobId);
        $settings->save('qa', true, ['findings:read', 'history:read', 'scans:run-page'], 1);

        // Token scope without scans:run-page.
        $this->assertNoMetadata($this->refusedWith('scope_not_granted', fn () => $this->execute('aqg_start_scan', ['pageUid' => 2, 'languageUid' => 1, 'idempotencyKey' => 'p1-key-boundary-01'], 3, ['findings:read', 'history:read'])), $jobId);

        self::assertSame(['replayed', $jobId], [($r = $retry())['status'], $r['scan']['jobId']], 'every right restored: replay works');
        self::assertCount(1, $this->crawlerSubmits);
    }

    #[Test]
    public function theDefaultLanguageFollowsTheLanguagePolicyAndAdministratorsScanEverything(): void
    {
        $this->setGroup(['allowed_languages' => '1']);
        $this->assertSameUndisclosingRefusal([$this->refused(fn () => $this->startScan(2, 2, 0, 'p1-key-lang0-01'))], '');
        self::assertSame([], $this->crawlerSubmits, 'language 0 excluded (S-01)');
        $this->setGroup(['allowed_languages' => '0']);
        self::assertSame('submitted', $this->startScan(2, 2, 0, 'p1-key-lang0-01')['status']);
        $this->finishScan((string)$this->crawlerSubmits[0]['jobId']);

        $this->denyTranslation(4);
        $admin = $this->startScan(1, 2, 1, 'p1-key-admin-01');
        self::assertSame('submitted', $admin['status'], 'administrators read and scan every translation');
        $this->finishScan($admin['scan']['jobId']);
        self::assertSame('replayed', $this->startScan(1, 2, 1, 'p1-key-admin-01')['status']);
        self::assertCount(2, $this->crawlerSubmits);
    }

    // ---- helpers --------------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function crawlerSubmit(array $body, string $idempotencyKey): array
    {
        // The crawler's own idempotency: the same key returns the run it started.
        foreach ($this->crawlerSubmits as $submit) {
            if ($idempotencyKey !== '' && $submit['idempotencyKey'] === $idempotencyKey) {
                return [200, ['jobId' => $submit['jobId'], 'status' => 'queued', 'sourceType' => 'single_page', 'replayed' => true]];
            }
        }
        $jobId = sprintf('%08d-0000-4000-8000-%012d', ++$this->jobCounter, $this->jobCounter);
        $this->crawlerSubmits[] = $body + ['jobId' => $jobId, 'idempotencyKey' => $idempotencyKey];

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
     * @param list<string> $scopes
     * @return array<string, mixed>
     */
    private function execute(string $tool, array $arguments, int $beUserUid, array $scopes = ['findings:read', 'history:read', 'scans:run-page']): array
    {
        return $this->get(McpToolExecutor::class)->execute($tool, $arguments, [
            'tokenId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'tokenSuffix' => 'synthetic', 'clientName' => 'P1 synthetic',
            'beUserUid' => $beUserUid, 'scopes' => $scopes, 'siteIdentifiers' => ['qa'], 'expiresAt' => '2099-12-01T00:00:00Z',
        ]);
    }

    /**
     * A completed German site crawl of the translated page: result page 60 without mapped elements (maps to the site
     * root) and result page 61 with a finding (71) on a German content element of page 2; owners stamped as on persist.
     */
    private function storedTranslatedResult(): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 500, 'pid' => 2, 'CType' => 'text', 'header' => 'P1 German content', 'sys_language_uid' => 1]);
        $pool->getConnectionForTable(Tables::REMOTE_SCAN)->insert(Tables::REMOTE_SCAN, [
            'uid' => 50, 'site_identifier' => 'qa', 'job_id' => 'eeeeeeee-0000-4000-8000-000000000050', 'scan_scope' => 'site', 'source_type' => 'crawl',
            'language_uid' => 1, 'status' => 'completed', 'persisted_at' => time(), 'finished_at' => time(), 'crdate' => time(),
            'start_url' => 'https://qa.example.invalid/de/',
        ]);
        foreach ([60, 61] as $uid) {
            $pool->getConnectionForTable(Tables::REMOTE_SCAN_PAGE)->insert(Tables::REMOTE_SCAN_PAGE, [
                'uid' => $uid, 'remote_scan' => 50, 'url' => 'https://qa.example.invalid/de' . self::TRANSLATED_SLUG, 'title' => 'P1 German', 'http_status' => 200,
                'owner_state' => 1, 'owner_page_uid' => 2, 'owner_language_uid' => 1, 'owner_translation_uid' => 4,
            ]);
        }
        $pool->getConnectionForTable(Tables::REMOTE_ISSUE)->insert(Tables::REMOTE_ISSUE, [
            'uid' => 71, 'remote_scan' => 50, 'remote_scan_page' => 61, 'rule_id' => 'image-alt', 'impact' => 'serious', 'help' => 'Images must have alt text', 'nodes_count' => 1,
        ]);
        $pool->getConnectionForTable(Tables::REMOTE_ISSUE_NODE)->insert(Tables::REMOTE_ISSUE_NODE, [
            'remote_issue' => 71, 'target_json' => '["img"]', 'html_snippet' => '<img src="x">', 'mapped_table' => 'tt_content', 'mapped_uid' => 500,
        ]);
    }

    /** Marks a stored scan finished, so the one-active-scan-per-site rule does not decide the next call. */
    private function finishScan(string $jobId): void
    {
        $this->getConnectionPool()->getConnectionForTable(Tables::REMOTE_SCAN)
            ->update(Tables::REMOTE_SCAN, ['status' => 'completed', 'finished_at' => time()], ['job_id' => $jobId]);
    }

    private function denyTranslation(int $uid): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => $uid]);
    }

    private function allowTranslation(int $uid): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 31], ['uid' => $uid]);
    }

    /** @param array<string, mixed> $fields */
    private function setGroup(array $fields): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', $fields, ['uid' => 10]);
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

    private function refusedWith(string $reason, callable $call): McpCallRefused
    {
        $refused = $this->refused($call);
        self::assertSame($reason, $refused->reason, $refused->getMessage());

        return $refused;
    }

    private function assertRefusedWith(string $reason, callable $call): void
    {
        $this->refusedWith($reason, $call);
    }

    /**
     * Every refusal of a target the user may no longer scan is the same `permission_denied` sentence, whether an earlier
     * run exists for the key or not, and names no job, URL, slug or translation.
     *
     * @param list<McpCallRefused> $refusals
     */
    private function assertSameUndisclosingRefusal(array $refusals, string $jobId): void
    {
        foreach ($refusals as $refusal) {
            self::assertSame('permission_denied', $refusal->reason, $refusal->getMessage());
            self::assertSame($refusals[0]->getMessage(), $refusal->getMessage(), 'replay and fresh refusals are indistinguishable');
            $this->assertNoMetadata($refusal, $jobId);
        }
    }

    private function assertNoMetadata(McpCallRefused $refusal, string $jobId): void
    {
        $text = $refusal->reason . ' ' . $refusal->getMessage();
        if ($jobId !== '') {
            self::assertStringNotContainsString($jobId, $text);
        }
        foreach (['geheim', 'qa.example.invalid', '/de/', 'replayed', 'jobId', 'translation'] as $needle) {
            self::assertStringNotContainsString($needle, $text);
        }
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
