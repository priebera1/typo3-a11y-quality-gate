<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiPrompts;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgPrompts;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgLimits;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiTask;

/**
 * The short cost hint inside an AI action button ("Explain with AI (<$0.02)"): the most one request of that action can
 * cost with the site's provider and model, from the same price table and worst-case bound the monthly budget uses.
 *
 * The real request reserves its own exact bound before it is sent; this estimate only describes the action. Its
 * input is AQG's fixed instructions and schema plus the largest context the action can send (every context field is
 * length-capped, see EditorAiContextBuilder), so the label never understates a request. Providers bill actual usage,
 * which is usually far lower. No provider, model or price means no amount: the button keeps its plain label.
 */
final class AiActionCostEstimator
{
    public const ACTION_EXPLAIN = 'explain';
    public const ACTION_FIX_TEXT = 'fix_text';
    public const ACTION_FIX_IMAGE = 'fix_image';
    public const ACTION_LINK_TEXT = 'link_text';
    public const ACTION_IFRAME_TITLE = 'iframe_title';
    /** One "Ask AQG" question in the toolbar. */
    public const ACTION_ASSISTANT = 'assistant';
    /** The largest "Ask AQG" context: 15 finding groups with guidance and three earlier turns. */
    public const MAX_ASSISTANT_CONTEXT_BYTES = 24000;

    /** Upper bound of the context characters an editor AI request sends (sum of the context builder's caps). */
    public const MAX_CONTEXT_CHARACTERS = 3_000;
    /** AiCostBound counts bytes; a capped character is at most 4 bytes in UTF-8 (JSON_UNESCAPED_UNICODE). */
    public const MAX_CONTEXT_BYTES = self::MAX_CONTEXT_CHARACTERS * 4;

    /** @var array<string, array<string, ?int>> */
    private array $cache = [];

    public function __construct(
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly AiPriceTable $priceTable,
    ) {}

    /**
     * Upper bound in micro-USD for one request of $action on $siteIdentifier, or null when no reliable price exists.
     */
    public function maxCostMicro(string $siteIdentifier, string $action): ?int
    {
        if (array_key_exists($action, $this->cache[$siteIdentifier] ?? [])) {
            return $this->cache[$siteIdentifier][$action];
        }
        $micro = null;
        try {
            $configuration = $this->configurationResolver->resolve($siteIdentifier);
            $price = $this->priceTable->find($configuration->provider, $configuration->model);
            $bound = $price !== null ? $this->bound($action, $configuration->provider) : null;
            $micro = $bound !== null ? $bound->costMicro($price) : null;
        } catch (\Throwable) {
            $micro = null;
        }

        return $this->cache[$siteIdentifier][$action] = ($micro !== null && $micro > 0 ? $micro : null);
    }

    /**
     * The worst-case bound of one request of $action: the same shape the real request has, with the largest context.
     */
    public function bound(string $action, string $provider): ?AiCostBound
    {
        $context = str_repeat('x', self::MAX_CONTEXT_BYTES);

        return match ($action) {
            self::ACTION_EXPLAIN => AiCostBound::forStructuredRequest(new AiStructuredRequest(
                task: AiTask::EXPLAIN,
                promptVersion: EditorAiPrompts::EXPLAIN_PROMPT_VERSION,
                instructions: EditorAiPrompts::explainInstructions(),
                inputText: AiPromptDefinition::CONTEXT_WRAPPER_PREFIX . "\n" . $context,
                schemaName: (string)EditorAiPrompts::explainOutputFormat()['name'],
                outputSchema: EditorAiPrompts::explainOutputFormat()['schema'],
                maxOutputTokens: EditorAiRemediationService::EXPLAIN_MAX_OUTPUT_TOKENS,
            ), $provider),
            // Rich-text fixes (link text, button label, iframe title, heading text): the longest fix instructions.
            self::ACTION_FIX_TEXT => AiCostBound::forStructuredRequest($this->fixRequest(AiTask::LINK_TEXT, $context), $provider),
            // Image alt text sends the image, at the image allowance: file references (legacy alt request) and alt texts
            // in rich text (editor fix request with the image) alike; the larger of both bounds.
            self::ACTION_FIX_IMAGE => self::larger(
                AiCostBound::forLegacyTask(AiTask::ALT_TEXT, $provider, $context, 1),
                new AiCostBound(
                    AiCostBound::forStructuredRequest($this->fixRequest(AiTask::ALT_TEXT, $context), $provider)->inputTokens + AiCostBound::IMAGE_TOKENS,
                    AiCostBound::forStructuredRequest($this->fixRequest(AiTask::ALT_TEXT, $context), $provider)->outputTokens,
                ),
            ),
            // Page Detail's inline link text and iframe title suggestions (legacy request builders, text only).
            self::ACTION_LINK_TEXT => AiCostBound::forLegacyTask(AiTask::LINK_TEXT, $provider, $context),
            self::ACTION_IFRAME_TITLE => AiCostBound::forLegacyTask(AiTask::IFRAME_TITLE, $provider, $context),
            self::ACTION_ASSISTANT => AiCostBound::forStructuredRequest(new AiStructuredRequest(
                task: AiTask::ASSISTANT,
                promptVersion: AskAqgPrompts::PROMPT_VERSION,
                instructions: AskAqgPrompts::instructions(),
                inputText: AiPromptDefinition::CONTEXT_WRAPPER_PREFIX . "\n" . str_repeat('x', self::MAX_ASSISTANT_CONTEXT_BYTES),
                schemaName: (string)AskAqgPrompts::outputFormat()['name'],
                outputSchema: AskAqgPrompts::outputFormat()['schema'],
                maxOutputTokens: AskAqgLimits::MAX_OUTPUT_TOKENS,
            ), $provider),
            default => null,
        };
    }

    /**
     * The amount for the button: the bound rounded up to one significant digit and kept strictly above the bound, so
     * "<$0.02" is always true for one request. Below a tenth of a cent it is "<$0.001": never "$0.00" for a request
     * that costs something. Two decimals at least, three at most.
     */
    public static function formatUpperBound(int $micro, string $decimalSeparator = '.'): string
    {
        $dollars = max(1, $micro) / 1_000_000;
        if ($dollars < 0.001) {
            return number_format(0.001, 3, $decimalSeparator, '');
        }
        $exponent = (int)floor(log10($dollars));
        $step = 10 ** $exponent;
        $ceiling = ceil(round($dollars / $step, 9)) * $step;
        if ($ceiling <= $dollars) {
            $ceiling += $step;
        }
        // Decimals of the rounded value: 0.0095 rounds to 0.01, not "0.010".
        $decimals = min(3, max(2, -(int)floor(log10($ceiling) + 1e-9)));

        return number_format($ceiling, $decimals, $decimalSeparator, '');
    }

    private function fixRequest(string $task, string $context): AiStructuredRequest
    {
        return new AiStructuredRequest(
            task: $task,
            promptVersion: EditorAiPrompts::FIX_PROMPT_VERSION,
            instructions: $this->longestFixInstructions(),
            inputText: AiPromptDefinition::CONTEXT_WRAPPER_PREFIX . "\n" . $context,
            schemaName: (string)EditorAiPrompts::fixOutputFormat()['name'],
            outputSchema: EditorAiPrompts::fixOutputFormat()['schema'],
            maxOutputTokens: EditorAiRemediationService::FIX_MAX_OUTPUT_TOKENS,
        );
    }

    private static function larger(AiCostBound $a, AiCostBound $b): AiCostBound
    {
        return new AiCostBound(max($a->inputTokens, $b->inputTokens), max($a->outputTokens, $b->outputTokens));
    }

    private function longestFixInstructions(): string
    {
        $longest = '';
        foreach ([EditorAiRuleCatalog::KIND_LINK_TEXT, EditorAiRuleCatalog::KIND_BUTTON_LABEL, EditorAiRuleCatalog::KIND_IFRAME_TITLE, EditorAiRuleCatalog::KIND_HEADING_TEXT, EditorAiRuleCatalog::KIND_IMAGE_ALT] as $kind) {
            $instructions = EditorAiPrompts::fixInstructions($kind);
            if (strlen($instructions) > strlen($longest)) {
                $longest = $instructions;
            }
        }

        return $longest;
    }
}
