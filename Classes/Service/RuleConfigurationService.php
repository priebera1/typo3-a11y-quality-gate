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
