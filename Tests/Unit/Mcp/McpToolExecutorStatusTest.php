<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Mcp\McpScanRequestStore;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\Intelligence\McpIntelligenceTools;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Mcp\McpUserContext;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Sol AQG-MCP-02: a status refresh that failed is never reported as live; the stored status follows, marked stale, with a
 * bounded code and a fixed sentence (never the module's or the scanner's text).
 */
final class McpToolExecutorStatusTest extends TestCase
{
    private const JOB = '11111111-1111-4111-8111-111111111111';

    /** @var array<string, mixed> */
    private array $previousGlobals = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousGlobals = ['BE_USER' => $GLOBALS['BE_USER'] ?? null, 'TYPO3_REQUEST' => $GLOBALS['TYPO3_REQUEST'] ?? null];
    }

    protected function tearDown(): void
    {
        $GLOBALS['BE_USER'] = $this->previousGlobals['BE_USER'];
        $GLOBALS['TYPO3_REQUEST'] = $this->previousGlobals['TYPO3_REQUEST'];
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: ResponseInterface, 1: string, 2: ?int}>
     */
    public static function failedRefreshes(): iterable
    {
        yield 'Sol reproduction: 502' => [new JsonResponse(['success' => false, 'code' => 'crawler_unavailable', 'message' => 'Upstream https://crawler.internal/x failed'], 502), 'crawler_unavailable', null];
        yield '503 without code' => [new JsonResponse(['success' => false], 503), 'scanner_unavailable', null];
        yield '500 with an unsafe code' => [new JsonResponse(['success' => false, 'code' => 'Boom <script>'], 500), 'scanner_unavailable', null];
        yield '429' => [new JsonResponse(['success' => false, 'code' => 'rate_limited', 'retryAfter' => 30], 429), 'rate_limited', 30];
        yield '401' => [new JsonResponse(['success' => false, 'code' => 'licence_not_active'], 401), 'licence_not_active', null];
        yield '403 without code' => [new JsonResponse(['success' => false], 403), 'status_refresh_refused', null];
        yield '404' => [new JsonResponse(['success' => false], 404), 'status_not_found', null];
        yield '409 mismatch' => [new JsonResponse(['success' => false, 'code' => 'remote_job_response_mismatch'], 409), 'remote_job_response_mismatch', null];
        yield '200 without success' => [new JsonResponse(['status' => 'completed'], 200), 'invalid_status_response', null];
        yield '200 success without status' => [new JsonResponse(['success' => true], 200), 'invalid_status_response', null];
        yield '200 not JSON' => [new Response('php://temp', 200, ['Content-Type' => 'text/html']), 'invalid_status_response', null];
    }

    #[Test]
    #[DataProvider('failedRefreshes')]
    public function aFailedRefreshIsStaleNeverLive(ResponseInterface $response, string $code, ?int $retryAfter): void
    {
        $result = $this->runStatus($response);

        self::assertFalse($result['live']);
        self::assertTrue($result['stale']);
        self::assertNull($result['failure']);
        self::assertSame($code, $result['refreshError']['code']);
        self::assertSame($retryAfter, $result['refreshError']['retryAfter'] ?? null);
        self::assertSame('running', $result['scan']['status'], 'the stored status, explicitly stale');
        $encoded = (string)json_encode($result);
        self::assertStringNotContainsString('crawler.internal', $encoded);
        self::assertStringNotContainsString('<script>', $encoded);
    }

    #[Test]
    public function aSuccessfulRefreshStaysLiveAndReportsAFailedJob(): void
    {
        $result = $this->runStatus(new JsonResponse(['success' => true, 'status' => 'failed', 'failureCode' => 'start_url_unreachable', 'title' => 'Not reachable', 'message' => 'The page could not be loaded.']));

        self::assertTrue($result['live']);
        self::assertFalse($result['stale']);
        self::assertNull($result['refreshError']);
        self::assertSame(['code' => 'start_url_unreachable', 'title' => 'Not reachable', 'message' => 'The page could not be loaded.'], $result['failure']);
    }

    #[Test]
    public function aJobTheServiceNoLongerHasIsADefinitiveAnswer(): void
    {
        $result = $this->runStatus(new JsonResponse(['success' => false, 'code' => 'remote_job_unavailable', 'status' => 'failed'], 410));

        self::assertTrue($result['live']);
        self::assertSame('remote_job_unavailable', $result['failure']['code']);
        self::assertNull($result['refreshError']);
    }

    #[Test]
    public function withoutScanPermissionTheStoredStatusIsStaleAndNoRefreshIsAttempted(): void
    {
        $result = $this->runStatus(null);

        self::assertFalse($result['live']);
        self::assertTrue($result['stale']);
        self::assertNull($result['refreshError']);
    }

    /**
     * @return array<string, mixed>
     */
    private function runStatus(?ResponseInterface $response): array
    {
        $site = new Site('client-a', 1, ['base' => 'https://a.test/', 'languages' => [['languageId' => 0, 'base' => '/', 'title' => 'English', 'locale' => 'en_US.UTF-8']]]);
        $scan = ['uid' => 1, 'job_id' => self::JOB, 'site_identifier' => 'client-a', 'scan_scope' => 'site', 'status' => 'running', 'persisted_at' => 0];
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->method('isAdmin')->willReturn(false);
        $GLOBALS['BE_USER'] = $user;
        $settings = $this->createMock(McpSiteSettingsRepository::class);
        $settings->method('effectiveCapabilities')->willReturn(['history:read']);
        $scope = $this->createMock(ScopeAccessService::class);
        $scope->method('canReadSite')->willReturn(true);
        $scope->method('resolveReadableScanByJobId')->willReturn($scan);
        $access = $this->createMock(AccessControlService::class);
        $access->method('canShowScanNow')->willReturn($response !== null);
        $access->method('canShowScanAll')->willReturn(false);
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteByIdentifier')->willReturn($site);
        $repository = $this->createMock(RemoteScanRepository::class);
        $repository->method('findScanByJobId')->willReturn($scan);
        $controller = $this->createMock(ProCrawlerAjaxController::class);
        if ($response !== null) {
            $controller->expects(self::once())->method('statusAction')->willReturn($response);
        } else {
            $controller->expects(self::never())->method('statusAction');
        }
        $executor = new McpToolExecutor(
            $this->createMock(McpUserContext::class),
            $settings,
            $scope,
            $access,
            $sites,
            $repository,
            $this->createMock(RemoteResultAuthorizationService::class),
            $this->createMock(ConnectionPool::class),
            $this->createMock(McpScanRequestStore::class),
            $controller,
            // Not used by aqg_get_scan_status (final class: an instance without its services).
            (new \ReflectionClass(McpIntelligenceTools::class))->newInstanceWithoutConstructor(),
        );

        $method = new \ReflectionMethod(McpToolExecutor::class, 'getScanStatus');

        return $method->invoke($executor, $user, ['siteIdentifier' => 'client-a', 'jobId' => self::JOB], ['siteIdentifiers' => ['client-a'], 'scopes' => ['history:read']]);
    }
}
