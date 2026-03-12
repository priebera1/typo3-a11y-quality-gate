<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\ScanAjaxController;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Scan\ScanResult;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class ScanAjaxControllerTest extends TestCase
{
    private ScanAjaxController $controller;
    private ScanOrchestrator $orchestrator;
    private SiteResolutionService $siteResolutionService;
    private AccessControlService $accessControlService;
    private ScanStatusService $scanStatusService;
    private BackendUserService $backendUserService;
    private ServerRequestInterface $request;
    private ResponseInterface $response;

    protected function setUp(): void
    {
        $this->orchestrator = $this->createMock(ScanOrchestrator::class);
        $this->siteResolutionService = $this->createMock(SiteResolutionService::class);
        $this->accessControlService = $this->createMock(AccessControlService::class);
        $this->scanStatusService = $this->createMock(ScanStatusService::class);
        $this->backendUserService = $this->createMock(BackendUserService::class);

        $stream = $this->createMock(StreamInterface::class);

        $this->response = $this->createMock(ResponseInterface::class);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withBody')->willReturnSelf();

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($this->response);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $this->controller = new ScanAjaxController(
            $responseFactory,
            $streamFactory,
            $this->backendUserService,
            $this->orchestrator,
            $this->siteResolutionService,
            $this->accessControlService,
            $this->scanStatusService,
        );

        $this->request = $this->createMock(ServerRequestInterface::class);
    }

    #[Test]
    public function scanPageActionReturns401WhenNotLoggedIn(): void
    {
        $GLOBALS['BE_USER'] = null;

        $this->backendUserService
            ->method('getBackendUser')
            ->willReturn(null);

        $this->request->method('getParsedBody')->willReturn(['pageUid' => 1]);

        $this->accessControlService->expects($this->never())->method('canShowScanNow');
        $this->orchestrator->expects($this->never())->method('scanPage');

        $response = $this->controller->scanPageAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanPageActionReturns403WhenAccessIsDenied(): void
    {
        $backendUser = $this->mockLoggedInUser(1);

        $this->request->method('getParsedBody')->willReturn(['pageUid' => 1]);

        $this->accessControlService
            ->expects($this->once())
            ->method('canShowScanNow')
            ->with($backendUser)
            ->willReturn(false);

        $this->scanStatusService->expects($this->never())->method('isRunning');
        $this->orchestrator->expects($this->never())->method('scanPage');

        $response = $this->controller->scanPageAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanPageActionReturns409WhenScanIsAlreadyRunning(): void
    {
        $backendUser = $this->mockLoggedInUser(1);

        $this->request->method('getParsedBody')->willReturn(['pageUid' => 1]);

        $this->accessControlService
            ->expects($this->once())
            ->method('canShowScanNow')
            ->with($backendUser)
            ->willReturn(true);

        $this->scanStatusService
            ->expects($this->once())
            ->method('isRunning')
            ->willReturn(true);

        $this->scanStatusService
            ->expects($this->once())
            ->method('getStatus')
            ->willReturn(['running' => true]);

        $this->orchestrator->expects($this->never())->method('scanPage');

        $response = $this->controller->scanPageAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanPageActionReturns400WhenPageUidMissing(): void
    {
        $backendUser = $this->mockLoggedInUser(1);

        $this->request->method('getParsedBody')->willReturn([]);

        $this->accessControlService
            ->expects($this->once())
            ->method('canShowScanNow')
            ->with($backendUser)
            ->willReturn(true);

        $this->scanStatusService
            ->expects($this->once())
            ->method('isRunning')
            ->willReturn(false);

        $this->orchestrator->expects($this->never())->method('scanPage');

        $response = $this->controller->scanPageAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanPageActionRunsScanAndReturnsResult(): void
    {
        $backendUser = $this->mockLoggedInUser(1, 'tester');

        $this->request->method('getParsedBody')->willReturn(['pageUid' => 42]);

        $this->accessControlService
            ->expects($this->once())
            ->method('canShowScanNow')
            ->with($backendUser)
            ->willReturn(true);

        $this->scanStatusService
            ->expects($this->once())
            ->method('isRunning')
            ->willReturn(false);

        $this->siteResolutionService
            ->expects($this->once())
            ->method('resolveSiteIdentifierFromPageId')
            ->with(42)
            ->willReturn('main');

        $this->scanStatusService
            ->expects($this->once())
            ->method('markRunning')
            ->with(
                'page',
                'tester',
                42,
                null,
            );

        $result = new ScanResult(scanUid: 7);
        $result->pagesScanned = 1;
        $result->recordsScanned = 5;
        $result->issuesNew = 3;
        $result->issuesResolved = 1;
        $result->issuesIgnored = 0;

        $this->orchestrator
            ->expects($this->once())
            ->method('scanPage')
            ->with('main', 42)
            ->willReturn($result);

        $this->scanStatusService
            ->expects($this->once())
            ->method('markFinished')
            ->with($result);

        $this->scanStatusService
            ->expects($this->once())
            ->method('getStatus')
            ->willReturn(['running' => false]);

        $response = $this->controller->scanPageAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanPageActionReturns500WhenScanThrows(): void
    {
        $backendUser = $this->mockLoggedInUser(1, 'tester');

        $this->request->method('getParsedBody')->willReturn(['pageUid' => 42]);

        $this->accessControlService
            ->expects($this->once())
            ->method('canShowScanNow')
            ->with($backendUser)
            ->willReturn(true);

        $this->scanStatusService
            ->expects($this->once())
            ->method('isRunning')
            ->willReturn(false);

        $this->siteResolutionService
            ->expects($this->once())
            ->method('resolveSiteIdentifierFromPageId')
            ->with(42)
            ->willReturn('main');

        $this->scanStatusService
            ->expects($this->once())
            ->method('markRunning')
            ->with(
                'page',
                'tester',
                42,
                null,
            );

        $this->orchestrator
            ->expects($this->once())
            ->method('scanPage')
            ->with('main', 42)
            ->willThrowException(new \RuntimeException('DB connection lost'));

        $this->scanStatusService
            ->expects($this->once())
            ->method('markFailed')
            ->with('DB connection lost');

        $this->scanStatusService
            ->expects($this->once())
            ->method('getStatus')
            ->willReturn([
                'running' => false,
                'error' => 'DB connection lost',
            ]);

        $response = $this->controller->scanPageAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanSiteActionReturns401WhenNotLoggedIn(): void
    {
        $GLOBALS['BE_USER'] = null;

        $this->backendUserService
            ->method('getBackendUser')
            ->willReturn(null);

        $this->request->method('getParsedBody')->willReturn(['rootPid' => 1]);

        $this->accessControlService->expects($this->never())->method('canShowScanAll');
        $this->orchestrator->expects($this->never())->method('scanSubtree');

        $response = $this->controller->scanSiteAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanSiteActionReturns403WhenAccessIsDenied(): void
    {
        $backendUser = $this->mockLoggedInUser(1);

        $this->request->method('getParsedBody')->willReturn(['rootPid' => 1]);

        $this->accessControlService
            ->expects($this->once())
            ->method('canShowScanAll')
            ->with($backendUser)
            ->willReturn(false);

        $this->scanStatusService->expects($this->never())->method('isRunning');
        $this->orchestrator->expects($this->never())->method('scanSubtree');

        $response = $this->controller->scanSiteAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanSiteActionReturns409WhenScanIsAlreadyRunning(): void
    {
        $backendUser = $this->mockLoggedInUser(1);

        $this->request->method('getParsedBody')->willReturn(['rootPid' => 1]);

        $this->accessControlService
            ->expects($this->once())
            ->method('canShowScanAll')
            ->with($backendUser)
            ->willReturn(true);

        $this->scanStatusService
            ->expects($this->once())
            ->method('isRunning')
            ->willReturn(true);

        $this->scanStatusService
            ->expects($this->once())
            ->method('getStatus')
            ->willReturn(['running' => true]);

        $this->orchestrator->expects($this->never())->method('scanSubtree');

        $response = $this->controller->scanSiteAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanSiteActionReturns400WhenRootPidMissing(): void
    {
        $backendUser = $this->mockLoggedInUser(1);

        $this->request->method('getParsedBody')->willReturn([]);

        $this->accessControlService
            ->expects($this->once())
            ->method('canShowScanAll')
            ->with($backendUser)
            ->willReturn(true);

        $this->scanStatusService
            ->expects($this->once())
            ->method('isRunning')
            ->willReturn(false);

        $this->orchestrator->expects($this->never())->method('scanSubtree');

        $response = $this->controller->scanSiteAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanSiteActionRunsScanAndReturnsResult(): void
    {
        $backendUser = $this->mockLoggedInUser(1, 'tester');

        $this->request->method('getParsedBody')->willReturn(['rootPid' => 123]);

        $this->accessControlService
            ->expects($this->once())
            ->method('canShowScanAll')
            ->with($backendUser)
            ->willReturn(true);

        $this->scanStatusService
            ->expects($this->once())
            ->method('isRunning')
            ->willReturn(false);

        $this->siteResolutionService
            ->expects($this->once())
            ->method('resolveSiteIdentifierFromPageId')
            ->with(123)
            ->willReturn('main');

        $this->scanStatusService
            ->expects($this->once())
            ->method('markRunning')
            ->with(
                'site',
                'tester',
                null,
                123,
            );

        $result = new ScanResult(scanUid: 8);
        $result->pagesScanned = 10;
        $result->recordsScanned = 50;
        $result->issuesNew = 4;
        $result->issuesResolved = 2;
        $result->issuesIgnored = 1;

        $this->orchestrator
            ->expects($this->once())
            ->method('scanSubtree')
            ->with('main', 123)
            ->willReturn($result);

        $this->scanStatusService
            ->expects($this->once())
            ->method('markFinished')
            ->with($result);

        $this->scanStatusService
            ->expects($this->once())
            ->method('getStatus')
            ->willReturn(['running' => false]);

        $response = $this->controller->scanSiteAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanStatusActionReturns401WhenNotLoggedIn(): void
    {
        $GLOBALS['BE_USER'] = null;

        $this->backendUserService
            ->method('getBackendUser')
            ->willReturn(null);

        $this->scanStatusService->expects($this->never())->method('getStatus');

        $response = $this->controller->scanStatusAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function scanStatusActionReturnsStatusForLoggedInUser(): void
    {
        $this->mockLoggedInUser(1);

        $this->scanStatusService
            ->expects($this->once())
            ->method('getStatus')
            ->willReturn(['running' => false]);

        $response = $this->controller->scanStatusAction($this->request);

        self::assertSame($this->response, $response);
    }

    private function mockLoggedInUser(int $uid, string $username = 'admin'): BackendUserAuthentication
    {
        $beUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();

        $beUser->user = [
            'uid' => $uid,
            'username' => $username,
        ];

        $this->backendUserService
            ->method('isLoggedIn')
            ->willReturn(true);

        $this->backendUserService
            ->method('getBackendUser')
            ->willReturn($beUser);

        return $beUser;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['BE_USER']);
    }
}
