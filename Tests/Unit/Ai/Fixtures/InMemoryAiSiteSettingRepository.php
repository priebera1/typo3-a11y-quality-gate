<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures;

use Priebera\A11yQualityGate\Domain\Repository\Contract\AiSiteSettingRepositoryInterface;

final class InMemoryAiSiteSettingRepository implements AiSiteSettingRepositoryInterface
{
    /** @var array<string,array<string,mixed>> */
    public array $rows = [];

    public function findBySiteIdentifier(string $siteIdentifier): ?array
    {
        return $this->rows[$siteIdentifier] ?? null;
    }

    public function saveProvider(string $siteIdentifier, string $provider, int $backendUserUid): void
    {
        $this->rows[$siteIdentifier] = [
            'active_provider' => $provider,
            'provider_changed_by' => $backendUserUid,
            'provider_changed_at' => time(),
            'processing_ack_provider' => '',
            'processing_ack_by' => 0,
            'processing_ack_at' => 0,
        ] + ($this->rows[$siteIdentifier] ?? ['allow_image_input' => 1, 'allow_page_title' => 1]);
    }

    public function savePrivacy(string $siteIdentifier, bool $allowImageInput, bool $allowPageTitle): void
    {
        $this->rows[$siteIdentifier] = ['allow_image_input' => $allowImageInput ? 1 : 0, 'allow_page_title' => $allowPageTitle ? 1 : 0]
            + ($this->rows[$siteIdentifier] ?? ['active_provider' => '']);
    }

    public function saveLinkTextSuggestionsEnabled(string $siteIdentifier, bool $enabled): void
    {
        $this->rows[$siteIdentifier] = ['link_text_suggestions_enabled' => $enabled ? 1 : 0]
            + ($this->rows[$siteIdentifier] ?? ['active_provider' => '', 'allow_image_input' => 1, 'allow_page_title' => 1]);
    }

    public function saveBudget(string $siteIdentifier, int $budgetMicroUsd, int $warningPercent, bool $hardStop, int $backendUserUid): void
    {
        $this->rows[$siteIdentifier] = [
            'budget_micro_usd' => $budgetMicroUsd,
            'budget_warning_percent' => $warningPercent,
            'budget_hard_stop' => $hardStop ? 1 : 0,
            'budget_changed_by' => $backendUserUid,
        ] + ($this->rows[$siteIdentifier] ?? ['active_provider' => '', 'allow_image_input' => 1, 'allow_page_title' => 1]);
    }

    public function saveBudgetAlertEmail(string $siteIdentifier, string $email): void
    {
        $this->rows[$siteIdentifier] = ['budget_alert_email' => $email]
            + ($this->rows[$siteIdentifier] ?? ['active_provider' => '', 'allow_image_input' => 1, 'allow_page_title' => 1]);
    }

    public function saveAcknowledgement(string $siteIdentifier, string $provider, int $backendUserUid): void
    {
        $this->rows[$siteIdentifier] = [
            'processing_ack_provider' => $provider,
            'processing_ack_by' => $backendUserUid,
            'processing_ack_at' => time(),
        ] + ($this->rows[$siteIdentifier] ?? ['active_provider' => '', 'allow_image_input' => 1, 'allow_page_title' => 1]);
    }
}
