<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\RemoteScreenshotController;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\ProNotConfiguredException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\RemoteScreenshotService;
use Priebera\A11yQualityGate\Service\RemoteScreenshotAccessPolicy;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * showAction answered licence, crawler and database failures with the raw exception message as
 * JSON, so crawler URLs, response bodies, token metadata and SQL errors reached the browser.
 */
final class RemoteScreenshotControllerTest extends TestCase
{
    private const LICENCE_MESSAGE = 'Screenshot is not available for the current licence.';
    private const FAILURE_MESSAGE = 'Screenshot could not be loaded.';

    private const INTERNAL_FRAGMENTS = [
        'api.priebera.sk',
        '/crawl/page/',
        'Bearer',
        'accessTokenLength',
        'aqg_live_',
        'body=',
        'SQLSTATE',
        '/var/www',
        'cURL',
    ];

    private RemoteScreenshotService $screenshotService;
    private RemoteScanRepository $remoteScanRepository;
    private RemoteScreenshotAccessPolicy $accessPolicy;

    /** @var AbstractLogger&object{records: list<array{level: mixed, message: string, context: array<string, mixed>}>} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->screenshotService = $this->createMock(RemoteScreenshotService::class);
        $this->remoteScanRepository = $this->createMock(RemoteScanRepository::class);
        $this->remoteScanRepository->method('findPageByUid')->willReturn(['uid' => 7, 'remote_scan' => 3]);
        $this->remoteScanRepository->method('findScanByUid')->willReturn(['uid' => 3, 'page_uid' => 12, 'site_identifier' => 'main']);
        $this->accessPolicy = $this->createMock(RemoteScreenshotAccessPolicy::class);
        $this->accessPolicy->method('canRead')->willReturn(true);

        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
            }
        };
        GeneralUtility::setSingletonInstance(LogManager::class, new class ($this->logger) extends LogManager {
            public function __construct(private readonly LoggerInterface $recordingLogger)
            {
                parent::__construct();
            }

            public function getLogger(string $name = ''): LoggerInterface
            {
                return $this->recordingLogger;
            }
        });
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: \Throwable, 1: int, 2: string}>
     */
    public static function failureProvider(): iterable
    {
        yield 'crawler rejected the token after a refresh' => [
            new TokenRefreshException(
                'Remote crawler request failed after token refresh: AQG crawler HTTP 401: invalid token'
                . ' | url=https://api.priebera.sk/crawl/page/9f1c/screenshot'
                . ' | auth={"authorizationHeaderType":"Bearer","accessTokenLength":403}'
                . ' | body={"error":{"message":"aqg_live_0123456789"}}'
            ),
            403,
            self::LICENCE_MESSAGE,
        ];
        yield 'licence API unreachable' => [
            new TokenRefreshException(
                'AQG API request failed.',
                0,
                new ApiRequestFailedException('cURL error 28: https://api.priebera.sk/auth/token timed out', 0, null, 'transport_error'),
            ),
            403,
            self::LICENCE_MESSAGE,
        ];
        yield 'no licence configured' => [
            new ProNotConfiguredException('AQG PRO licence key is not configured.'),
            403,
            self::LICENCE_MESSAGE,
        ];
        yield 'crawler unreachable' => [
            new \RuntimeException('cURL error 7: Failed to connect to api.priebera.sk port 443 for https://api.priebera.sk/crawl/page/9f1c/screenshot'),
            500,
            self::FAILURE_MESSAGE,
        ];
        yield 'database failure' => [
            new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused in /var/www/html/vendor/doctrine/dbal/src/Driver.php'),
            500,
            self::FAILURE_MESSAGE,
        ];
    }

    #[DataProvider('failureProvider')]
    #[Test]
    public function failuresReturnABoundedMessageAndKeepTheirStatus(\Throwable $failure, int $expectedStatus, string $expectedMessage): void
    {
        $this->screenshotService->method('fetchScreenshotOrState')->willThrowException($failure);

        $response = $this->subject()->showAction($this->request(7));

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => $expectedMessage], $this->decode($response));
        $this->assertNoInternals((string)$response->getBody(), $failure);
    }

    #[Test]
    public function theDiagnosticDetailIsLoggedServerSide(): void
    {
        $failure = new \RuntimeException('cURL error 28: Operation timed out for https://api.priebera.sk/crawl/page/9f1c/screenshot');
        $this->screenshotService->method('fetchScreenshotOrState')->willThrowException($failure);

        $this->subject()->showAction($this->request(7));

        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0]['level']);
        self::assertSame(7, $this->logger->records[0]['context']['remotePageUid']);
        self::assertSame(\RuntimeException::class, $this->logger->records[0]['context']['exceptionClass']);
        self::assertSame($failure->getMessage(), $this->logger->records[0]['context']['exceptionMessage']);
    }

    #[Test]
    public function aFailingAccessCheckFailsClosedWithoutDetails(): void
    {
        $policy = $this->createMock(RemoteScreenshotAccessPolicy::class);
        $policy->method('canRead')->willThrowException(
            new \RuntimeException('SQLSTATE[42S02]: Base table or view not found in /var/www/html/public')
        );
        $this->accessPolicy = $policy;
        $this->screenshotService->expects(self::never())->method('fetchScreenshotOrState');

        $response = $this->subject()->showAction($this->request(7));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => self::FAILURE_MESSAGE], $this->decode($response));
    }

    #[Test]
    public function deniedAccessStaysForbiddenWithoutFetching(): void
    {
        $policy = $this->createMock(RemoteScreenshotAccessPolicy::class);
        $policy->method('canRead')->willReturn(false);
        $this->accessPolicy = $policy;
        $this->screenshotService->expects(self::never())->method('fetchScreenshotOrState');

        $response = $this->subject()->showAction($this->request(7));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(['success' => false, 'error' => 'Access denied'], $this->decode($response));
    }

    #[Test]
    public function aScreenshotIsStillServedAsAnImage(): void
    {
        $this->screenshotService->method('fetchScreenshotOrState')->willReturn([
            'content' => "\x89PNG\r\n\x1a\nimage-bytes",
            'contentType' => 'image/png',
            'filename' => 'aqg-screenshot-7.png',
        ]);

        $response = $this->subject()->showAction($this->request(7));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('inline; filename="aqg-screenshot-7.png"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame("\x89PNG\r\n\x1a\nimage-bytes", (string)$response->getBody());
        self::assertSame([], $this->logger->records);
    }

    #[Test]
    public function aMissingScreenshotIsNotFound(): void
    {
        $this->screenshotService->method('fetchScreenshotOrState')->willReturn(['state' => 'not_captured']);

        $response = $this->subject()->showAction($this->request(7));

        self::assertSame(404, $response->getStatusCode());
        self::assertFalse($this->decode($response)['success']);
        self::assertSame('not_captured', $this->decode($response)['screenshotState']);
    }

    #[Test]
    public function aScreenshotDeletedAfterItsRetentionPeriodIsGoneWithItsReason(): void
    {
        $this->screenshotService->method('fetchScreenshotOrState')->willReturn(['state' => 'expired']);

        $response = $this->subject()->showAction($this->request(7));

        self::assertSame(410, $response->getStatusCode());
        self::assertSame('expired', $this->decode($response)['screenshotState']);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function aScreenshotThatCannotBeLoadedIsNeverReportedAsExpired(): void
    {
        $this->screenshotService->method('fetchScreenshotOrState')->willReturn(['state' => 'unavailable']);

        $response = $this->subject()->showAction($this->request(7));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('unavailable', $this->decode($response)['screenshotState']);
    }

    /**
     * The browser keeps a screenshot only for the backend user who loaded it: every view revalidates, and the ETag
     * names that user, so after Switch User or another login no cached image of someone else is shown.
     */
    #[Test]
    public function aScreenshotIsCachedPerBackendUserAndRevalidatedOnEveryView(): void
    {
        $this->screenshotService->expects(self::once())->method('fetchScreenshotOrState')->willReturn([
            'content' => "\x89PNG\r\n\x1a\nimage-bytes",
            'contentType' => 'image/png',
            'filename' => 'aqg-screenshot-7.png',
        ]);

        $first = $this->subject(5)->showAction($this->request(7));
        $etag = $first->getHeaderLine('ETag');

        self::assertSame('private, no-cache', $first->getHeaderLine('Cache-Control'));
        self::assertSame('Cookie', $first->getHeaderLine('Vary'));
        self::assertNotSame('', $etag);

        $revalidated = $this->subject(5)->showAction($this->request(7)->withHeader('If-None-Match', $etag));
        self::assertSame(304, $revalidated->getStatusCode(), 'the same user revalidates without another AQG request');
        self::assertSame('', (string)$revalidated->getBody());

        $otherUser = $this->subject(6);
        self::assertNotSame($etag, (string)(new \ReflectionMethod($otherUser, 'buildEtag'))->invoke($otherUser, 7, 'highlighted'));
    }

    #[Test]
    public function aRevalidationIsCheckedForAccessFirst(): void
    {
        $policy = $this->createMock(RemoteScreenshotAccessPolicy::class);
        $policy->method('canRead')->willReturn(false);
        $this->accessPolicy = $policy;
        $subject = $this->subject(5);
        $etag = (string)(new \ReflectionMethod($subject, 'buildEtag'))->invoke($subject, 7, 'highlighted');

        self::assertSame(403, $subject->showAction($this->request(7)->withHeader('If-None-Match', $etag))->getStatusCode());
    }

    private function subject(int $userUid = 5): RemoteScreenshotController
    {
        $backendUserService = $this->createMock(BackendUserService::class);
        $backendUserService->method('isLoggedIn')->willReturn(true);
        $backendUserService->method('getBackendUserUid')->willReturn($userUid);

        return new RemoteScreenshotController(
            $this->screenshotService,
            $this->accessPolicy,
            new ResponseFactory(),
            new StreamFactory(),
            $backendUserService,
        );
    }

    private function request(int $remotePageUid): ServerRequest
    {
        return (new ServerRequest('https://example.org/typo3/module/web/a11y/remote-screenshot', 'GET'))
            ->withQueryParams(['remotePageUid' => (string)$remotePageUid]);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function assertNoInternals(string $body, \Throwable $failure): void
    {
        self::assertStringNotContainsString($failure->getMessage(), $body);
        foreach (self::INTERNAL_FRAGMENTS as $fragment) {
            self::assertStringNotContainsString($fragment, $body);
        }
    }
}
