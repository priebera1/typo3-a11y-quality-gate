<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\OpenAi;

use GuzzleHttp\Utils;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class OpenAiResponsesProvider implements AiProviderInterface
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';
    private const MAX_OUTPUT_TOKENS = 180;
    private const IMAGE_DETAIL = 'low';
    private const CONNECTION_TEST_IMAGE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAGklEQVR42mP8//8/AymAiYFEMKphVMPQ0QAAVW0DHfeH1GIAAAAASUVORK5CYII=';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly AiModelCompatibilityRegistry $modelRegistry,
    ) {}

    public function supports(string $provider): bool
    {
        return strtolower(trim($provider)) === 'openai';
    }

    public function suggestAltText(
        AiAltSuggestionRequest $request,
        AiProviderConfiguration $configuration,
    ): AiAltSuggestionResult {
        $model = trim($configuration->model);
        $profile = $this->modelRegistry->require($model);

        try {
            $contextJson = Utils::jsonEncode(
                $request->contextPayload(),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (\JsonException $exception) {
            throw new AiProviderException('The AI request context could not be encoded.', 1771002302, $exception);
        }

        $payload = $this->buildRequestPayload(
            model: $model,
            profile: $profile,
            instructions: AiPromptDefinition::DEVELOPER_INSTRUCTIONS,
            inputText: AiPromptDefinition::CONTEXT_WRAPPER_PREFIX . "\n" . $contextJson,
            imageDataUrl: $request->dataUrl,
            metadata: [
                'aqg_prompt_version' => AiPromptDefinition::AI_PROMPT_VERSION,
                'aqg_model_registry_version' => AiModelCompatibilityRegistry::VERSION,
            ],
            structuredOutputFormat: AiPromptDefinition::structuredOutputFormat(),
        );

        $decoded = $this->request($payload, $configuration->apiKey(), $model);
        $structured = $this->parseAndValidateStructuredResponse($decoded, $model);

        return new AiAltSuggestionResult(
            status: $structured['status'],
            suggestion: $structured['alt_text'],
            provider: 'openai',
            model: $model,
            promptVersion: AiPromptDefinition::AI_PROMPT_VERSION,
        );
    }

    public function testConnection(AiProviderConfiguration $configuration): void
    {
        $model = trim($configuration->model);
        $profile = $this->modelRegistry->require($model);

        $payload = $this->buildRequestPayload(
            model: $model,
            profile: $profile,
            instructions: AiPromptDefinition::CONNECTION_TEST_INSTRUCTIONS,
            inputText: 'AQG connection contract test. Inspect the supplied image input.',
            imageDataUrl: self::CONNECTION_TEST_IMAGE,
            metadata: [
                'aqg_prompt_version' => AiPromptDefinition::AI_PROMPT_VERSION,
                'aqg_test_version' => AiPromptDefinition::CONNECTION_TEST_VERSION,
                'aqg_model_registry_version' => AiModelCompatibilityRegistry::VERSION,
            ],
            structuredOutputFormat: AiPromptDefinition::connectionTestStructuredOutputFormat(),
        );

        $decoded = $this->request($payload, $configuration->apiKey(), $model);
        $structured = $this->parseAndValidateStructuredResponse($decoded, $model);
        if ($structured['status'] !== AiAltSuggestionResult::STATUS_SUGGESTION
            || $structured['alt_text'] !== 'AQG_TEST_OK') {
            $this->logSafeFailure('openai_connection_contract_mismatch', $model);
            throw new AiProviderException('OpenAI returned an invalid structured-output test response.', 1771002318);
        }
    }

    /**
     * @param array<string,mixed> $profile
     * @param array<string,string> $metadata
     * @param array<string,mixed> $structuredOutputFormat
     * @return array<string,mixed>
     */
    private function buildRequestPayload(
        string $model,
        array $profile,
        string $instructions,
        string $inputText,
        string $imageDataUrl,
        array $metadata,
        array $structuredOutputFormat,
    ): array {
        if (!in_array(self::IMAGE_DETAIL, (array)($profile['imageDetail'] ?? []), true)) {
            throw new \InvalidArgumentException('The selected model does not support the AQG image detail profile.');
        }

        $payload = [
            'model' => $model,
            'store' => false,
            'metadata' => $metadata,
            'instructions' => $instructions,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
            'text' => [
                'format' => $structuredOutputFormat,
            ],
            'input' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $inputText,
                    ],
                    [
                        'type' => 'input_image',
                        'image_url' => $imageDataUrl,
                        'detail' => self::IMAGE_DETAIL,
                    ],
                ],
            ]],
        ];

        if (($profile['reasoningParameter'] ?? false) === true) {
            $efforts = (array)($profile['supportedReasoningEfforts'] ?? []);
            if (!in_array('none', $efforts, true)) {
                throw new \InvalidArgumentException('The selected model does not support the AQG reasoning profile.');
            }
            $payload['reasoning'] = ['effort' => 'none'];
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function request(
        array $payload,
        #[\SensitiveParameter] string $apiKey,
        string $model,
    ): array {
        try {
            $body = Utils::jsonEncode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AiProviderException('The AI provider request could not be encoded.', 1771002302, $exception);
        }

        try {
            $response = $this->requestFactory->request(self::ENDPOINT, 'POST', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'body' => $body,
                'timeout' => 30,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $exception) {
            $this->logSafeFailure('openai_transport_failure', $model, [
                'exception_class' => $this->boundedDiagnosticValue($exception::class),
                'exception_code' => (int)$exception->getCode(),
            ]);
            // Never retain the transport exception: it can contain request headers or body.
            throw new AiProviderException('The OpenAI transport request failed.', 1771002302);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $error = $this->extractProviderError($response);
            [$message, $exceptionCode] = $this->mapHttpFailure($status, $error['type'], $error['code']);
            $this->logSafeFailure('openai_http_failure', $model, [
                'http_status' => $status,
                'provider_error_type' => $error['type'],
                'provider_error_code' => $error['code'],
                'provider_error_param' => $error['param'],
                'request_id' => $this->boundedDiagnosticValue($response->getHeaderLine('x-request-id')),
                'aqg_exception_code' => $exceptionCode,
            ]);

            throw new AiProviderException($message, $exceptionCode);
        }

        try {
            $decoded = Utils::jsonDecode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logSafeFailure('openai_invalid_json', $model, [
                'http_status' => $status,
                'request_id' => $this->boundedDiagnosticValue($response->getHeaderLine('x-request-id')),
            ]);
            throw new AiProviderException('OpenAI returned an invalid structured-output test response.', 1771002304, $exception);
        }
        if (!is_array($decoded)) {
            $this->logSafeFailure('openai_invalid_response', $model, ['http_status' => $status]);
            throw new AiProviderException('OpenAI returned an invalid structured-output test response.', 1771002305);
        }

        return $decoded;
    }

    /** @param array<string,mixed> $response @return array{status:string,alt_text:string} */
    private function parseAndValidateStructuredResponse(array $response, string $model): array
    {
        $this->assertCompletedWithoutRefusal($response, $model);
        $structured = $this->extractStructuredOutput($response, $model);
        $this->assertCrossFieldContract($structured, $model);

        return $structured;
    }

    /** @param array<string,mixed> $response */
    private function assertCompletedWithoutRefusal(array $response, string $model): void
    {
        if ($this->containsRefusal($response)) {
            $this->logSafeFailure('openai_refusal', $model, [
                'response_status' => $this->boundedDiagnosticValue($response['status'] ?? ''),
            ]);
            throw new AiProviderException('OpenAI returned an invalid structured-output test response.', 1771002313);
        }

        if (($response['status'] ?? null) !== 'completed') {
            $this->logSafeFailure('openai_incomplete_response', $model, [
                'response_status' => $this->boundedDiagnosticValue($response['status'] ?? ''),
                'incomplete_reason' => is_array($response['incomplete_details'] ?? null)
                    ? $this->boundedDiagnosticValue($response['incomplete_details']['reason'] ?? '')
                    : '',
            ]);
            throw new AiProviderException('OpenAI returned an invalid structured-output test response.', 1771002314);
        }
    }

    /** @param array<string,mixed> $response @return array{status:string,alt_text:string} */
    private function extractStructuredOutput(array $response, string $model): array
    {
        $raw = $this->extractOutputText($response);
        if ($raw === '') {
            $this->logSafeFailure('openai_empty_structured_output', $model);
            throw new AiProviderException('OpenAI returned an invalid structured-output test response.', 1771002301);
        }

        try {
            $structured = Utils::jsonDecode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logSafeFailure('openai_invalid_structured_output', $model);
            throw new AiProviderException('OpenAI returned an invalid structured-output test response.', 1771002315, $exception);
        }

        $keys = is_array($structured) ? array_keys($structured) : [];
        sort($keys);
        if (!is_array($structured)
            || $keys !== ['alt_text', 'status']
            || !is_string($structured['status'] ?? null)
            || !is_string($structured['alt_text'] ?? null)
            || !in_array($structured['status'], [
                AiAltSuggestionResult::STATUS_SUGGESTION,
                AiAltSuggestionResult::STATUS_NEEDS_REVIEW,
            ], true)) {
            $this->logSafeFailure('openai_schema_mismatch', $model);
            throw new AiProviderException('OpenAI returned an invalid structured-output test response.', 1771002316);
        }

        return [
            'status' => $structured['status'],
            'alt_text' => $structured['alt_text'],
        ];
    }

    /** @param array{status:string,alt_text:string} $structured */
    private function assertCrossFieldContract(array $structured, string $model): void
    {
        $validSuggestion = $structured['status'] === AiAltSuggestionResult::STATUS_SUGGESTION
            && trim($structured['alt_text']) !== '';
        $validNeedsReview = $structured['status'] === AiAltSuggestionResult::STATUS_NEEDS_REVIEW
            && $structured['alt_text'] === '';
        if ($validSuggestion || $validNeedsReview) {
            return;
        }

        $this->logSafeFailure('openai_cross_field_contract_mismatch', $model);
        throw new AiProviderException('OpenAI returned an invalid structured-output test response.', 1771002318);
    }

    /** @param array<string,mixed> $response */
    private function containsRefusal(array $response): bool
    {
        foreach ((array)($response['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array)($item['content'] ?? []) as $content) {
                if (is_array($content) && ($content['type'] ?? '') === 'refusal') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string,mixed> $response */
    private function extractOutputText(array $response): string
    {
        $outputText = trim((string)($response['output_text'] ?? ''));
        if ($outputText !== '') {
            return $outputText;
        }

        foreach ((array)($response['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            foreach ((array)($item['content'] ?? []) as $content) {
                if (is_array($content) && ($content['type'] ?? '') === 'output_text') {
                    $text = trim((string)($content['text'] ?? ''));
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return '';
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

    /** @return array{0:string,1:int} */
    private function mapHttpFailure(int $status, string $type, string $code): array
    {
        if ($status === 401) {
            return ['The API key was rejected by OpenAI.', 1771002310];
        }
        if ($status === 429 && ($code === 'insufficient_quota' || $type === 'insufficient_quota')) {
            return ['The OpenAI project has insufficient quota.', 1771002320];
        }
        if ($status === 429) {
            return ['OpenAI rate-limited the connection test. Try again later.', 1771002311];
        }
        if ($status === 403) {
            return ['The selected model is not permitted by this OpenAI project.', 1771002321];
        }
        if ($status === 404 || in_array($code, ['model_not_found', 'model_not_available', 'unsupported_model'], true)) {
            return ['The selected OpenAI model is unavailable.', 1771002317];
        }
        if ($status === 400 || $type === 'invalid_request_error') {
            return ['OpenAI rejected the AQG request contract.', 1771002322];
        }
        if ($status >= 500) {
            return ['OpenAI could not complete the request.', 1771002312];
        }

        return ['The OpenAI connection could not be verified.', 1771002303];
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
    private function logSafeFailure(string $event, string $model, array $metadata = []): void
    {
        $exceptionCode = (int)($metadata['aqg_exception_code'] ?? $this->diagnosticExceptionCode($event));
        $metadata['aqg_exception_code'] = $exceptionCode;

        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->warning($event, array_merge([
                    'provider' => 'openai',
                    'requested_model_id' => $model,
                    'prompt_version' => AiPromptDefinition::AI_PROMPT_VERSION,
                    'connection_contract_version' => AiPromptDefinition::CONNECTION_TEST_VERSION,
                    'aqg_exception_class' => AiProviderException::class,
                ], $metadata));
        } catch (\Throwable) {
            // Logging must never replace the original safe error path.
        }
    }

    private function diagnosticExceptionCode(string $event): int
    {
        return match ($event) {
            'openai_transport_failure' => 1771002302,
            'openai_invalid_json' => 1771002304,
            'openai_invalid_response' => 1771002305,
            'openai_refusal' => 1771002313,
            'openai_incomplete_response' => 1771002314,
            'openai_empty_structured_output' => 1771002301,
            'openai_invalid_structured_output' => 1771002315,
            'openai_schema_mismatch' => 1771002316,
            'openai_cross_field_contract_mismatch',
            'openai_connection_contract_mismatch' => 1771002318,
            default => 0,
        };
    }
}
