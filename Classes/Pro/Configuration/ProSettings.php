<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Configuration;

use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ProSettings
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly RulesetRepository $rulesetRepository,
        private readonly RuleConfigurationService $ruleConfigurationService,
    ) {
    }

    public function getLicenceKey(): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get('a11y_quality_gate', 'licenceKey'));
        } catch (\Throwable) {
            return '';
        }
    }

    public function isConfigured(): bool
    {
        return $this->getLicenceKey() !== '';
    }

    public function isTrialKey(?string $licenceKey = null): bool
    {
        $licenceKey ??= $this->getLicenceKey();

        return $licenceKey !== ''
            && (
                str_starts_with($licenceKey, 'aqg_trial_')
                || str_starts_with($licenceKey, 'aqg_test_')
            );
    }

    public function showProHints(): bool
    {
        try {
            $ruleset = $this->rulesetRepository->findDefault();
            if (is_array($ruleset)) {
                $storedValue = $this->ruleConfigurationService->getShowProHintsFromRuleset($ruleset);
                if ($storedValue !== null) {
                    return $storedValue;
                }
            }
        } catch (\Throwable) {
            // Keep ExtensionConfiguration as the backwards-compatible source below.
        }

        try {
            $rawValue = $this->extensionConfiguration->get('a11y_quality_gate', 'showProHints');
        } catch (\Throwable) {
            return true;
        }

        return filter_var($rawValue, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public static function resolveApiBaseUrl(): string
    {
        $override = getenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL');
        if (is_string($override) && trim($override) !== '') {
            return rtrim(trim($override), '/');
        }

        return ProConstants::API_BASE_URL;
    }

    public static function resolveCrawlerBaseUrl(): string
    {
        $override = getenv('A11Y_QUALITY_GATE_PRO_CRAWLER_BASE_URL');
        if (is_string($override) && trim($override) !== '') {
            return rtrim(trim($override), '/');
        }

        return ProConstants::API_BASE_URL;
    }
}
