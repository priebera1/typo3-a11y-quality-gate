<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\ExportController;
use Priebera\A11yQualityGate\Export\AcceptanceEvidenceBuilder;
use Priebera\A11yQualityGate\Export\PdfGenerator;
use Priebera\A11yQualityGate\Export\PdfTemplateRenderer;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\Service\RemoteScanPairingService;
use Priebera\A11yQualityGate\Service\ScanComparisonService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use ReflectionClass;
use ReflectionProperty;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Acceptance evidence states what was compared, what was not, and the limits of automated testing — and
 * is only produced for two compatible scans the user may export, on a PRO or Agency licence.
 */
final class AcceptanceEvidenceTest extends TestCase
{
    private const FROM = ['uid' => 1, 'job_id' => 'from', 'finished_at' => 1780000000, 'scan_scope' => 'site', 'start_url' => 'https://example.org/', 'pages_scanned' => 10, 'pages_failed' => 1];
    private const TO = ['uid' => 2, 'job_id' => 'to', 'finished_at' => 1790000000, 'scan_scope' => 'site', 'start_url' => 'https://example.org/', 'pages_scanned' => 11, 'pages_failed' => 0];

    #[Test]
    public function evidenceCountsTheComparisonAndKeepsItsLimitations(): void
    {
        $evidence = $this->builder()->build(new Site('main', 1, ['base' => 'https://example.org/']), self::FROM, self::TO);

        self::assertSame(['fixed' => 1, 'new' => 1, 'regressed' => 0, 'unresolved' => 0, 'unverified' => 1, 'comparedPages' => 1], $evidence['counts']);
        self::assertSame(10, $evidence['baseline']['pagesScanned']);
        self::assertSame(1, $evidence['baseline']['pagesFailed']);
        self::assertSame('Page failed to load in one of the scans', $evidence['unverified'][0]['reasonLabel']);
        self::assertStringContainsString('cannot confirm WCAG conformance', implode(' ', $evidence['limitations']));
    }

    #[Test]
    public function theCsvNeutralisesFormulasAndListsUncomparedPages(): void
    {
        $builder = $this->builder();
        $csv = $builder->renderCsv($builder->build(new Site('main', 1, ['base' => 'https://example.org/']), self::FROM, self::TO));

        self::assertStringContainsString('status,url,rule,impact,baseline_occurrences,current_occurrences,note,site,scope,language_uid,baseline_scan_finished,current_scan_finished', $csv);
        self::assertStringNotContainsString(',=HYPERLINK', $csv);
        self::assertStringContainsString('not_compared,https://example.org/broken', $csv);

        // Detached from the PDF, the CSV still names what was compared, how much, and what it cannot prove.
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), array_filter(explode("\n", substr($csv, 3))));
        $header = array_shift($rows);
        foreach ($rows as $row) {
            $named = array_combine($header, $row);
            self::assertSame('https://example.org', $named['site']);
            self::assertNotSame('', $named['baseline_scan_finished']);
            self::assertNotSame('', $named['current_scan_finished']);
        }
        self::assertContains('coverage', array_column($rows, 0));
        self::assertStringContainsString('cannot confirm WCAG conformance', implode(' ', array_column($rows, 6)));
    }

    #[Test]
    public function aTamperedPairIsRefused(): void
    {
        $response = $this->controller(pair: null)->acceptanceAction($this->request());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function readAccessAloneDoesNotExportEvidence(): void
    {
        $response = $this->controller(canEdit: false)->acceptanceAction($this->request());

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function aTrialCannotExportEvidence(): void
    {
        $response = $this->controller(trial: true)->acceptanceAction($this->request());

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('PRO or Agency', (string)$response->getBody());
    }

    #[Test]
    public function aProSiteGetsThePdf(): void
    {
        $response = $this->controller()->acceptanceAction($this->request());

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('application/pdf', $response->getHeaderLine('Content-Type'));
        self::assertSame('%PDF-evidence', (string)$response->getBody());
    }

    private function builder(): AcceptanceEvidenceBuilder
    {
        $comparison = $this->createMock(ScanComparisonService::class);
        $comparison->method('compare')->willReturn([
            'fixed' => [['url' => 'https://example.org/a', 'ruleId' => 'image-alt', 'impact' => 'critical', 'before' => 2, 'after' => 0]],
            'new' => [['url' => '=HYPERLINK("https://evil")', 'ruleId' => 'color-contrast', 'impact' => 'serious', 'before' => 0, 'after' => 1]],
            'regressed' => [],
            'unresolved' => [],
            'unverified' => [['url' => 'https://example.org/broken', 'reason' => 'page_failed']],
            'comparedPages' => 1,
        ]);
        $pdf = $this->createMock(PdfGenerator::class);
        $pdf->method('render')->willReturn('%PDF-evidence');
        $renderer = $this->createMock(PdfTemplateRenderer::class);
        $renderer->method('render')->willReturn('<html></html>');

        return new AcceptanceEvidenceBuilder($comparison, $renderer, $pdf);
    }

    private function controller(?array $pair = ['from' => self::FROM, 'to' => self::TO], bool $canEdit = true, bool $trial = false): ExportController
    {
        $pairing = $this->createMock(RemoteScanPairingService::class);
        $pairing->method('resolveComparePair')->willReturn($pair);
        $scope = $this->createMock(ScopeAccessService::class);
        $scope->method('canEditRemoteScan')->willReturn($canEdit);
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteByIdentifier')->willReturn(new Site('main', 1, ['base' => 'https://example.org/']));
        $status = $this->createMock(ProStatusResolverService::class);
        $status->method('resolveForSite')->willReturn(new ProStatusViewModel(
            configured: true, valid: true, proAvailable: true, plan: $trial ? 'trial' : 'pro', features: [], reason: null,
            reasonLabel: null, statusLabel: '', showProHints: false, hasCrawler: true, hasExportPdf: !$trial,
            hasMultiSite: false, hasProRules: true, isTrial: $trial,
        ));

        $subject = (new ReflectionClass(ExportController::class))->newInstanceWithoutConstructor();
        foreach ([
            'remoteScanPairingService' => $pairing,
            'scopeAccessService' => $scope,
            'siteResolutionService' => $sites,
            'proStatusResolverService' => $status,
            'acceptanceEvidenceBuilder' => $this->builder(),
            'responseFactory' => new ResponseFactory(),
            'streamFactory' => new StreamFactory(),
        ] as $property => $value) {
            (new ReflectionProperty(ExportController::class, $property))->setValue($subject, $value);
        }

        return $subject;
    }

    private function request(): ServerRequest
    {
        return (new ServerRequest('https://example.org/typo3/module/web/a11y/export/acceptance', 'GET'))
            ->withQueryParams(['site' => 'main', 'fromJobId' => 'from', 'toJobId' => 'to', 'format' => 'pdf']);
    }
}
