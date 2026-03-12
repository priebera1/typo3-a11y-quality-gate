<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\IssueApiController;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class IssueApiControllerTest extends TestCase
{
    private IssueApiController $controller;
    private IssueRepository $issueRepo;
    private BackendUserService $backendUserService;
    private ServerRequestInterface $request;
    private ResponseInterface $response;

    protected function setUp(): void
    {
        $this->issueRepo = $this->createMock(IssueRepository::class);

        $stream = $this->createMock(StreamInterface::class);

        $this->response = $this->createMock(ResponseInterface::class);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withBody')->willReturnSelf();

        $responseFactory = $this->createMock(ResponseFactoryInterface::class);
        $responseFactory->method('createResponse')->willReturn($this->response);

        $streamFactory = $this->createMock(StreamFactoryInterface::class);
        $streamFactory->method('createStream')->willReturn($stream);

        $this->backendUserService = $this->createMock(BackendUserService::class);

        $this->controller = new IssueApiController(
            $this->issueRepo,
            $responseFactory,
            $streamFactory,
            $this->backendUserService,
        );

        $this->request = $this->createMock(ServerRequestInterface::class);
    }

    #[Test]
    public function issuesActionReturns403WhenNotLoggedIn(): void
    {
        $this->backendUserService
            ->expects($this->once())
            ->method('isLoggedIn')
            ->willReturn(false);

        $this->request->method('getQueryParams')->willReturn(['recordUid' => '1']);

        $this->issueRepo->expects($this->never())->method('findOpenForRecord');

        $response = $this->controller->issuesAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function issuesActionReturns400WhenRecordUidMissing(): void
    {
        $this->mockLoggedInUser(42);

        $this->request->method('getQueryParams')->willReturn([]);

        $this->issueRepo->expects($this->never())->method('findOpenForRecord');

        $response = $this->controller->issuesAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function issuesActionCallsRepositoryWithCorrectParams(): void
    {
        $this->mockLoggedInUser(42);

        $this->request->method('getQueryParams')->willReturn([
            'recordUid' => '99',
            'fieldName' => 'bodytext',
        ]);

        $this->issueRepo
            ->expects($this->once())
            ->method('findOpenForRecord')
            ->with('tt_content', 99, 'bodytext')
            ->willReturn([]);

        $response = $this->controller->issuesAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function issuesActionUsesDefaultFieldNameBodytext(): void
    {
        $this->mockLoggedInUser(42);

        $this->request->method('getQueryParams')->willReturn([
            'recordUid' => '5',
        ]);

        $this->issueRepo
            ->expects($this->once())
            ->method('findOpenForRecord')
            ->with('tt_content', 5, 'bodytext')
            ->willReturn([]);

        $response = $this->controller->issuesAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function ignoreActionReturns405ForGetRequest(): void
    {
        $this->mockLoggedInUser(42);

        $this->request->method('getMethod')->willReturn('GET');
        $this->request->method('getBody')->willReturn($this->mockStream('{}'));

        $this->issueRepo->expects($this->never())->method('ignore');

        $response = $this->controller->ignoreAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function ignoreActionReturns400WhenFingerprintMissing(): void
    {
        $this->mockLoggedInUser(42);

        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getBody')->willReturn($this->mockStream('{}'));

        $this->issueRepo->expects($this->never())->method('findByFingerprintPublic');

        $response = $this->controller->ignoreAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function ignoreActionReturns404WhenIssueNotFound(): void
    {
        $this->mockLoggedInUser(42);

        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getBody')->willReturn(
            $this->mockStream('{"fingerprint":"abc123"}')
        );

        $this->issueRepo
            ->expects($this->once())
            ->method('findByFingerprintPublic')
            ->with('abc123')
            ->willReturn(null);

        $this->issueRepo->expects($this->never())->method('ignore');

        $response = $this->controller->ignoreAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function ignoreActionReturns409WhenIssueAlreadyIgnored(): void
    {
        $this->mockLoggedInUser(42);

        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getBody')->willReturn(
            $this->mockStream('{"fingerprint":"abc123"}')
        );

        $this->issueRepo
            ->expects($this->once())
            ->method('findByFingerprintPublic')
            ->with('abc123')
            ->willReturn([
                'uid' => 1,
                'status' => IssueStatus::Ignored->value,
                'site_identifier' => 'test',
            ]);

        $this->issueRepo->expects($this->never())->method('ignore');

        $response = $this->controller->ignoreAction($this->request);

        self::assertSame($this->response, $response);
    }

    #[Test]
    public function ignoreActionCallsIgnoreWhenValid(): void
    {
        $this->mockLoggedInUser(99);

        $this->request->method('getMethod')->willReturn('POST');
        $this->request->method('getBody')->willReturn(
            $this->mockStream('{"fingerprint":"abc123","reason":"Intentional design"}')
        );

        $this->issueRepo
            ->expects($this->once())
            ->method('findByFingerprintPublic')
            ->with('abc123')
            ->willReturn([
                'uid' => 7,
                'status' => IssueStatus::Open->value,
                'site_identifier' => 'test',
            ]);

        $this->issueRepo
            ->expects($this->once())
            ->method('ignore')
            ->with(7, 'Intentional design', 99);

        $response = $this->controller->ignoreAction($this->request);

        self::assertSame($this->response, $response);
    }

    private function mockLoggedInUser(int $uid): void
    {
        $beUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->getMock();

        $beUser->user = ['uid' => $uid];

        $this->backendUserService
            ->expects($this->any())
            ->method('isLoggedIn')
            ->willReturn(true);

        $this->backendUserService
            ->expects($this->any())
            ->method('getBackendUser')
            ->willReturn($beUser);

        $this->backendUserService
            ->expects($this->any())
            ->method('getBackendUserUid')
            ->willReturn($uid);
    }

    private function mockStream(string $content): StreamInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($content);
        $stream->method('getContents')->willReturn($content);

        return $stream;
    }
}
