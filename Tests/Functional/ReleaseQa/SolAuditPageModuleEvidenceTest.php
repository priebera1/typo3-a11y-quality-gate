<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\PageDetailController;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Service\PageModuleIndicatorService;
use Priebera\A11yQualityGate\Service\RemoteResultProvenanceService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SOL audit STATE-01 on the TYPO3 Page module panel and Content Page Detail, with the real services, repositories and the
 * rendered panel: a permission-filtered zero is never "No issues found" on its own.
 *
 * Page 2 of site `solpm`, content 10 on it. Users: 1 admin; 2 editor who may read pages and content; 3 editor who may read
 * pages but not content (findings of content 10 are hidden from them).
 */
final class SolAuditPageModuleEvidenceTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        foreach ([[1, 0, 'Root', '/'], [2, 1, 'Hours', '/hours'], [5, 1, 'Old owner', '/old']] as [$uid, $pid, $title, $slug]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $uid === 1 ? 1 : 0,
                'perms_everybody' => 31, 'perms_userid' => 99, 'perms_user' => 31,
            ]);
        }
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 2, 'header' => 'C10', 'CType' => 'text', 'bodytext' => '<a href="/x"></a>']);
        foreach ([[10, 'pages,tt_content'], [11, 'pages']] as [$uid, $tables]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'title' => 'G' . $uid, 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
                'tables_select' => $tables, 'tables_modify' => $tables, 'allowed_languages' => '',
            ]);
        }
        foreach ([[1, 1, ''], [2, 0, '10'], [3, 0, '11']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => $uid, 'username' => 'u' . $uid, 'admin' => $admin, 'usergroup' => $group]);
        }
        $this->get(SiteWriter::class)->write('solpm', ['rootPageId' => 1, 'base' => 'https://solpm.example.invalid/', 'languages' => [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
        ]]);
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->request();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function aGenuineVerifiedZeroIsCleanOnlyForAUserWhoSeesEveryResult(): void
    {
        $this->pageScan();
        $this->user(1);
        $admin = $this->panel();
        self::assertSame('ok', $admin['view']['rows'][0]['state'], 'positive control: administrator, complete page scan, no finding');
        self::assertStringContainsString('No issues found', $admin['html']);

        $this->user(2);
        $editor = $this->panel();
        self::assertSame('limited', $editor['view']['rows'][0]['state']);
        self::assertSame('limited', $editor['view']['overallState']);
        self::assertStringNotContainsString('No issues found', $editor['html']);
        self::assertStringContainsString('No open issues in the results you can access', $editor['html']);
    }

    #[Test]
    public function hiddenFindingsAreNeverACleanPageAndTheirNumberIsNotDisclosed(): void
    {
        $this->pageScan();
        $this->finding(10, 'tt_content');
        $this->user(3);
        // F9: content row `ok`, "No issues found" (raw total 1, visible 0).
        $editor = $this->panel();
        self::assertSame('limited', $editor['view']['rows'][0]['state']);
        self::assertStringNotContainsString('No issues found', $editor['html']);
        self::assertStringNotContainsString('1 warning', $editor['html']);
        self::assertSame([], $editor['view']['rows'][0]['counts']);
        $this->user(1);
        self::assertSame('warning', $this->panel()['view']['rows'][0]['state'], 'positive control');
    }

    #[Test]
    public function partiallyVisibleResultsShowTheReadableFindingsOnly(): void
    {
        $this->pageScan();
        $this->finding(10, 'tt_content');
        $this->finding(2, 'pages', 'link-name');
        $this->user(3);
        $editor = $this->panel();
        self::assertSame('warning', $editor['view']['rows'][0]['state']);
        self::assertStringContainsString('1 warning', $editor['view']['rows'][0]['headline']);
        $this->user(1);
        self::assertStringContainsString('2 warnings', $this->panel()['view']['rows'][0]['headline']);
    }

    #[Test]
    public function aPageWhoseRenderedCheckFailedOrThatASiteScanLeftOutIsNotFullyChecked(): void
    {
        $this->pageScan(['rendered_skipped' => 1, 'rendered_skipped_json' => json_encode([['pageUid' => 2, 'statusCode' => 0, 'message' => 'timeout', 'reason' => '']])]);
        $this->user(1);
        $panel = $this->panel();
        self::assertSame('limited', $panel['view']['rows'][0]['state']);
        self::assertStringContainsString('Not fully checked', $panel['html']);

        // A restricted site scan that left page 2 out does not cover it (AUTH-01 coverage).
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_scan')->truncate('tx_a11y_scan');
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_scan')->insert('tx_a11y_scan', [
            'site_identifier' => 'solpm', 'root_pid' => 1, 'language_uid' => 0, 'scope' => 'subtree', 'status' => 2,
            'started_at' => time() - 10, 'finished_at' => time(), 'pages_not_permitted' => 1, 'not_permitted_json' => '[2]',
        ]);
        self::assertNotSame('ok', $this->panel()['view']['rows'][0]['state']);
    }

    #[Test]
    public function theFreeRemotePreviewIsNotCleanForAReaderWhoCannotSeeItsFindings(): void
    {
        $this->pageScan();
        $this->remoteScan(1, 'page', true, 100, [[51, 'https://solpm.example.invalid/hours', 10]]);
        $this->user(1);
        self::assertSame('error', $this->panel()['view']['rows'][1]['state'], 'positive control: the administrator sees the stored finding');
        $this->user(3);
        // F9: the Free branch set the result page to null, so every restricted reader got `ok`.
        $editor = $this->panel();
        self::assertSame('limited', $editor['view']['rows'][1]['state']);
        self::assertStringNotContainsString('No issues found', $editor['html']);
        $this->user(2);
        self::assertSame('error', $this->panel()['view']['rows'][1]['state'], 'a reader of the content sees the Free finding');
    }

    #[Test]
    public function aCompletedScanWhoseResultsAreNotStoredIsPending(): void
    {
        $this->pageScan();
        $this->remoteScan(1, 'page', true, 0, []);
        $this->user(1);
        $panel = $this->panel();
        self::assertSame('limited', $panel['view']['rows'][1]['state'], 'F9: ok');
        self::assertNotSame('No issues found', $panel['view']['rows'][1]['headline']);
        self::assertSame('limited', $panel['view']['overallState'], 'the verified Content row alone does not make the panel clean');
        // Rendering retries the persistence; offline, the retry records a sync error: then the storing failed.
        $syncError = (string)$this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_scan')->select(['sync_error'], 'tx_a11y_remote_scan', ['uid' => 1])->fetchOne();
        self::assertStringContainsString($syncError === '' ? 'Results are being saved' : 'Results could not be saved', $panel['html']);
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['sync_error' => 'x'], ['uid' => 1]);
        self::assertStringContainsString('Results could not be saved', $this->panel()['html']);
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['sync_error' => '', 'persisted_at' => 100], ['uid' => 1]);
        self::assertSame('ok', $this->panel()['view']['rows'][1]['state'], 'positive control: stored, no page result rows, administrator');
    }

    #[Test]
    public function aNewerResultOfAnotherOwnerDoesNotHideAnOlderAuthorizedResultNorLeakItsReference(): void
    {
        $this->grantAgencyLicence();
        $this->pageScan();
        $this->remoteScan(1, 'site', false, 100, [[61, 'https://solpm.example.invalid/hours', 10]], 1_700_000_000);
        $this->remoteScan(2, 'site', false, 100, [[62, 'https://solpm.example.invalid/hours', 10]], 1_700_009_000);
        // Scan 2's result was recorded for page 5 (the URL belonged to it then): a reused URL.
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['owner_page_uid' => 5], ['uid' => 62]);

        $this->user(2);
        $editor = $this->panel();
        self::assertSame('error', $editor['view']['rows'][1]['state'], 'the older authorized result of this page is shown');
        $links = implode(' ', array_column($editor['view']['actions'], 'url'));
        self::assertStringNotContainsString('remotePageUid=62', $links, 'no reference to the result of another owner');

        // Only the other owner's result: never `ok`, and no link to it.
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['owner_page_uid' => 5], ['uid' => 61]);
        $editor = $this->panel();
        // F9: `ok`. A result the editor may not read is no result for them: its existence is not shown either.
        self::assertSame('none', $editor['view']['rows'][1]['state'], 'F9: wrong owner gave `ok`');
        $this->user(1);
        self::assertSame('error', $this->panel()['view']['rows'][1]['state'], 'positive control: the administrator sees the stored result');
        $this->user(2);
        $links = implode(' ', array_column($editor['view']['actions'], 'url'));
        self::assertStringNotContainsString('remotePageUid=6', $links);
    }

    #[Test]
    public function pageDetailSaysCleanOnlyForAVerifiedZero(): void
    {
        $this->pageScan();
        $this->finding(10, 'tt_content');
        $controller = $this->get(PageDetailController::class);
        $method = new \ReflectionMethod($controller, 'localEvidenceView');
        $this->user(3);
        $hidden = $method->invoke($controller, 'solpm', 2, 0);
        self::assertFalse($hidden['clean'], 'F9: "This page is clean"');
        self::assertSame('restricted', $hidden['state']);

        $this->getConnectionPool()->getConnectionForTable('tx_a11y_issue')->truncate('tx_a11y_issue');
        $this->user(1);
        self::assertTrue($method->invoke($controller, 'solpm', 2, 0)['clean'], 'positive control');
        self::assertFalse($method->invoke($controller, 'solpm', 2, 1)['clean'], 'no scan of this page in that language');

        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Templates/PageDetail/Show.html');
        self::assertStringContainsString('<f:if condition="{localDetail.hasScan} && {localEvidence.limited}">', $template, 'the clean block is behind the verified state');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array{view: array<string, mixed>, html: string} */
    private function panel(): array
    {
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('solpm');
        $service = $this->get(PageModuleIndicatorService::class);
        $view = $service->buildViewData(2, $site, 0);
        self::assertIsArray($view);

        return ['view' => $view, 'html' => (string)($service->buildState(2, $site, 0)['html'] ?? '')];
    }

    /** @param array<string, mixed> $overrides */
    private function pageScan(array $overrides = []): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_scan')->insert('tx_a11y_scan', $overrides + [
            'site_identifier' => 'solpm', 'root_pid' => 2, 'language_uid' => 0, 'scope' => 'page', 'status' => 2,
            'started_at' => time() - 20, 'finished_at' => time() - 10, 'pages_scanned' => 1, 'rendered_checked' => 1,
        ]);
    }

    private function finding(int $sourceUid, string $table, string $rule = 'link-text'): void
    {
        $this->insertIssue([
            'site_identifier' => 'solpm', 'page_uid' => 2, 'source_lang_uid' => 0, 'source_table' => $table, 'source_uid' => $sourceUid,
            'source_field' => $table === 'pages' ? 'title' : 'bodytext', 'rule_id' => $rule, 'severity' => 2, 'status' => 0,
            'fingerprint' => sha1($table . $sourceUid . $rule),
        ]);
    }

    /** @param list<array{0:int,1:string,2:int}> $results result page uid, URL, mapped content uid */
    private function remoteScan(int $uid, string $scope, bool $free, int $persistedAt, array $results, int $finishedAt = 1_700_000_000): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => $uid, 'site_identifier' => 'solpm', 'job_id' => 'pm-' . $uid, 'scan_scope' => $scope, 'language_uid' => 0,
            'page_uid' => $scope === 'page' ? 2 : 0, 'status' => 'completed', 'is_free_preview' => $free ? 1 : 0, 'persisted_at' => $persistedAt,
            'pages_scanned' => 1, 'pages_total' => 1, 'issues_total' => count($results), 'crdate' => $finishedAt, 'finished_at' => $finishedAt,
            'start_url' => 'https://solpm.example.invalid/hours',
        ]);
        foreach ($results as [$pageRow, $url, $content]) {
            $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', ['uid' => $pageRow, 'remote_scan' => $uid, 'url' => $url, 'title' => 'Hours', 'http_status' => 200, 'issues_count' => 1]);
            $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', ['uid' => $pageRow, 'remote_scan' => $uid, 'remote_scan_page' => $pageRow, 'rule_id' => 'link-name', 'impact' => 'serious', 'nodes_count' => 1]);
            $pool->getConnectionForTable('tx_a11y_remote_issue_node')->insert('tx_a11y_remote_issue_node', ['uid' => $pageRow, 'remote_issue' => $pageRow, 'mapped_table' => 'tt_content', 'mapped_uid' => $content, 'html_snippet' => '<a href="/x"></a>']);
        }
        if ($results !== []) {
            $this->get(RemoteResultProvenanceService::class)->stampScan($uid, 'solpm');
        }
    }

    private function grantAgencyLicence(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['licenceKey'] = 'aqg_live_' . str_repeat('6', 32);
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('solpm');
        $resolver = $this->get(ProStatusResolverService::class);
        $licence = $this->get(ProLicenceService::class);
        foreach ((new \ReflectionMethod($resolver, 'collectCandidateDomainsForSite'))->invoke($resolver, $site) as $domain) {
            $sites = $this->get(ProSiteFingerprintService::class)->collectValidationSites($domain, false);
            $key = (new \ReflectionMethod($licence, 'buildCacheKey'))->invoke($licence, $domain, $sites);
            $this->get(ProCacheManager::class)->setLicenceResult($key, new LicenceValidationResult(valid: true, plan: 'agency', features: ['crawler', 'export_pdf', 'multi_site']), 3600, 3600);
        }
        self::assertTrue((bool)$resolver->resolveForSite($site instanceof Site ? $site : null)->hasCrawler, 'fixture: paid entitlement');
    }

    private function request(): void
    {
        $request = (new ServerRequest('https://solpm.example.invalid/typo3/module/web/layout'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $GLOBALS['TYPO3_REQUEST'] = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    private function user(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }
}
