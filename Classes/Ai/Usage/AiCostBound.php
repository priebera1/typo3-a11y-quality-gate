<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiIframeTitleSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiTask;

/**
 * A conservative upper bound of the tokens one provider request can be billed for, computed before it is sent. The
 * monthly budget reserves this bound, so a request is only sent while even its worst case fits the budget.
 *
 * Input: every byte of text AQG sends counts as one token. Both providers tokenize byte-level, so a token always
 * covers at least one byte and the byte count cannot be exceeded. A fixed envelope covers role markers, the output
 * schema and message framing, and each image counts as IMAGE_TOKENS (OpenAI bills AQG's "low" detail images at well
 * under 1,000 tokens; Anthropic bills about width × height / 750, at most a few thousand tokens after its own
 * downscaling).
 * Output: the output cap AQG sends (max_output_tokens / max_tokens). Reasoning and thinking tokens count against that
 * cap at both providers; for Anthropic AQG adds its thinking headroom to the cap, so the bound adds it too.
 * Money: AiPrice::worstCaseMicro() — the long-context tier when the input bound exceeds its threshold, and input at the
 * cache-write rate where that is higher than ordinary input (OpenAI GPT-5.6+ write automatically at the default
 * 30-minute lifetime; AQG requests no longer lifetime and sends no Anthropic cache_control, so no other rate applies).
 */
final readonly class AiCostBound
{
    public const ENVELOPE_TOKENS = 1_024;
    public const IMAGE_TOKENS = 6_400;
    /** Mirrors AnthropicMessagesProvider::THINKING_HEADROOM_TOKENS (pinned by AiCostBoundTest). */
    public const ANTHROPIC_THINKING_HEADROOM_TOKENS = 2_048;
    /** Output caps of the requests the providers build themselves (pinned against the provider constants by tests). */
    public const LEGACY_OUTPUT_TOKENS = [
        AiTask::ALT_TEXT => 180,
        AiTask::LINK_TEXT => 260,
        AiTask::IFRAME_TITLE => 260,
        AiTask::CONNECTION_TEST => 180,
    ];
    /** Fallback when a caller passes no bound: the largest output cap AiStructuredRequest allows. */
    public const FALLBACK_OUTPUT_TOKENS = 4_096;
    public const FALLBACK_INPUT_TOKENS = 200_000;

    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
    ) {}

    public static function forStructuredRequest(AiStructuredRequest $request, string $provider): self
    {
        $text = strlen($request->instructions) + strlen($request->inputText) + strlen($request->schemaName)
            + strlen((string)json_encode($request->outputSchema));

        return new self(
            $text + self::ENVELOPE_TOKENS + ($request->image !== null ? self::IMAGE_TOKENS : 0),
            self::outputCap($request->maxOutputTokens, $provider),
        );
    }

    /**
     * A request the provider client builds from AQG's fixed instructions for $task plus $contextJson (the context
     * payload as JSON) and at most $images images.
     */
    public static function forLegacyTask(string $task, string $provider, string $contextJson, int $images = 0): self
    {
        $instructions = match ($task) {
            AiTask::LINK_TEXT => AiLinkTextSuggestionRequestBuilder::DEVELOPER_INSTRUCTIONS,
            AiTask::IFRAME_TITLE => AiIframeTitleSuggestionRequestBuilder::DEVELOPER_INSTRUCTIONS,
            AiTask::CONNECTION_TEST => AiPromptDefinition::CONNECTION_TEST_INSTRUCTIONS . AiPromptDefinition::CONNECTION_TEST_INPUT,
            default => AiPromptDefinition::DEVELOPER_INSTRUCTIONS,
        };

        return new self(
            strlen($instructions) + strlen(AiPromptDefinition::CONTEXT_WRAPPER_PREFIX) + strlen($contextJson)
                + self::ENVELOPE_TOKENS + max(0, $images) * self::IMAGE_TOKENS,
            self::outputCap(self::LEGACY_OUTPUT_TOKENS[$task] ?? self::FALLBACK_OUTPUT_TOKENS, $provider),
        );
    }

    /** The connection test: fixed prompt and the 16-pixel test image, no site content. */
    public static function forConnectionTest(string $provider): self
    {
        return self::forLegacyTask(AiTask::CONNECTION_TEST, $provider, '', 1);
    }

    /** Used only when a caller cannot describe its request; deliberately large. */
    public static function fallback(string $provider): self
    {
        return new self(self::FALLBACK_INPUT_TOKENS, self::outputCap(self::FALLBACK_OUTPUT_TOKENS, $provider));
    }

    /**
     * The worst case at $price: the prompt-length tier the input bound reaches, every input token at the highest input
     * rate an AQG request can incur (an automatic cache write at the default lifetime), every output token at output.
     */
    public function costMicro(AiPrice $price): int
    {
        return $price->worstCaseMicro($this->inputTokens, $this->outputTokens);
    }

    private static function outputCap(int $maxOutputTokens, string $provider): int
    {
        return max(0, $maxOutputTokens)
            + (AiProviderCatalog::normalize($provider) === AiProviderCatalog::ANTHROPIC ? self::ANTHROPIC_THINKING_HEADROOM_TOKENS : 0);
    }
}
