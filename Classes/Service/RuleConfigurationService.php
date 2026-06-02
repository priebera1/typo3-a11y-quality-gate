<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;

final class RuleConfigurationService
{
    private array $disabledRuleIdsCache = [];

    public function __construct(
        private readonly RulesetRepository $rulesetRepository,
    ) {
    }

    public function isRuleEnabledForSite(string $siteIdentifier, string $ruleId): bool
    {
        return !in_array($ruleId, $this->getDisabledRuleIdsForSite($siteIdentifier), true);
    }

    public function getDisabledRuleIdsForSite(string $siteIdentifier): array
    {
        if (array_key_exists($siteIdentifier, $this->disabledRuleIdsCache)) {
            return $this->disabledRuleIdsCache[$siteIdentifier];
        }

        $ruleset = $this->rulesetRepository->findForSiteOrDefault($siteIdentifier);
        if (!is_array($ruleset)) {
            return $this->disabledRuleIdsCache[$siteIdentifier] = [];
        }

        return $this->disabledRuleIdsCache[$siteIdentifier] = $this->getDisabledRuleIdsFromRuleset($ruleset);
    }

    public function getDisabledRuleIdsFromRuleset(array $ruleset): array
    {
        $configuration = $this->decodeRulesJson((string)($ruleset['rules_json'] ?? ''));
        $disabledRules = $configuration['disabledRules'] ?? [];
        if (!is_array($disabledRules)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $ruleId): string => trim((string)$ruleId),
            $disabledRules
        ), static fn (string $ruleId): bool => $ruleId !== '')));
    }

    public function encodeRulesJsonWithDisabledRules(string $currentRulesJson, array $disabledRuleIds): string
    {
        $configuration = $this->decodeRulesJson($currentRulesJson);
        $configuration['disabledRules'] = array_values(array_unique(array_filter(array_map(
            static fn (mixed $ruleId): string => trim((string)$ruleId),
            $disabledRuleIds
        ), static fn (string $ruleId): bool => $ruleId !== '')));

        $this->disabledRuleIdsCache = [];

        return json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }


    /**
     * @return array{mode:string,forceLanguage:string,nonDescriptiveAdditional:string,nonDescriptiveDisabled:string,isAuto:bool,isForce:bool,isDisable:bool}
     */
    public function getDictionarySettingsFromRuleset(array $ruleset): array
    {
        $configuration = $this->decodeRulesJson((string)($ruleset['rules_json'] ?? ''));
        $dictionary = is_array($configuration['dictionary'] ?? null) ? $configuration['dictionary'] : [];

        $mode = strtolower(trim((string)($dictionary['mode'] ?? 'auto')));
        if (!in_array($mode, ['auto', 'force', 'disable'], true)) {
            $mode = 'auto';
        }

        return [
            'mode' => $mode,
            'forceLanguage' => strtolower(trim((string)($dictionary['forceLanguage'] ?? ''))),
            'nonDescriptiveAdditional' => $this->listToTextarea($dictionary['nonDescriptiveAdditional'] ?? []),
            'nonDescriptiveDisabled' => $this->listToTextarea($dictionary['nonDescriptiveDisabled'] ?? []),
            'isAuto' => $mode === 'auto',
            'isForce' => $mode === 'force',
            'isDisable' => $mode === 'disable',
        ];
    }

    /**
     * @param array<string, mixed> $dictionarySettings
     */
    public function encodeRulesJsonWithDictionarySettings(string $currentRulesJson, array $dictionarySettings): string
    {
        $configuration = $this->decodeRulesJson($currentRulesJson);
        $currentDictionary = is_array($configuration['dictionary'] ?? null) ? $configuration['dictionary'] : [];

        $mode = strtolower(trim((string)($dictionarySettings['mode'] ?? ($currentDictionary['mode'] ?? 'auto'))));
        if (!in_array($mode, ['auto', 'force', 'disable'], true)) {
            $mode = 'auto';
        }

        $configuration['dictionary'] = [
            'mode' => $mode,
            'forceLanguage' => strtolower(trim((string)($dictionarySettings['forceLanguage'] ?? ($currentDictionary['forceLanguage'] ?? '')))),
            'nonDescriptiveAdditional' => array_key_exists('nonDescriptiveAdditional', $dictionarySettings)
                ? $this->normalizeTextareaList($dictionarySettings['nonDescriptiveAdditional'])
                : $this->normalizeListValue($currentDictionary['nonDescriptiveAdditional'] ?? []),
            'nonDescriptiveDisabled' => array_key_exists('nonDescriptiveDisabled', $dictionarySettings)
                ? $this->normalizeTextareaList($dictionarySettings['nonDescriptiveDisabled'])
                : $this->normalizeListValue($currentDictionary['nonDescriptiveDisabled'] ?? []),
        ];

        return json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }


    /**
     * @return array{enabled:bool,allowPrivateHosts:bool}
     */
    public function getRenderedCheckSettingsForSite(string $siteIdentifier): array
    {
        $ruleset = $this->rulesetRepository->findForSiteOrDefault($siteIdentifier);
        if (!is_array($ruleset)) {
            return [
                'enabled' => true,
                'allowPrivateHosts' => false,
            ];
        }

        return $this->getRenderedCheckSettingsFromRuleset($ruleset);
    }

    /**
     * @return array{enabled:bool,allowPrivateHosts:bool}
     */
    public function getRenderedCheckSettingsFromRuleset(array $ruleset): array
    {
        $configuration = $this->decodeRulesJson((string)($ruleset['rules_json'] ?? ''));
        $renderedCheck = is_array($configuration['renderedCheck'] ?? null) ? $configuration['renderedCheck'] : [];

        return [
            'enabled' => !array_key_exists('enabled', $renderedCheck) || (bool)$renderedCheck['enabled'],
            'allowPrivateHosts' => (bool)($renderedCheck['allowPrivateHosts'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $renderedCheckSettings
     */
    public function encodeRulesJsonWithRenderedCheckSettings(string $currentRulesJson, array $renderedCheckSettings): string
    {
        $configuration = $this->decodeRulesJson($currentRulesJson);

        $configuration['renderedCheck'] = [
            'enabled' => (bool)($renderedCheckSettings['enabled'] ?? false),
            'allowPrivateHosts' => (bool)($renderedCheckSettings['allowPrivateHosts'] ?? false),
        ];

        return json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }


    public function getShowProHintsFromRuleset(array $ruleset): ?bool
    {
        $configuration = $this->decodeRulesJson((string)($ruleset['rules_json'] ?? ''));
        $ui = is_array($configuration['ui'] ?? null) ? $configuration['ui'] : [];

        if (!array_key_exists('showProHints', $ui)) {
            return null;
        }

        return filter_var($ui['showProHints'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? null;
    }

    public function encodeRulesJsonWithShowProHints(string $currentRulesJson, bool $showProHints): string
    {
        $configuration = $this->decodeRulesJson($currentRulesJson);
        $ui = is_array($configuration['ui'] ?? null) ? $configuration['ui'] : [];
        $ui['showProHints'] = $showProHints;
        $configuration['ui'] = $ui;

        return json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * @return list<string>
     */
    private function normalizeTextareaList(mixed $value): array
    {
        if (is_array($value)) {
            $items = $value;
        } else {
            $items = preg_split('/[\r\n,]+/', (string)$value) ?: [];
        }

        $normalized = [];
        foreach ($items as $item) {
            $item = trim((string)$item);
            if ($item !== '') {
                $normalized[] = $item;
            }
        }

        return array_values(array_unique($normalized));
    }


    /**
     * @return list<string>
     */
    private function normalizeListValue(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string)$item),
            $value
        ), static fn (string $item): bool => $item !== '')));
    }

    private function listToTextarea(mixed $value): string
    {
        if (!is_array($value)) {
            return '';
        }

        return implode("\n", array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string)$item),
            $value
        ), static fn (string $item): bool => $item !== '')));
    }

    private function decodeRulesJson(string $rulesJson): array
    {
        $rulesJson = trim($rulesJson);
        if ($rulesJson === '') {
            return [];
        }

        try {
            $configuration = json_decode($rulesJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($configuration) ? $configuration : [];
    }
}
