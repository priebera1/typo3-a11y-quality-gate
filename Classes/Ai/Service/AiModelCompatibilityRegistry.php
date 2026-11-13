<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;

/**
 * AQG-owned compatibility boundary for provider models.
 *
 * Availability is discovered per key. A model is selectable only when it is returned by the site's provider, is
 * present in this registry for that provider, and, where the provider reports capabilities, the provider confirms
 * image input and structured outputs.
 */
final class AiModelCompatibilityRegistry
{
    /** OpenAI registry version; unchanged since 2.0 so cached OpenAI model lists stay valid after an upgrade. */
    public const VERSION = 'aqg_openai_models_v1';
    public const ANTHROPIC_VERSION = 'aqg_anthropic_models_v1';

    /**
     * @var array<string,array{
     *     label:string,
     *     responsesApi:bool,
     *     imageInput:bool,
     *     structuredOutputs:bool,
     *     reasoningParameter:bool,
     *     supportedReasoningEfforts:list<string>,
     *     imageDetail:list<string>,
     *     deprecated:bool
     * }>
     */
    private const MODELS = [
        'gpt-4.1-mini' => [
            'label' => 'GPT-4.1 mini',
            'responsesApi' => true,
            'imageInput' => true,
            'structuredOutputs' => true,
            'reasoningParameter' => false,
            'supportedReasoningEfforts' => [],
            'imageDetail' => ['low', 'high', 'auto'],
            'deprecated' => false,
            'provider' => AiProviderCatalog::OPENAI,
        ],
        'gpt-4.1-mini-2025-04-14' => [
            'label' => 'GPT-4.1 mini · 2025-04-14 snapshot',
            'responsesApi' => true,
            'imageInput' => true,
            'structuredOutputs' => true,
            'reasoningParameter' => false,
            'supportedReasoningEfforts' => [],
            'imageDetail' => ['low', 'high', 'auto'],
            'deprecated' => false,
            'provider' => AiProviderCatalog::OPENAI,
        ],
        'gpt-5.4-mini' => [
            'label' => 'GPT-5.4 mini',
            'responsesApi' => true,
            'imageInput' => true,
            'structuredOutputs' => true,
            'reasoningParameter' => true,
            'supportedReasoningEfforts' => ['none', 'low', 'medium', 'high', 'xhigh'],
            'imageDetail' => ['low', 'high', 'auto'],
            'deprecated' => false,
            'provider' => AiProviderCatalog::OPENAI,
        ],
        'gpt-5.4-mini-2026-03-17' => [
            'label' => 'GPT-5.4 mini · 2026-03-17 snapshot',
            'responsesApi' => true,
            'imageInput' => true,
            'structuredOutputs' => true,
            'reasoningParameter' => true,
            'supportedReasoningEfforts' => ['none', 'low', 'medium', 'high', 'xhigh'],
            'imageDetail' => ['low', 'high', 'auto'],
            'deprecated' => false,
            'provider' => AiProviderCatalog::OPENAI,
        ],
        'gpt-5.4-nano' => [
            'label' => 'GPT-5.4 nano',
            'responsesApi' => true,
            'imageInput' => true,
            'structuredOutputs' => true,
            'reasoningParameter' => true,
            'supportedReasoningEfforts' => ['none', 'low', 'medium', 'high', 'xhigh'],
            'imageDetail' => ['low', 'high', 'auto'],
            'deprecated' => false,
            'provider' => AiProviderCatalog::OPENAI,
        ],
        'gpt-5.4-nano-2026-03-17' => [
            'label' => 'GPT-5.4 nano · 2026-03-17 snapshot',
            'responsesApi' => true,
            'imageInput' => true,
            'structuredOutputs' => true,
            'reasoningParameter' => true,
            'supportedReasoningEfforts' => ['none', 'low', 'medium', 'high', 'xhigh'],
            'imageDetail' => ['low', 'high', 'auto'],
            'deprecated' => false,
            'provider' => AiProviderCatalog::OPENAI,
        ],
    ];

    /**
     * Claude models whose Messages API supports image input and JSON-schema structured outputs
     * (output_config.format). effortParameter: the model accepts output_config.effort, used to keep adaptive thinking
     * short for these one-line tasks. Older Claude generations are listed as available but not supported.
     *
     * @var array<string,array{label:string,messagesApi:bool,imageInput:bool,structuredOutputs:bool,effortParameter:bool,deprecated:bool,provider:string}>
     */
    private const ANTHROPIC_MODELS = [
        'claude-haiku-5-5' => ['label' => 'Claude Haiku 5.5', 'messagesApi' => true, 'imageInput' => true, 'structuredOutputs' => true, 'effortParameter' => true, 'deprecated' => false, 'provider' => AiProviderCatalog::ANTHROPIC],
        'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5', 'messagesApi' => true, 'imageInput' => true, 'structuredOutputs' => true, 'effortParameter' => true, 'deprecated' => false, 'provider' => AiProviderCatalog::ANTHROPIC],
        'claude-opus-5-5' => ['label' => 'Claude Opus 5.5', 'messagesApi' => true, 'imageInput' => true, 'structuredOutputs' => true, 'effortParameter' => true, 'deprecated' => false, 'provider' => AiProviderCatalog::ANTHROPIC],
        'claude-sonnet-5' => ['label' => 'Claude Sonnet 5', 'messagesApi' => true, 'imageInput' => true, 'structuredOutputs' => true, 'effortParameter' => true, 'deprecated' => false, 'provider' => AiProviderCatalog::ANTHROPIC],
        'claude-opus-5' => ['label' => 'Claude Opus 5', 'messagesApi' => true, 'imageInput' => true, 'structuredOutputs' => true, 'effortParameter' => true, 'deprecated' => false, 'provider' => AiProviderCatalog::ANTHROPIC],
        'claude-opus-4-8' => ['label' => 'Claude Opus 4.8', 'messagesApi' => true, 'imageInput' => true, 'structuredOutputs' => true, 'effortParameter' => true, 'deprecated' => false, 'provider' => AiProviderCatalog::ANTHROPIC],
        'claude-haiku-4-5-20251001' => ['label' => 'Claude Haiku 4.5', 'messagesApi' => true, 'imageInput' => true, 'structuredOutputs' => true, 'effortParameter' => false, 'deprecated' => false, 'provider' => AiProviderCatalog::ANTHROPIC],
    ];

    /**
     * Dated snapshot ids priced under another id of the provider's pricing page. Reviewed, explicit entries only: each
     * snapshot is the one its alias currently points to, and the provider prints its price under the alias. AQG never
     * derives a price from a similar name — a model id without an entry here is priced under its own id or not at all.
     */
    private const PRICE_MODEL_IDS = [
        'gpt-4.1-mini-2025-04-14' => 'gpt-4.1-mini',
        'gpt-5.4-mini-2026-03-17' => 'gpt-5.4-mini',
        'gpt-5.4-nano-2026-03-17' => 'gpt-5.4-nano',
        'claude-haiku-4-5-20251001' => 'claude-haiku-4-5',
    ];

    /**
     * The id the provider's pricing page prices $modelId under, or null for a model AQG does not know. Deprecated
     * registry models still resolve, so their recorded usage and running requests keep a price.
     */
    public function priceModelId(string $modelId, string $provider): ?string
    {
        $modelId = trim($modelId);
        $profile = self::MODELS[$modelId] ?? self::ANTHROPIC_MODELS[$modelId] ?? null;
        if (!is_array($profile) || $profile['provider'] !== AiProviderCatalog::normalize($provider)) {
            return null;
        }

        return self::PRICE_MODEL_IDS[$modelId] ?? $modelId;
    }

    /** @return list<string> every price id of $provider's registry models, deprecated ones included */
    public function priceModelIds(string $provider): array
    {
        $ids = [];
        foreach (array_keys(self::MODELS + self::ANTHROPIC_MODELS) as $modelId) {
            $priceId = $this->priceModelId((string)$modelId, $provider);
            if ($priceId !== null) {
                $ids[$priceId] = true;
            }
        }
        $ids = array_keys($ids);
        sort($ids, SORT_STRING);

        return $ids;
    }

    public static function versionFor(string $provider): string
    {
        return match (AiProviderCatalog::normalize($provider)) {
            AiProviderCatalog::ANTHROPIC => self::ANTHROPIC_VERSION,
            default => self::VERSION,
        };
    }

    /**
     * @return array<string,array{
     *     id:string,
     *     label:string,
     *     responsesApi:bool,
     *     imageInput:bool,
     *     structuredOutputs:bool,
     *     reasoningParameter:bool,
     *     supportedReasoningEfforts:list<string>,
     *     imageDetail:list<string>,
     *     deprecated:bool
     * }>
     */
    public function all(): array
    {
        $models = [];
        foreach (self::MODELS + self::ANTHROPIC_MODELS as $id => $profile) {
            $models[$id] = ['id' => $id] + $profile;
        }

        uasort($models, static function (array $left, array $right): int {
            return [$left['label'], $left['id']] <=> [$right['label'], $right['id']];
        });

        return $models;
    }

    /**
     * @param string|null $provider when given, a model of another provider is not found
     * @return array<string,mixed>|null
     */
    public function find(string $modelId, ?string $provider = null): ?array
    {
        $modelId = trim($modelId);
        $profile = self::MODELS[$modelId] ?? self::ANTHROPIC_MODELS[$modelId] ?? null;
        if (!is_array($profile) || !$this->isSelectableProfile($profile)) {
            return null;
        }
        if ($provider !== null && $profile['provider'] !== AiProviderCatalog::normalize($provider)) {
            return null;
        }

        return ['id' => $modelId] + $profile;
    }

    /** @return array<string,mixed> */
    public function require(string $modelId, ?string $provider = null): array
    {
        $profile = $this->find($modelId, $provider);
        if ($profile === null) {
            throw new \InvalidArgumentException('The selected AI model is not supported by AQG.');
        }

        return $profile;
    }

    public function supports(string $modelId, ?string $provider = null): bool
    {
        return $this->find($modelId, $provider) !== null;
    }

    /**
     * @param list<string> $availableModelIds
     * @param array<string,array{imageInput?:bool|null,structuredOutputs?:bool|null}> $reportedCapabilities
     *        capabilities the provider reported per model id; a capability reported as false excludes the model
     * @return array{supported:list<array{id:string,label:string}>,unsupported:list<string>}
     */
    public function filterAvailable(
        array $availableModelIds,
        string $provider = AiProviderCatalog::OPENAI,
        array $reportedCapabilities = [],
    ): array {
        $normalized = [];
        foreach ($availableModelIds as $modelId) {
            $modelId = trim($modelId);
            if ($modelId !== '') {
                $normalized[$modelId] = true;
            }
        }

        $supported = [];
        $unsupported = [];
        foreach (array_keys($normalized) as $modelId) {
            $profile = $this->find($modelId, $provider);
            $reported = $reportedCapabilities[$modelId] ?? [];
            if ($profile === null
                || ($reported['imageInput'] ?? null) === false
                || ($reported['structuredOutputs'] ?? null) === false) {
                $unsupported[] = $modelId;
                continue;
            }
            $supported[] = [
                'id' => $modelId,
                'label' => (string)$profile['label'],
            ];
        }

        usort($supported, static fn (array $left, array $right): int => [$left['label'], $left['id']] <=> [$right['label'], $right['id']]);
        sort($unsupported, SORT_STRING);

        return [
            'supported' => $supported,
            'unsupported' => array_slice($unsupported, 0, 200),
        ];
    }

    /** @param array<string,mixed> $profile */
    private function isSelectableProfile(array $profile): bool
    {
        $apiSupported = match ($profile['provider'] ?? '') {
            AiProviderCatalog::OPENAI => ($profile['responsesApi'] ?? false) === true,
            AiProviderCatalog::ANTHROPIC => ($profile['messagesApi'] ?? false) === true,
            default => false,
        };

        return $apiSupported
            && ($profile['imageInput'] ?? false) === true
            && ($profile['structuredOutputs'] ?? false) === true
            && ($profile['deprecated'] ?? true) === false;
    }
}
