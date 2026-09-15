<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiModelCacheCodec;
use Priebera\A11yQualityGate\Controller\IssueApiController;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Scan\ContentHashCalculator;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\StreamFactory;

/**
 * Runs AQG's JSON-handling paths with Guzzle 8's `GuzzleHttp\Utils`, which has no JSON helpers,
 * loaded in place of Guzzle 7's. TYPO3 13.4.35+ and 14.3.7+ accept Guzzle 8, so each path must work
 * without them: content hashing during a local scan, the AQG API client used for licence and Free
 * Remote Preview tokens, the AI model cache and the JSON request bodies of the issue API.
 */
final class Guzzle8RuntimeCompatibilityTest extends TestCase
{
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function localScanContentHashingNeedsNoGuzzleJsonHelpers(): void
    {
        $this->loadGuzzle8Utils();

        self::assertSame(
            sha1('{"alt":"Grüße/Bild","num":3}'),
            (new ContentHashCalculator())->forStructuredField(['alt' => 'Grüße/Bild', 'num' => 3])
        );
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function aqgApiClientEncodesRequestsAndDecodesResponsesWithoutGuzzleJsonHelpers(): void
    {
        $this->loadGuzzle8Utils();
        $sentBody = '';
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturnCallback(
            function (string $url, string $method, array $options) use (&$sentBody): ResponseInterface {
                $sentBody = (string)($options['body'] ?? '');

                return $this->response(
                    '{"plan":"free","entitlement":"free_daily","access_token":"jwt","expires_in":3600,'
                    . '"features":["crawler"],"capabilities":["crawler_submit"]}'
                );
            }
        );

        $token = (new AqgApiClient($factory))->issueFreeToken('anonymous-id', 'https://example.test/', 'main', '1.9.3');

        self::assertSame(
            ['installationId' => 'anonymous-id', 'siteUrl' => 'https://example.test/', 'siteIdentifier' => 'main', 'version' => '1.9.3'],
            json_decode($sentBody, true, 512, JSON_THROW_ON_ERROR)
        );
        self::assertTrue($token->success);
        self::assertSame('free_daily', $token->entitlement);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function aiModelCacheRoundTripNeedsNoGuzzleJsonHelpers(): void
    {
        $this->loadGuzzle8Utils();
        $codec = new AiModelCacheCodec();
        $fingerprint = str_repeat('a', 64);

        $decoded = $codec->decode($codec->encode([['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini']], ['gpt-audio-1'], $fingerprint));

        self::assertTrue($decoded['valid']);
        self::assertSame($fingerprint, $decoded['keyFingerprint']);
        self::assertSame([['id' => 'gpt-4.1-mini', 'label' => 'GPT-4.1 mini']], $decoded['supported']);
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function issueApiDecodesJsonRequestBodiesWithoutGuzzleJsonHelpers(): void
    {
        $this->loadGuzzle8Utils();
        $backendUserService = $this->createMock(BackendUserService::class);
        $backendUserService->method('isLoggedIn')->willReturn(true);
        $controller = new IssueApiController(
            $this->createMock(IssueRepository::class),
            $this->createMock(RuleRegistry::class),
            $this->createMock(RuleConfigurationService::class),
            $this->createMock(ConnectionPool::class),
            $this->createMock(SiteResolutionService::class),
            $this->createMock(BackendRecordAccessService::class),
            new ResponseFactory(),
            new StreamFactory(),
            $backendUserService,
        );
        $body = $this->createMock(StreamInterface::class);
        $body->method('__toString')->willReturn('{"fingerprint":""}');
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getBody')->willReturn($body);

        $response = $controller->ignoreAction($request);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('{"success":false,"error":"Missing fingerprint"}', (string)$response->getBody());
    }

    private function loadGuzzle8Utils(): void
    {
        self::assertFalse(class_exists('GuzzleHttp\\Utils', false), 'Guzzle\'s own Utils class is already loaded.');
        require_once __DIR__ . '/../../Fixtures/Guzzle8Utils.php';
    }

    private function response(string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(200);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturn('');

        return $response;
    }
}
