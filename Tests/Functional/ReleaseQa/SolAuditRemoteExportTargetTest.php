<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ExportController;
use Priebera\A11yQualityGate\Export\PdfGenerator;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Service\RemoteResultProvenanceService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * SOL audit AUTH-03 (F9): the frontend CSV/PDF export resolves one report target, authorizes exactly that scan (site,
 * scope, paid kind, language, current rights) and renders only it. An explicitly named scan that is invalid or refused is
 * refused, never replaced by a fallback; a fallback scan is authorized on its own.
 *
 * Scans of site `solx`: 1 = site scan language 0 (completed), 2 = site scan language 1 (completed, newest), 3 = site scan
 * language 0 still running, 4 = a Free Remote Preview page scan. Users: 1 admin, 2 editor of 0+1, 5 editor of language 0.
 */
final class SolAuditRemoteExportTargetTest extends AbstractFunctionalTestCase
{
    private const FORBIDDEN_FINISHED_AT = 1_788_888_888;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        foreach ([[1, 0, 'Root', '/', 0, 0], [2, 1, 'Open', '/open', 0, 0], [20, 1, 'Offen', '/open', 1, 2]] as [$uid, $pid, $title, $slug, $lang, $parent]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $uid === 1 ? 1 : 0,
                'perms_everybody' => 31, 'perms_userid' => 99, 'perms_user' => 31, 'sys_language_uid' => $lang, 'l10n_parent' => $parent,
            ]);
        }
        foreach ([[10, 2, 0], [11, 2, 1]] as [$uid, $pid, $lang]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => $uid, 'pid' => $pid, 'header' => 'C' . $uid, 'CType' => 'text', 'sys_language_uid' => $lang]);
        }
        foreach ([[10, '0'], [12, '0,1']] as [$uid, $langs]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'title' => 'G' . $uid, 'groupMods' => 'web,web_a11y', 'db_mountpoints' => '1',
                'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => $langs,
            ]);
        }
        foreach ([[1, 1, ''], [2, 0, '12'], [5, 0, '10']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => $uid, 'username' => 'u' . $uid, 'admin' => $admin, 'usergroup' => $group]);
        }
        $this->get(SiteWriter::class)->write('solx', ['rootPageId' => 1, 'base' => 'https://solx.example.invalid/', 'languages' => [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
        ]]);
        $this->get(SiteFinder::class)->getAllSites(false);

        foreach ([
            [1, 'site', 0, 'completed', 0, 1_700_000_000, 0],
            [2, 'site', 1, 'completed', 0, self::FORBIDDEN_FINISHED_AT, 0],
            [3, 'site', 0, 'running', 0, 0, 0],
            [4, 'page', 0, 'completed', 1, 1_700_000_500, 2],
        ] as [$uid, $scope, $lang, $status, $free, $finished, $page]) {
            $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
                'uid' => $uid, 'site_identifier' => 'solx', 'job_id' => 'job-' . $uid, 'scan_scope' => $scope, 'language_uid' => $lang,
                'status' => $status, 'is_free_preview' => $free, 'page_uid' => $page, 'persisted_at' => $status === 'completed' ? 100 : 0,
                'pages_scanned' => 1, 'issues_total' => 1, 'crdate' => 1000 + $uid, 'finished_at' => $finished,
                'start_url' => 'https://solx.example.invalid/' . ($lang === 1 ? 'de/' : ''),
            ]);
            $node = 'SOLX NODE ' . $uid;
            $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', ['uid' => 50 + $uid, 'remote_scan' => $uid, 'url' => 'https://solx.example.invalid/' . ($lang === 1 ? 'de/' : '') . 'open', 'title' => 'R' . $uid, 'http_status' => 200]);
            $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', ['uid' => 50 + $uid, 'remote_scan' => $uid, 'remote_scan_page' => 50 + $uid, 'rule_id' => 'solx-rule-' . $uid, 'impact' => 'serious', 'nodes_count' => 1]);
            $pool->getConnectionForTable('tx_a11y_remote_issue_node')->insert('tx_a11y_remote_issue_node', ['uid' => 50 + $uid, 'remote_issue' => 50 + $uid, 'mapped_table' => 'tt_content', 'mapped_uid' => $lang === 1 ? 11 : 10, 'html_snippet' => $node]);
            if ($status === 'completed') {
                $this->get(RemoteResultProvenanceService::class)->stampScan($uid, 'solx');
            }
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function anExplicitlyNamedSiteScanOfARefusedLanguageIsRefusedForCsvAndPdf(): void
    {
        $this->user(5);
        foreach (['csv', 'pdf'] as $format) {
            // F9: the PDF route answered 200 and rendered scan 2 (its language row and exact completion time).
            $response = $this->export($format, ['remoteScanUid' => '2', 'language' => '1']);
            self::assertSame(403, $response->getStatusCode(), $format);
            $this->assertNoScanMetadata($response, 2);
            $response = $this->export($format, ['remoteScanUid' => '2', 'language' => '0']);
            self::assertSame(403, $response->getStatusCode(), $format . ': the request language does not change the target');
        }
        $this->user(2);
        self::assertStringContainsString('SOLX NODE 2', (string)$this->export('csv', ['remoteScanUid' => '2', 'language' => '1'])->getBody(), 'positive control: an editor of the language');
    }

    #[Test]
    public function anInvalidExplicitScanIsRefusedInsteadOfReplacedByAnotherScan(): void
    {
        foreach ([5, 1] as $user) {
            $this->user($user);
            foreach (['csv', 'pdf'] as $format) {
                // F9: a running named scan fell back to the latest completed site scan (scan 2, the refused language).
                $running = $this->export($format, ['remoteScanUid' => '3', 'language' => '0']);
                self::assertSame(403, $running->getStatusCode(), 'user ' . $user . ' ' . $format . ': unfinished scan');
                $this->assertNoScanMetadata($running, 2);
                self::assertSame(403, $this->export($format, ['remoteScanUid' => '4', 'language' => '0'])->getStatusCode(), 'user ' . $user . ' ' . $format . ': a Free Remote Preview result is no paid export target');
                self::assertSame(403, $this->export($format, ['remoteScanUid' => '999', 'language' => '0'])->getStatusCode(), 'unknown scan');
            }
        }
    }

    #[Test]
    public function aFallbackIsAuthorizedOnItsOwnAndNeverCrossesTheLanguageBoundary(): void
    {
        $this->user(5);
        // All languages, nothing named: the newest completed site scan is scan 2 (language 1), which this user may not read.
        foreach (['csv', 'pdf'] as $format) {
            $response = $this->export($format, ['language' => '-1']);
            self::assertSame(403, $response->getStatusCode(), $format);
            $this->assertNoScanMetadata($response, 2);
        }
        // Positive control: the fallback in the user's own language.
        $csv = (string)$this->export('csv', ['language' => '0'])->getBody();
        self::assertStringContainsString('SOLX NODE 1', $csv);
        self::assertStringNotContainsString('SOLX NODE 2', $csv);
    }

    #[Test]
    public function authorizedNamedExportsStillWork(): void
    {
        $this->user(5);
        $csv = $this->export('csv', ['remoteScanUid' => '1', 'language' => '0']);
        self::assertSame(200, $csv->getStatusCode());
        self::assertStringContainsString('SOLX NODE 1', (string)$csv->getBody());
        if (PdfGenerator::isAvailable()) {
            $pdf = $this->export('pdf', ['remoteScanUid' => '1', 'language' => '0']);
            self::assertSame(200, $pdf->getStatusCode());
            self::assertStringStartsWith('%PDF', (string)$pdf->getBody());
        }
        $this->user(1);
        self::assertStringContainsString('SOLX NODE 2', (string)$this->export('csv', ['remoteScanUid' => '2', 'language' => '1'])->getBody(), 'administrator');
    }

    private function assertNoScanMetadata(ResponseInterface $response, int $scanUid): void
    {
        $body = (string)$response->getBody();
        self::assertStringNotContainsString('SOLX NODE ' . $scanUid, $body);
        self::assertStringNotContainsString('solx-rule-' . $scanUid, $body);
        self::assertStringNotContainsString(date('d.m.Y', self::FORBIDDEN_FINISHED_AT), $body);
        self::assertStringNotContainsString('%PDF', $body);
    }

    /** @param array<string, string> $query */
    private function export(string $format, array $query): ResponseInterface
    {
        $request = (new ServerRequest('https://solx.example.invalid/typo3/ajax/a11y/export'))
            ->withQueryParams($query + ['scope' => 'remote', 'site' => 'solx'])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $controller = $this->controllerWithPaidLicence();

        return $format === 'pdf' ? $controller->pdfAction($request) : $controller->csvAction($request);
    }

    /**
     * The real controller and services. The paid (Agency) entitlement is the licence service's own cached answer for the
     * site's domains (no licence API exists in this isolated test; nothing is mocked).
     */
    private function controllerWithPaidLicence(): ExportController
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['licenceKey'] = 'aqg_live_' . str_repeat('5', 32);
        $site = $this->get(SiteFinder::class)->getSiteByIdentifier('solx');
        $resolver = $this->get(ProStatusResolverService::class);
        $licence = $this->get(ProLicenceService::class);
        $domains = (new \ReflectionMethod($resolver, 'collectCandidateDomainsForSite'))->invoke($resolver, $site);
        foreach ($domains as $domain) {
            $sites = $this->get(ProSiteFingerprintService::class)->collectValidationSites($domain, false);
            $key = (new \ReflectionMethod($licence, 'buildCacheKey'))->invoke($licence, $domain, $sites);
            $this->get(ProCacheManager::class)->setLicenceResult($key, new LicenceValidationResult(valid: true, plan: 'agency', features: ['crawler', 'export_pdf', 'multi_site']), 3600, 3600);
        }
        self::assertTrue((bool)$resolver->resolveForSite($site)->hasExportPdf, 'fixture: paid entitlement in force');

        return $this->get(ExportController::class);
    }

    private function user(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }
}
