<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Anthropic;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Anthropic\AnthropicModelDiscoveryProvider;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;
use Priebera\A11yQualityGate\Ai\Exception\AiModelDiscoveryException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class AnthropicModelDiscoveryProviderTest extends TestCase
{
    #[Test]
    public function modelsAreListedWithReportedCapabilitiesAcrossPages(): void
    {
        $urls = [];
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::exactly(2))->method('request')->willReturnCallback(
            function (string $url, string $method, array $options) use (&$urls): ResponseInterface {
                $urls[] = $url;
                self::assertSame('GET', $method);
                self::assertSame('sk-ant-secret', $options['headers']['x-api-key']);
                self::assertSame('2023-06-01', $options['headers']['anthropic-version']);
                self::assertFalse($options['allow_redirects']);

                return count($urls) === 1
                    ? $this->response(200, [
                        'data' => [
                            $this->model('claude-sonnet-5-5', true, true),
                            $this->model('claude-legacy-text', false, true),
                            ['type' => 'model', 'id' => 'bad id with spaces'],
                        ],
                        'has_more' => true,
                        'last_id' => 'claude-legacy-text',
                    ])
                    : $this->response(200, [
                        'data' => [['type' => 'model', 'id' => 'claude-haiku-5-5']],
                        'has_more' => false,
                        'last_id' => 'claude-haiku-5-5',
                    ]);
            },
        );

        $models = (new AnthropicModelDiscoveryProvider($factory))->listModels($this->credentials());

        self::assertSame('https://api.anthropic.com/v1/models?limit=1000', $urls[0]);
        self::assertSame('https://api.anthropic.com/v1/models?limit=1000&after_id=claude-legacy-text', $urls[1]);
        self::assertSame(['claude-haiku-5-5', 'claude-legacy-text', 'claude-sonnet-5-5'], array_map(static fn ($model) => $model->id, $models));
        self::assertNull($models[0]->imageInput, 'Unreported capabilities stay unknown.');
        self::assertFalse($models[1]->imageInput);
        self::assertTrue($models[2]->structuredOutputs);
        self::assertSame(1000000, $models[2]->maxInputTokens);
    }

    #[Test]
    #[DataProvider('failures')]
    public function failuresUseSafeCodesAndNeverFallBack(int $status, string $type, string $expectedSafeCode): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->response($status, ['type' => 'error', 'error' => ['type' => $type, 'message' => 'x']]));

        try {
            (new AnthropicModelDiscoveryProvider($factory))->listModelIds($this->credentials());
            self::fail('Expected a discovery failure.');
        } catch (AiModelDiscoveryException $exception) {
            self::assertSame($expectedSafeCode, $exception->safeCode);
        }
    }

    /** @return iterable<string,array{int,string,string}> */
    public static function failures(): iterable
    {
        yield 'invalid key' => [401, 'authentication_error', 'api_key_rejected'];
        yield 'permission' => [403, 'permission_error', 'models_permission_denied'];
        yield 'billing' => [402, 'billing_error', 'provider_billing_error'];
        yield 'rate limit' => [429, 'rate_limit_error', 'models_rate_limited'];
        yield 'server' => [500, 'api_error', 'provider_service_failure'];
        yield 'overloaded' => [529, 'overloaded_error', 'provider_service_failure'];
        yield 'other' => [400, 'invalid_request_error', 'models_request_failed'];
    }

    #[Test]
    public function invalidListAndTransportFailureAreSafe(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->response(200, ['models' => []]));
        try {
            (new AnthropicModelDiscoveryProvider($factory))->listModelIds($this->credentials());
            self::fail('Expected an invalid list.');
        } catch (AiModelDiscoveryException $exception) {
            self::assertSame('models_invalid_response', $exception->safeCode);
        }

        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willThrowException(new \RuntimeException('header x-api-key: sk-ant-secret'));
        try {
            (new AnthropicModelDiscoveryProvider($factory))->listModelIds($this->credentials());
            self::fail('Expected a transport failure.');
        } catch (AiModelDiscoveryException $exception) {
            self::assertSame('models_transport_failure', $exception->safeCode);
            self::assertNull($exception->getPrevious());
        }
    }

    /** @return array<string,mixed> */
    private function model(string $id, bool $images, bool $structured): array
    {
        return [
            'type' => 'model',
            'id' => $id,
            'display_name' => $id,
            'max_input_tokens' => 1000000,
            'capabilities' => [
                'image_input' => ['supported' => $images],
                'structured_outputs' => ['supported' => $structured],
            ],
        ];
    }

    private function credentials(): AiProviderCredentials
    {
        return new AiProviderCredentials('anthropic', 'sk-ant-secret', 'site', 'fp');
    }

    /** @param array<string,mixed> $payload */
    private function response(int $status, array $payload): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn(json_encode($payload, JSON_THROW_ON_ERROR));
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        $response->method('getHeaderLine')->willReturn('');

        return $response;
    }
}
