<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Settings;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiSiteSettingRepositoryInterface;

/**
 * Resolves the effective site-level AI settings. Upgraded 2.x sites have no site-setting row: they keep OpenAI and
 * keep sending images and page titles. They have no processing acknowledgement either, so AI stays paused for their
 * editors until an administrator confirms it (Documentation/Upgrade).
 */
final class AiSiteSettingsService
{
    public function __construct(
        private readonly AiSiteSettingRepositoryInterface $siteSettingRepository,
        private readonly AiConfigurationRepositoryInterface $configurationRepository,
    ) {}

    public function get(string $siteIdentifier): AiSiteSettings
    {
        $siteIdentifier = trim($siteIdentifier);
        $row = $siteIdentifier !== '' ? $this->siteSettingRepository->findBySiteIdentifier($siteIdentifier) : null;
        $storedProvider = AiProviderCatalog::normalize((string)($row['active_provider'] ?? ''));

        return new AiSiteSettings(
            provider: $storedProvider !== '' ? $storedProvider : AiProviderCatalog::LEGACY_DEFAULT,
            providerChosen: $storedProvider !== '' || $this->usedOpenAiBefore($siteIdentifier),
            allowImageInput: !is_array($row) || (int)($row['allow_image_input'] ?? 1) === 1,
            allowPageTitle: !is_array($row) || (int)($row['allow_page_title'] ?? 1) === 1,
            providerChangedAt: max(0, (int)($row['provider_changed_at'] ?? 0)),
            budgetMicroUsd: max(0, (int)($row['budget_micro_usd'] ?? 0)),
            budgetWarningPercent: max(0, min(100, (int)($row['budget_warning_percent'] ?? 0))),
            budgetHardStop: (int)($row['budget_hard_stop'] ?? 0) === 1,
            acknowledgedProvider: AiProviderCatalog::normalize((string)($row['processing_ack_provider'] ?? '')),
            acknowledgedBy: max(0, (int)($row['processing_ack_by'] ?? 0)),
            acknowledgedAt: max(0, (int)($row['processing_ack_at'] ?? 0)),
            budgetAlertEmail: trim((string)($row['budget_alert_email'] ?? '')),
        );
    }

    public function activeProvider(string $siteIdentifier): string
    {
        return $this->get($siteIdentifier)->provider;
    }

    private function usedOpenAiBefore(string $siteIdentifier): bool
    {
        if (AiProviderCatalog::environmentKey(AiProviderCatalog::OPENAI) !== '') {
            return true;
        }
        if ($siteIdentifier === '') {
            return false;
        }

        $row = $this->configurationRepository->findBySiteIdentifier($siteIdentifier, AiProviderCatalog::OPENAI);

        return is_array($row) && trim((string)($row['encrypted_api_key'] ?? '')) !== '';
    }
}
