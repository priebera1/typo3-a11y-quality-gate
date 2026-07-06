<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

/**
 * AQG-owned compatibility boundary for OpenAI models.
 *
 * Availability is discovered per project key. A model is selectable only when
 * it is both returned by OpenAI and present in this registry.
 */
final class AiModelCompatibilityRegistry
{
    public const VERSION = 'aqg_openai_models_v1';

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
        ],
    ];

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
        foreach (self::MODELS as $id => $profile) {
            $models[$id] = ['id' => $id] + $profile;
        }

        uasort($models, static function (array $left, array $right): int {
            return [$left['label'], $left['id']] <=> [$right['label'], $right['id']];
        });

        return $models;
    }

    /** @return array<string,mixed>|null */
    public function find(string $modelId): ?array
    {
        $modelId = trim($modelId);
        $profile = self::MODELS[$modelId] ?? null;
        if (!is_array($profile) || !$this->isSelectableProfile($profile)) {
            return null;
        }

        return ['id' => $modelId] + $profile;
    }

    /** @return array<string,mixed> */
    public function require(string $modelId): array
    {
        $profile = $this->find($modelId);
        if ($profile === null) {
            throw new \InvalidArgumentException('The selected OpenAI model is not supported by AQG.');
        }

        return $profile;
    }

    public function supports(string $modelId): bool
    {
        return $this->find($modelId) !== null;
    }

    /**
     * @param list<string> $availableModelIds
     * @return array{supported:list<array{id:string,label:string}>,unsupported:list<string>}
     */
    public function filterAvailable(array $availableModelIds): array
    {
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
            $profile = $this->find($modelId);
            if ($profile === null) {
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
        return ($profile['responsesApi'] ?? false) === true
            && ($profile['imageInput'] ?? false) === true
            && ($profile['structuredOutputs'] ?? false) === true
            && ($profile['deprecated'] ?? true) === false;
    }
}
