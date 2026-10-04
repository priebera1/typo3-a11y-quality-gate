<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\PageModuleIndicatorAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\SourceStateRepository;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\PageModuleIndicatorService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * A frontend scan started in the Accessibility module stayed at "Scan running / Frontend scan: 0/1 pages processed"
 * in the Page module until the browser was reloaded: only a full render read the running scan back from the AQG
 * service, and the indicator never asked for one. It now asks the state endpoint, which renders the panel the same
 * way — reading the scan back and storing its results once it has completed — until the scan has ended.
 */
final class PageModuleIndicatorRunningScanTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    #[Test]
    public function aRunningFrontendScanIsReadBackFromTheServiceOnEveryState(): void
    {
        $running = ['job_id' => 'job-1', 'site_identifier' => 'main', 'status' => 'running', 'pages_scanned' => 0, 'pages_total' => 1];
        $recovery = $this->createMock(RemoteScanRecoveryService::class);
        $recovery->expects(self::exactly(2))->method('recoverScanIfNeeded')->willReturnOnConsecutiveCalls(
            ['status' => 'active', 'pages_scanned' => 1, 'pages_total' => 1] + $running,
            ['status' => 'completed', 'pages_scanned' => 1, 'pages_total' => 1, 'persisted_at' => 1_790_000_000] + $running,
        );
        $service = $this->service($recovery, $running);
        $site = new Site('main', 1, ['base' => 'https://example.org/']);

        // buildState() renders exactly these variables; its `running` is their `isRunning`.
        $first = $service->buildViewData(42, $site, 0);
        self::assertTrue($first['isRunning']);
        self::assertSame('running', $first['overallState']);

        $second = $service->buildViewData(42, $site, 0);
        self::assertFalse($second['isRunning'], 'a completed scan ends the following');
        self::assertNotSame('running', $second['overallState']);
    }

    #[Test]
    public function aPageWithoutSiteHasNoState(): void
    {
        $service = $this->service($this->createMock(RemoteScanRecoveryService::class), null);

        self::assertNull($service->buildState(42, null, 0));
        self::assertNull($service->buildViewData(0, new Site('main', 1, ['base' => 'https://example.org/']), 0));
    }

    #[Test]
    public function theEndpointAnswersOnlyForAReadablePageAndTakesTheSiteFromThePage(): void
    {
        $site = new Site('main', 1, ['base' => 'https://example.org/']);
        $request = (new ServerRequest('https://backend.example/typo3/ajax/a11y/page-module-indicator'))
            ->withQueryParams(['pageUid' => '42', 'language' => '1', 'site' => 'other-site']);

        $indicator = $this->createMock(PageModuleIndicatorService::class);
        $indicator->expects(self::once())->method('buildState')->with(42, $site, 1)
            ->willReturn(['running' => true, 'html' => '<section>panel</section>']);
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->expects(self::once())->method('resolveSiteByPageId')->with(42)->willReturn($site);
        $sites->expects(self::never())->method('resolveSiteForBackendRequest');

        $response = $this->controller($indicator, $sites, true)->stateAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(
            ['success' => true, 'running' => true, 'html' => '<section>panel</section>'],
            json_decode((string)$response->getBody(), true),
        );
    }

    #[Test]
    public function aPageTheUserCannotReadIsRefusedBeforeAnythingIsRendered(): void
    {
        $indicator = $this->createMock(PageModuleIndicatorService::class);
        $indicator->expects(self::never())->method('buildState');
        $request = (new ServerRequest('https://backend.example/'))->withQueryParams(['pageUid' => '42']);

        $response = $this->controller($indicator, $this->createMock(SiteResolutionService::class), false)->stateAction($request);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('access_denied', json_decode((string)$response->getBody(), true)['code']);
    }

    #[Test]
    public function aPageOutsideEverySiteEndsTheFollowing(): void
    {
        $indicator = $this->createMock(PageModuleIndicatorService::class);
        $indicator->method('buildState')->willReturn(null);
        $request = (new ServerRequest('https://backend.example/'))->withQueryParams(['pageUid' => '42']);

        $response = $this->controller($indicator, $this->createMock(SiteResolutionService::class), true)->stateAction($request);

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function thePanelTellsTheScriptWhatToFollowAndTheRouteInheritsModuleAccess(): void
    {
        $template = (string)file_get_contents(self::ROOT . 'Resources/Private/Templates/Backend/PageModuleIndicator.html');
        self::assertStringContainsString('data-aqg-indicator-running="{f:if(condition: isRunning, then: \'1\', else: \'0\')}"', $template);
        self::assertStringContainsString('data-language-uid="{languageUid}"', $template);
        self::assertStringContainsString('data-page-uid="{pageUid}"', $template);

        $routes = require self::ROOT . 'Configuration/Backend/AjaxRoutes.php';
        self::assertSame(PageModuleIndicatorAjaxController::class . '::stateAction', $routes['a11y_page_module_indicator']['target']);
        self::assertSame(['GET'], $routes['a11y_page_module_indicator']['methods']);
        self::assertSame('web_a11y', $routes['a11y_page_module_indicator']['inheritAccessFromModule']);

        $script = (string)file_get_contents(self::ROOT . 'Resources/Public/JavaScript/backend/page-module-indicator.js');
        self::assertStringContainsString('ajaxUrls?.a11y_page_module_indicator', $script);
        self::assertStringContainsString("window.addEventListener('pagehide', stopFollowingRunningScan)", $script);
    }

    private function controller(PageModuleIndicatorService $indicator, SiteResolutionService $sites, bool $canRead): PageModuleIndicatorAjaxController
    {
        $scope = $this->createMock(ScopeAccessService::class);
        $scope->method('canReadPage')->willReturnCallback(static fn (int $pageUid): bool => $canRead && $pageUid === 42);
        $parameters = $this->createMock(RequestParameterService::class);
        $parameters->method('getPageUidOrZero')->willReturnCallback(
            static fn (ServerRequest $request): int => (int)($request->getQueryParams()['pageUid'] ?? 0)
        );
        $parameters->method('getLanguageUid')->willReturnCallback(
            static fn (ServerRequest $request): int => (int)($request->getQueryParams()['language'] ?? 0)
        );

        return new PageModuleIndicatorAjaxController($indicator, $sites, $scope, $parameters);
    }

    /**
     * @param array<string, mixed>|null $activeScan
     */
    private function service(RemoteScanRecoveryService $recovery, ?array $activeScan): PageModuleIndicatorService
    {
        $proStatus = $this->createMock(ProStatusResolverService::class);
        $proStatus->method('resolveForSite')->willReturn(new ProStatusViewModel(
            configured: true,
            valid: true,
            proAvailable: true,
            plan: 'pro',
            features: ['crawler'],
            reason: null,
            reasonLabel: null,
            statusLabel: 'Licence active — Pro',
            showProHints: true,
            hasCrawler: true,
            hasExportPdf: true,
            hasMultiSite: false,
            hasProRules: true,
            domain: 'example.org',
        ));
        $issues = $this->createMock(IssueRepository::class);
        $issues->method('countOpenBySeverity')->willReturn(['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0]);
        $remoteScans = $this->createMock(RemoteScanRepository::class);
        $remoteScans->method('findLatestActiveScanBySite')->willReturn($activeScan);
        $scanStatus = $this->createMock(ScanStatusService::class);
        $scanStatus->method('getStatus')->willReturn([]);
        $backendContext = $this->createMock(BackendContextService::class);
        $backendContext->method('translate')->willReturnCallback(static fn (string $key): string => $key);
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturn(new Uri('/typo3/aqg'));
        $urls = $this->createMock(FrontendPageUrlService::class);
        $urls->method('resolveForPage')->willReturn('https://example.org/page');

        $viewFactory = $this->createMock(ViewFactoryInterface::class);

        return new PageModuleIndicatorService(
            $issues,
            $this->createMock(SourceStateRepository::class),
            $this->createMock(ScanRepository::class),
            $remoteScans,
            $proStatus,
            $recovery,
            $scanStatus,
            $backendContext,
            $uriBuilder,
            $viewFactory,
            $urls,
            $this->createMock(FreeRemotePreviewService::class),
        );
    }
}
