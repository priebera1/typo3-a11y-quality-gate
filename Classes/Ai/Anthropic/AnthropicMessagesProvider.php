<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Anthropic;

use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;
use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionResult;
use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionResult;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiIframeTitleSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Task\AiImageInput;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredOutputContract;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredResponse;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskExecutorInterface;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Anthropic Claude through the Messages API (POST /v1/messages) with JSON-schema structured outputs
 * (output_config.format). Same prompts, schemas and result contracts as the OpenAI provider; exceptions use the
 * shared AQG provider error codes, so every caller maps both providers identically.
 *
 * The request uses exactly the site's selected model: no server-side fallback model, because a fallback would send
 * content to a model the administrator neither chose nor verified.
 */
final class AnthropicMessagesProvider implements AiProviderInterface, AiStructuredTaskExecutorInterface
{
    private const ENDPOINT = AnthropicApi::BASE_URL . '/v1/messages';
    private const ALT_TEXT_MAX_OUTPUT_TOKENS = 180;
    private const LINK_TEXT_MAX_OUTPUT_TOKENS = 260;
    private const IFRAME_TITLE_MAX_OUTPUT_TOKENS = 260;
    /**
     * Claude 5.x models think adaptively and thinking tokens count against max_tokens. AQG asks for low effort and
     * leaves this headroom so a short answer is never cut off; only the tokens actually used are billed.
     */
    private const THINKING_HEADROOM_TOKENS = 2048;
    private const TIMEOUT_SECONDS = 45;

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly AiModelCompatibilityRegistry $modelRegistry,
        private readonly AiStructuredOutputContract $outputContract,
        private readonly ?AiUsageMeter $usageMeter = null,
    ) {}

    public function supports(string $provider): bool
    {
        return strtolower(trim($provider)) === AiProviderCatalog::ANTHROPIC;
    }

    public function suggestAltText(AiAltSuggestionRequest $request, AiProviderConfiguration $configuration): AiAltSuggestionResult
    {
        $data = $this->execute(
            $this->structuredRequest(
                AiTask::ALT_TEXT,
                AiPromptDefinition::AI_PROMPT_VERSION,
                AiPromptDefinition::DEVELOPER_INSTRUCTIONS,
                $request->contextPayload(),
                AiPromptDefinition::structuredOutputFormat(),
                self::ALT_TEXT_MAX_OUTPUT_TOKENS,
                $this->imageInput($request->dataUrl),
            ),
            $configuration,
        )->data;
        $this->assertAltCrossFieldContract($data, $configuration->model);

        return new AiAltSuggestionResult(
            status: (string)$data['status'],
            suggestion: (string)$data['alt_text'],
            provider: AiProviderCatalog::ANTHROPIC,
            model: trim($configuration->model),
            promptVersion: AiPromptDefinition::AI_PROMPT_VERSION,
        );
    }

    public function suggestLinkText(AiLinkTextSuggestionRequest $request, AiProviderConfiguration $configuration): AiLinkTextSuggestionResult
    {
        $data = $this->execute(
            $this->structuredRequest(
                AiTask::LINK_TEXT,
                AiLinkTextSuggestionRequestBuilder::PROMPT_VERSION,
                AiLinkTextSuggestionRequestBuilder::DEVELOPER_INSTRUCTIONS,
                $request->contextPayload(),
                AiLinkTextSuggestionRequestBuilder::structuredOutputFormat(),
                self::LINK_TEXT_MAX_OUTPUT_TOKENS,
            ),
            $configuration,
        )->data;

        return new AiLinkTextSuggestionResult(
            status: (string)$data['status'],
            suggestedLinkText: (string)$data['suggested_link_text'],
            reason: (string)$data['reason'],
            needsReview: (bool)$data['needs_review'],
            provider: AiProviderCatalog::ANTHROPIC,
            model: trim($configuration->model),
            promptVersion: AiLinkTextSuggestionRequestBuilder::PROMPT_VERSION,
        );
    }

    public function suggestIframeTitle(AiIframeTitleSuggestionRequest $request, AiProviderConfiguration $configuration): AiIframeTitleSuggestionResult
    {
        $data = $this->execute(
            $this->structuredRequest(
                AiTask::IFRAME_TITLE,
                AiIframeTitleSuggestionRequestBuilder::PROMPT_VERSION,
                AiIframeTitleSuggestionRequestBuilder::DEVELOPER_INSTRUCTIONS,
                $request->contextPayload(),
                AiIframeTitleSuggestionRequestBuilder::structuredOutputFormat(),
                self::IFRAME_TITLE_MAX_OUTPUT_TOKENS,
            ),
            $configuration,
        )->data;

        return new AiIframeTitleSuggestionResult(
            status: (string)$data['status'],
            suggestedIframeTitle: (string)$data['suggested_iframe_title'],
            reason: (string)$data['reason'],
            needsReview: (bool)$data['needs_review'],
            provider: AiProviderCatalog::ANTHROPIC,
            model: trim($configuration->model),
            promptVersion: AiIframeTitleSuggestionRequestBuilder::PROMPT_VERSION,
        );
    }

    public function testConnection(AiProviderConfiguration $configuration): void
    {
        $format = AiPromptDefinition::connectionTestStructuredOutputFormat();
        $data = $this->execute(
            new AiStructuredRequest(
                task: AiTask::CONNECTION_TEST,
                promptVersion: AiPromptDefinition::CONNECTION_TEST_VERSION,
                instructions: AiPromptDefinition::CONNECTION_TEST_INSTRUCTIONS,
                inputText: AiPromptDefinition::CONNECTION_TEST_INPUT,
                schemaName: (string)$format['name'],
                outputSchema: $format['schema'],
                maxOutputTokens: self::ALT_TEXT_MAX_OUTPUT_TOKENS,
                image: AiImageInput::fromDataUrl(AiPromptDefinition::CONNECTION_TEST_IMAGE),
            ),
            $configuration,
        )->data;

        if (($data['status'] ?? null) !== AiAltSuggestionResult::STATUS_SUGGESTION || ($data['alt_text'] ?? null) !== 'AQG_TEST_OK') {
            $this->logSafeFailure('anthropic_connection_contract_mismatch', $configuration->model, 1771002318);
            throw new AiProviderException('Anthropic returned an invalid structured-output test response.', 1771002318);
        }
    }

    public function execute(AiStructuredRequest $request, AiProviderConfiguration $configuration): AiStructuredResponse
    {
        $model = trim($configuration->model);
        try {
            $profile = $this->modelRegistry->require($model, AiProviderCatalog::ANTHROPIC);
        } catch (\InvalidArgumentException $exception) {
            throw new AiProviderException('The selected Anthropic model is not supported by AQG.', 1771002317, $exception);
        }

        $decoded = $this->request($this->payload($request, $model, $profile), $configuration->apiKey(), $model);
        $usage = AiUsage::fromAnthropicMessages($decoded['usage'] ?? null);
        $this->usageMeter?->report($usage);

        $stopReason = (string)($decoded['stop_reason'] ?? '');
        if ($stopReason === 'refusal') {
            $this->logSafeFailure('anthropic_refusal', $model, 1771002313, [
                'refusal_category' => AnthropicApi::boundedDiagnosticValue($decoded['stop_details']['category'] ?? ''),
            ]);
            throw new AiProviderException('Anthropic declined to answer this request.', 1771002313);
        }
        if ($stopReason !== 'end_turn') {
            $this->logSafeFailure('anthropic_incomplete_response', $model, 1771002314, [
                'stop_reason' => AnthropicApi::boundedDiagnosticValue($stopReason),
            ]);
            throw new AiProviderException('Anthropic returned an incomplete response.', 1771002314);
        }

        $text = $this->outputText($decoded);
        if ($text === '') {
            $this->logSafeFailure('anthropic_empty_structured_output', $model, 1771002301);
            throw new AiProviderException('Anthropic returned no structured output.', 1771002301);
        }

        try {
            $structured = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logSafeFailure('anthropic_invalid_structured_output', $model, 1771002315);
            throw new AiProviderException('Anthropic returned invalid structured output.', 1771002315, $exception);
        }

        try {
            $data = $this->outputContract->validate($structured, $request->outputSchema);
        } catch (\UnexpectedValueException $exception) {
            $this->logSafeFailure('anthropic_schema_mismatch', $model, 1771002316, ['task' => $request->task]);
            throw new AiProviderException('Anthropic returned an answer outside the AQG contract.', 1771002316, $exception);
        }

        return new AiStructuredResponse($data, $usage, AiProviderCatalog::ANTHROPIC, $model);
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $format
     */
    private function structuredRequest(
        string $task,
        string $promptVersion,
        string $instructions,
        array $context,
        array $format,
        int $maxOutputTokens,
        ?AiImageInput $image = null,
    ): AiStructuredRequest {
        try {
            return AiStructuredRequest::withUntrustedContext($task, $promptVersion, $instructions, $context, $format, $maxOutputTokens, $image);
        } catch (\InvalidArgumentException $exception) {
            throw new AiProviderException('The AI request context could not be encoded.', 1771002302, $exception);
        }
    }

    private function imageInput(string $dataUrl): AiImageInput
    {
        try {
            return AiImageInput::fromDataUrl($dataUrl);
        } catch (\InvalidArgumentException $exception) {
            throw new AiProviderException('The image could not be prepared for Anthropic.', 1771002322, $exception);
        }
    }

    /**
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    private function payload(AiStructuredRequest $request, string $model, array $profile): array
    {
        $content = [];
        if ($request->image !== null) {
            $content[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => $request->image->mimeType,
                    'data' => $request->image->base64Data,
                ],
            ];
        }
        $content[] = ['type' => 'text', 'text' => $request->inputText];

        $outputConfig = [
            'format' => [
                'type' => 'json_schema',
                'schema' => $request->outputSchema,
            ],
        ];
        if (($profile['effortParameter'] ?? false) === true) {
            $outputConfig['effort'] = 'low';
        }

        return [
            'model' => $model,
            'max_tokens' => $request->maxOutputTokens + self::THINKING_HEADROOM_TOKENS,
            'system' => $request->instructions,
            'messages' => [[
                'role' => 'user',
                'content' => $content,
            ]],
            'output_config' => $outputConfig,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function request(array $payload, #[\SensitiveParameter] string $apiKey, string $model): array
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new AiProviderException('The AI provider request could not be encoded.', 1771002302, $exception);
        }

        try {
            $response = $this->requestFactory->request(self::ENDPOINT, 'POST', [
                'headers' => AnthropicApi::headers($apiKey, true),
                'body' => $body,
                'timeout' => self::TIMEOUT_SECONDS,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $exception) {
            $this->logSafeFailure('anthropic_transport_failure', $model, 1771002302, [
                'exception_class' => AnthropicApi::boundedDiagnosticValue($exception::class),
            ]);
            // Never keep the transport exception: it can contain request headers or the body.
            throw new AiProviderException('The Anthropic transport request failed.', 1771002302);
        }

        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            $error = AnthropicApi::extractError($response);
            [$message, $code] = $this->mapHttpFailure($status, $error['type']);
            $this->logSafeFailure('anthropic_http_failure', $model, $code, [
                'http_status' => $status,
                'provider_error_type' => $error['type'],
                'request_id' => $error['requestId'],
            ]);

            throw new AiProviderException($message, $code);
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logSafeFailure('anthropic_invalid_json', $model, 1771002304, ['http_status' => $status]);
            throw new AiProviderException('Anthropic returned an invalid response.', 1771002304, $exception);
        }
        if (!is_array($decoded) || ($decoded['type'] ?? null) !== 'message') {
            $this->logSafeFailure('anthropic_invalid_response', $model, 1771002305, ['http_status' => $status]);
            throw new AiProviderException('Anthropic returned an invalid response.', 1771002305);
        }

        return $decoded;
    }

    /** @param array<string,mixed> $response */
    private function outputText(array $response): string
    {
        $text = '';
        foreach ((array)($response['content'] ?? []) as $block) {
            // Thinking blocks are the model's own reasoning and never part of the answer.
            if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                $text .= $block['text'];
            }
        }

        return trim($text);
    }

    /** @param array<string,string|bool> $data */
    private function assertAltCrossFieldContract(array $data, string $model): void
    {
        $validSuggestion = $data['status'] === AiAltSuggestionResult::STATUS_SUGGESTION
            && trim((string)$data['alt_text']) !== '';
        $validNeedsReview = $data['status'] === AiAltSuggestionResult::STATUS_NEEDS_REVIEW
            && $data['alt_text'] === '';
        if ($validSuggestion || $validNeedsReview) {
            return;
        }

        $this->logSafeFailure('anthropic_cross_field_contract_mismatch', $model, 1771002318);
        throw new AiProviderException('Anthropic returned an answer outside the AQG contract.', 1771002318);
    }

    /** @return array{0:string,1:int} */
    private function mapHttpFailure(int $status, string $type): array
    {
        return match (true) {
            $status === 401 => ['The API key was rejected by Anthropic.', 1771002310],
            $status === 402, $type === 'billing_error' => ['Anthropic reports a billing problem for this account.', 1771002323],
            $status === 403 => ['This Anthropic key may not use the selected model.', 1771002321],
            $status === 404 => ['The selected Anthropic model is unavailable.', 1771002317],
            $status === 429 => ['Anthropic rate-limited the request. Try again later.', 1771002311],
            $status === 400, $status === 413 => ['Anthropic rejected the AQG request.', 1771002322],
            $status >= 500 => ['Anthropic could not complete the request.', 1771002324],
            default => ['The Anthropic connection could not be verified.', 1771002303],
        };
    }

    /** @param array<string,int|string> $metadata */
    private function logSafeFailure(string $event, string $model, int $exceptionCode, array $metadata = []): void
    {
        AnthropicApi::logSafeFailure(self::class, $event, [
            'requested_model_id' => AnthropicApi::boundedDiagnosticValue($model),
            'registry_version' => AiModelCompatibilityRegistry::ANTHROPIC_VERSION,
            'aqg_exception_code' => $exceptionCode,
        ] + $metadata);
    }
}
