<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Anthropic;

use Priebera\A11yQualityGate\Ai\Contract\AiModelCatalogProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiDiscoveredModel;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderCredentials;
use Priebera\A11yQualityGate\Ai\Exception\AiModelDiscoveryException;
use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Lists the models an Anthropic API key can use (GET /v1/models, paginated) with the capabilities Anthropic reports.
 * Failures carry the same safe codes as OpenAI discovery; nothing falls back to a default model.
 */
final class AnthropicModelDiscoveryProvider implements AiModelCatalogProviderInterface
{
    private const ENDPOINT = AnthropicApi::BASE_URL . '/v1/models';
    private const PAGE_SIZE = 1000;
    private const MAX_PAGES = 5;

    public function __construct(private readonly RequestFactory $requestFactory) {}

    public function supports(string $provider): bool
    {
        return strtolower(trim($provider)) === AiProviderCatalog::ANTHROPIC;
    }

    public function listModelIds(AiProviderCredentials $credentials): array
    {
        return array_map(static fn (AiDiscoveredModel $model): string => $model->id, $this->listModels($credentials));
    }

    public function listModels(AiProviderCredentials $credentials): array
    {
        $models = [];
        $afterId = '';
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $decoded = $this->requestPage($credentials, $afterId);
            foreach ($decoded['data'] as $entry) {
                $model = $this->toModel($entry);
                if ($model !== null) {
                    $models[$model->id] = $model;
                }
            }

            $lastId = is_string($decoded['last_id'] ?? null) ? trim($decoded['last_id']) : '';
            if (($decoded['has_more'] ?? false) !== true || !$this->isSafeModelId($lastId) || $lastId === $afterId) {
                break;
            }
            $afterId = $lastId;
        }

        ksort($models, SORT_STRING);

        return array_values($models);
    }

    /** @return array<string,mixed> */
    private function requestPage(AiProviderCredentials $credentials, string $afterId): array
    {
        $query = ['limit' => self::PAGE_SIZE];
        if ($afterId !== '') {
            $query['after_id'] = $afterId;
        }

        try {
            $response = $this->requestFactory->request(self::ENDPOINT . '?' . http_build_query($query), 'GET', [
                'headers' => AnthropicApi::headers($credentials->apiKey(), false),
                'timeout' => 20,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $exception) {
            AnthropicApi::logSafeFailure(self::class, 'anthropic_model_discovery_transport_failure', [
                'exception_class' => AnthropicApi::boundedDiagnosticValue($exception::class),
                'aqg_exception_code' => 1771002720,
            ]);
            // Never keep the transport exception: it can carry request headers.
            throw new AiModelDiscoveryException(
                'models_transport_failure',
                'AQG could not load the models available to this Anthropic account.',
                1771002720,
            );
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $error = AnthropicApi::extractError($response);
            $safeCode = $this->mapFailureCode($status, $error['type']);
            AnthropicApi::logSafeFailure(self::class, 'anthropic_model_discovery_http_failure', [
                'http_status' => $status,
                'provider_error_type' => $error['type'],
                'request_id' => $error['requestId'],
                'aqg_exception_code' => 1771002721,
            ]);

            throw new AiModelDiscoveryException($safeCode, 'Anthropic did not provide the model list.', 1771002721);
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AiModelDiscoveryException(
                'models_invalid_response',
                'Anthropic returned an invalid model list.',
                1771002722,
                $exception,
            );
        }
        if (!is_array($decoded) || !is_array($decoded['data'] ?? null)) {
            throw new AiModelDiscoveryException(
                'models_invalid_response',
                'Anthropic returned an invalid model list.',
                1771002723,
            );
        }

        return $decoded;
    }

    private function toModel(mixed $entry): ?AiDiscoveredModel
    {
        if (!is_array($entry) || ($entry['type'] ?? 'model') !== 'model') {
            return null;
        }
        $id = trim((string)($entry['id'] ?? ''));
        if (!$this->isSafeModelId($id)) {
            return null;
        }
        $capabilities = is_array($entry['capabilities'] ?? null) ? $entry['capabilities'] : [];

        return new AiDiscoveredModel(
            id: $id,
            imageInput: $this->supported($capabilities['image_input'] ?? null),
            structuredOutputs: $this->supported($capabilities['structured_outputs'] ?? null),
            maxInputTokens: is_int($entry['max_input_tokens'] ?? null) ? max(0, $entry['max_input_tokens']) : 0,
        );
    }

    private function supported(mixed $capability): ?bool
    {
        return is_array($capability) && is_bool($capability['supported'] ?? null) ? $capability['supported'] : null;
    }

    private function mapFailureCode(int $status, string $type): string
    {
        return match (true) {
            $status === 401 => 'api_key_rejected',
            $status === 403 => 'models_permission_denied',
            $status === 402, $type === 'billing_error' => 'provider_billing_error',
            $status === 429 => 'models_rate_limited',
            $status >= 500 => 'provider_service_failure',
            default => 'models_request_failed',
        };
    }

    private function isSafeModelId(string $modelId): bool
    {
        return $modelId !== ''
            && strlen($modelId) <= 100
            && preg_match('/^[a-zA-Z0-9._:-]+$/', $modelId) === 1;
    }
}
