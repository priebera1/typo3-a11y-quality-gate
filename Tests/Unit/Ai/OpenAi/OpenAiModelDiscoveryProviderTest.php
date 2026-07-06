<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\OpenAi;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;
use Priebera\A11yQualityGate\Ai\Exception\AiModelDiscoveryException;
use Priebera\A11yQualityGate\Ai\OpenAi\OpenAiModelDiscoveryProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class OpenAiModelDiscoveryProviderTest extends TestCase
{
    #[Test]
    public function successfulResponseReturnsSafeUniqueSortedModelIds(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                'https://api.openai.com/v1/models',
                'GET',
                self::callback(static function (array $options): bool {
                    return $options['headers']['Authorization'] === 'Bearer secret-key'
                        && $options['allow_redirects'] === false;
                }),
            )
            ->willReturn($this->response(200, json_encode([
                'data' => [
                    ['id' => 'gpt-5.4-mini'],
                    ['id' => 'gpt-4.1-mini'],
                    ['id' => 'gpt-5.4-mini'],
                    ['id' => 'invalid model id'],
                    ['object' => 'model'],
                ],
            ], JSON_THROW_ON_ERROR)));

        $result = (new OpenAiModelDiscoveryProvider($factory))->listModelIds($this->credentials());

        self::assertSame(['gpt-4.1-mini', 'gpt-5.4-mini'], $result);
    }

    #[DataProvider('failureProvider')]
    #[Test]
    public function providerFailuresAreSafelyClassified(int $status, array $body, string $safeCode): void
    {
        $subject = new OpenAiModelDiscoveryProvider(
            $this->factory($status, json_encode($body, JSON_THROW_ON_ERROR)),
        );

        try {
            $subject->listModelIds($this->credentials());
            self::fail('Expected model discovery exception.');
        } catch (AiModelDiscoveryException $exception) {
            self::assertSame($safeCode, $exception->safeCode);
            self::assertStringNotContainsString('secret-key', $exception->getMessage());
            self::assertStringNotContainsString('provider-secret', $exception->getMessage());
        }
    }

    public static function failureProvider(): iterable
    {
        yield '401' => [401, ['error' => ['message' => 'provider-secret']], 'api_key_rejected'];
        yield '403 restricted models endpoint' => [403, ['error' => ['type' => 'permission_error']], 'models_permission_denied'];
        yield '429 rate limited' => [429, ['error' => ['code' => 'rate_limit_exceeded']], 'models_rate_limited'];
        yield '429 quota' => [429, ['error' => ['code' => 'insufficient_quota']], 'insufficient_quota'];
        yield '5xx' => [503, ['error' => ['message' => 'provider-secret']], 'openai_service_failure'];
    }

    #[DataProvider('invalidResponseProvider')]
    #[Test]
    public function invalidResponseIsRejected(string $body): void
    {
        $this->expectException(AiModelDiscoveryException::class);
        $this->expectExceptionCode(1771002702);

        (new OpenAiModelDiscoveryProvider($this->factory(200, $body)))
            ->listModelIds($this->credentials());
    }

    public static function invalidResponseProvider(): iterable
    {
        yield 'invalid JSON' => ['{broken'];
    }

    #[Test]
    public function missingDataIsRejected(): void
    {
        try {
            (new OpenAiModelDiscoveryProvider($this->factory(200, '{}')))
                ->listModelIds($this->credentials());
            self::fail('Expected model discovery exception.');
        } catch (AiModelDiscoveryException $exception) {
            self::assertSame('models_invalid_response', $exception->safeCode);
            self::assertSame(1771002703, $exception->getCode());
        }
    }

    private function credentials(): AiProviderCredentials
    {
        return new AiProviderCredentials('openai', 'secret-key', 'site', 'fingerprint');
    }

    private function factory(int $status, string $body): RequestFactory
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->response($status, $body));

        return $factory;
    }

    private function response(int $status, string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturn('req_safe_123');

        return $response;
    }
}
