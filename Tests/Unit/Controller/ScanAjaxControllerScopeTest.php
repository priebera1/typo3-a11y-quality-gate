<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\ScanAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\LanguageUidResolver;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * One content scan runs per installation and its status is polled by every AQG user. Who may see its
 * details or stop it follows the page it covers, and a failed scan never publishes its exception text.
 */
final class ScanAjaxControllerScopeTest extends TestCase
{
    private ScanStatusService $scanStatusService;
    private ScopeAccessService $scopeAccessService;
    private RemoteScanRepository $remoteScanRepository;
    private ScanRepository $scanRepository;
    private ScanOrchestrator $orchestrator;

    protected function setUp(): void
    {
        $this->scanStatusService = $this->createMock(ScanStatusService::class);
        // Real status redaction, mocked page and site permissions.
        $this->scopeAccessService = $this->getMockBuilder(ScopeAccessService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['canReadPage', 'canEditPage', 'canReadSiteIdentifier'])
            ->getMock();
        $this->remoteScanRepository = $this->createMock(RemoteScanRepository::class);
        $this->scanRepository = $this->createMock(ScanRepository::class);
        $this->orchestrator = $this->createMock(ScanOrchestrator::class);
    }

    #[Test]
    public function cancelIsRefusedForAScanOfAPageTheUserCannotEdit(): void
    {
        $this->scanStatusService->method('isRunning')->willReturn(true);
        $this->scanStatusService->method('getStatus')->willReturn($this->runningStatus());
        $this->scopeAccessService->method('canEditPage')->with(77)->willReturn(false);
        $this->scopeAccessService->method('canReadPage')->willReturn(false);
        $this->scanStatusService->expects(self::never())->method('requestCancellation');
        $this->scanRepository->expects(self::never())->method('requestScanCancellation');

        $response = $this->controller()->cancelScanAction($this->request());
        $payload = $this->decode($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('local_scan_access_denied', $payload['code']);
        self::assertArrayNotHasKey('pageUid', $payload['status']);
    }

    #[Test]
    public function cancelIsAcceptedForAScanOfAnEditablePage(): void
    {
        $this->scanStatusService->method('isRunning')->willReturn(true);
        $this->scanStatusService->method('getStatus')->willReturn($this->runningStatus());
        $this->scopeAccessService->method('canEditPage')->with(77)->willReturn(true);
        $this->scopeAccessService->method('canReadPage')->willReturn(true);
        $this->scanStatusService->expects(self::once())->method('requestCancellation');
        $this->scanRepository->expects(self::once())->method('requestScanCancellation')->with(501);

        $response = $this->controller()->cancelScanAction($this->request());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function statusOfAScanOutsideTheUsersPagesOnlySaysThatAScanIsRunning(): void
    {
        $this->scanStatusService->method('getStatus')->willReturn($this->runningStatus());
        $this->scopeAccessService->method('canReadPage')->with(77)->willReturn(false);

        $payload = $this->decode($this->controller()->scanStatusAction($this->request()));

        self::assertTrue($payload['status']['running']);
        self::assertTrue($payload['status']['restricted']);
        foreach (['pageUid', 'rootPid', 'triggeredBy', 'summary', 'error', 'scanUid'] as $key) {
            self::assertArrayNotHasKey($key, $payload['status']);
        }
    }

    #[Test]
    public function remoteStatusOfASiteTheUserCannotReadIsNotReturned(): void
    {
        $this->scanStatusService->method('getStatus')->willReturn(['running' => false]);
        $this->scopeAccessService->method('canReadSiteIdentifier')->with('client-b')->willReturn(false);
        $this->remoteScanRepository->expects(self::never())->method('findLatestActiveSiteScanBySite');
        $this->remoteScanRepository->expects(self::never())->method('findLastCompletedScanBySite');

        $payload = $this->decode($this->controller()->scanStatusAction($this->request(['site' => 'client-b'])));

        self::assertNull($payload['remoteStatus']);
    }

    #[Test]
    public function failedScanKeepsTheExceptionTextOutOfTheResponseAndTheSharedStatus(): void
    {
        $accessControl = $this->createMock(AccessControlService::class);
        $accessControl->method('canShowScanNow')->willReturn(true);
        $this->scanStatusService->method('isRunning')->willReturn(false);
        $this->scanStatusService->method('getStatus')->willReturn(['running' => false]);
        $this->scopeAccessService->method('canReadPage')->willReturn(true);
        $this->orchestrator->method('scanPage')->willThrowException(
            new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused (db.internal:3306) in /var/www/html/vendor/x.php')
        );
        $this->scanStatusService->expects(self::once())->method('markFailed')->with(
            self::logicalNot(self::stringContains('db.internal'))
        );

        $response = $this->controller($accessControl)->scanPageAction($this->request([], ['pageUid' => 12]));
        $body = (string)$response->getBody();

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString('db.internal', $body);
        self::assertStringNotContainsString('/var/www', $body);
        self::assertSame('local_scan_failed', $this->decode($response)['code']);
    }

    private function controller(?AccessControlService $accessControl = null): ScanAjaxController
    {
        $backendUser = $this->getMockBuilder(BackendUserAuthentication::class)->disableOriginalConstructor()->getMock();
        $backendUser->user = ['uid' => 3, 'username' => 'editor'];
        $users = $this->createMock(BackendUserService::class);
        $users->method('isLoggedIn')->willReturn(true);
        $users->method('getBackendUser')->willReturn($backendUser);
        $users->method('getBackendUserSnapshot')->willReturn(['uid' => 3, 'username' => 'editor', 'name' => 'editor']);

        if ($accessControl === null) {
            $accessControl = $this->createMock(AccessControlService::class);
            $accessControl->method('canShowScanNow')->willReturn(true);
            $accessControl->method('canShowScanAll')->willReturn(true);
        }

        $records = $this->createMock(BackendRecordAccessService::class);
        $records->method('canEditRecord')->willReturn(true);
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteIdentifierFromPageId')->willReturn('main');

        return new ScanAjaxController(
            new ResponseFactory(),
            new StreamFactory(),
            $users,
            $this->orchestrator,
            $sites,
            $accessControl,
            $this->scanStatusService,
            $this->remoteScanRepository,
            $this->scanRepository,
            $records,
            new LanguageUidResolver(),
            $this->scopeAccessService,
        );
    }

    /** @return array<string, mixed> */
    private function runningStatus(): array
    {
        return [
            'running' => true,
            'startedAt' => 1700000000,
            'trigger' => 'page',
            'triggeredBy' => 'alice',
            'pageUid' => 77,
            'rootPid' => null,
            'scanUid' => 501,
            'summary' => null,
            'error' => null,
            'cancelRequested' => false,
        ];
    }

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    private function request(array $query = [], array $body = []): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getQueryParams')->willReturn($query);
        $request->method('getParsedBody')->willReturn($body);

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
