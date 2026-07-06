<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\OpenAi;

use GuzzleHttp\Utils;
use Priebera\A11yQualityGate\Ai\Contract\AiModelDiscoveryProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;
use Priebera\A11yQualityGate\Ai\Exception\AiModelDiscoveryException;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class OpenAiModelDiscoveryProvider implements AiModelDiscoveryProviderInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/models';
    private const MAX_MODELS = 1000;

    public function __construct(private readonly RequestFactory $requestFactory) {}

    public function supports(string $provider): bool
    {
        return strtolower(trim($provider)) === 'openai';
    }

    /** @return list<string> */
    public function listModelIds(AiProviderCredentials $credentials): array
    {
        try {
            $response = $this->requestFactory->request(self::ENDPOINT, 'GET', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $credentials->apiKey(),
                    'Accept' => 'application/json',
                ],
                'timeout' => 20,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $exception) {
            $this->logSafeFailure('openai_model_discovery_transport_failure', [
                'exception_class' => $this->boundedDiagnosticValue($exception::class),
                'exception_code' => (int)$exception->getCode(),
            ]);
            throw new AiModelDiscoveryException(
                'models_transport_failure',
                'AQG could not load the models available to this OpenAI project.',
                1771002700,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $error = $this->extractProviderError($response);
            $safeCode = $this->mapFailureCode($status, $error['type'], $error['code']);
            $this->logSafeFailure('openai_model_discovery_http_failure', [
                'http_status' => $status,
                'provider_error_type' => $error['type'],
                'provider_error_code' => $error['code'],
                'provider_error_param' => $error['param'],
                'request_id' => $this->boundedDiagnosticValue($response->getHeaderLine('x-request-id')),
            ]);

            $message = match ($safeCode) {
                'models_permission_denied' => 'AQG could not load the models available to this OpenAI project. Allow read access to the Models endpoint or use a key with the required project permissions.',
                'api_key_rejected' => 'The API key was rejected by OpenAI.',
                'insufficient_quota' => 'The OpenAI project has insufficient quota.',
                'models_rate_limited' => 'OpenAI rate-limited the model discovery request. Try again later.',
                'openai_service_failure' => 'OpenAI could not provide the available model list.',
                default => 'AQG could not load the models available to this OpenAI project.',
            };

            throw new AiModelDiscoveryException($safeCode, $message, 1771002701);
        }

        try {
            $decoded = Utils::jsonDecode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logSafeFailure('openai_model_discovery_invalid_json', [
                'request_id' => $this->boundedDiagnosticValue($response->getHeaderLine('x-request-id')),
            ]);
            throw new AiModelDiscoveryException(
                'models_invalid_response',
                'OpenAI returned an invalid model list.',
                1771002702,
                $exception,
            );
        }

        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            $this->logSafeFailure('openai_model_discovery_missing_data', [
                'request_id' => $this->boundedDiagnosticValue($response->getHeaderLine('x-request-id')),
            ]);
            throw new AiModelDiscoveryException(
                'models_invalid_response',
                'OpenAI returned an invalid model list.',
                1771002703,
            );
        }

        $modelIds = [];
        foreach (array_slice($decoded['data'], 0, self::MAX_MODELS) as $model) {
            if (!is_array($model)) {
                continue;
            }
            $modelId = trim((string)($model['id'] ?? ''));
            if (!$this->isSafeModelId($modelId)) {
                continue;
            }
            $modelIds[$modelId] = true;
        }

        $result = array_keys($modelIds);
        sort($result, SORT_STRING);

        return array_values($result);
    }

    /** @return array{type:string,code:string,param:string} */
    private function extractProviderError(ResponseInterface $response): array
    {
        try {
            $decoded = Utils::jsonDecode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['type' => '', 'code' => '', 'param' => ''];
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];

        return [
            'type' => $this->boundedDiagnosticValue($error['type'] ?? ''),
            'code' => $this->boundedDiagnosticValue($error['code'] ?? ''),
            'param' => $this->boundedDiagnosticValue($error['param'] ?? ''),
        ];
    }

    private function mapFailureCode(int $status, string $type, string $code): string
    {
        if ($status === 401) {
            return 'api_key_rejected';
        }
        if ($status === 403) {
            return 'models_permission_denied';
        }
        if ($status === 429 && ($code === 'insufficient_quota' || $type === 'insufficient_quota')) {
            return 'insufficient_quota';
        }
        if ($status === 429) {
            return 'models_rate_limited';
        }
        if ($status >= 500) {
            return 'openai_service_failure';
        }

        return 'models_request_failed';
    }

    private function isSafeModelId(string $modelId): bool
    {
        return $modelId !== ''
            && strlen($modelId) <= 100
            && preg_match('/^[a-zA-Z0-9._:-]+$/', $modelId) === 1;
    }

    private function boundedDiagnosticValue(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $value = trim((string)preg_replace('/[^a-zA-Z0-9._:-]+/', '_', (string)$value));

        return substr($value, 0, 80);
    }

    /** @param array<string,int|string> $metadata */
    private function logSafeFailure(string $event, array $metadata): void
    {
        $metadata['aqg_exception_code'] = (int)($metadata['aqg_exception_code'] ?? match ($event) {
            'openai_model_discovery_transport_failure' => 1771002700,
            'openai_model_discovery_http_failure' => 1771002701,
            'openai_model_discovery_invalid_json' => 1771002702,
            'openai_model_discovery_missing_data' => 1771002703,
            default => 0,
        });

        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->warning($event, array_merge([
                    'provider' => 'openai',
                    'endpoint' => 'models',
                    'aqg_exception_class' => AiModelDiscoveryException::class,
                ], $metadata));
        } catch (\Throwable) {
            // Diagnostics must never replace the safe failure path.
        }
    }
}
