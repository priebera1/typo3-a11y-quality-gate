<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Settings;

final readonly class AiSiteSettings
{
    /**
     * @param string $provider the provider AQG uses for this site (OpenAI for sites without a choice, as in 2.x)
     * @param bool $providerChosen whether an administrator chose the provider or the site already used OpenAI in 2.x
     * @param int $budgetMicroUsd monthly budget in micro-USD; 0 = no budget
     * @param int $budgetWarningPercent warning threshold; 0 = no warning
     * @param bool $budgetHardStop whether AI pauses for editors when the budget is reached (false = warning only)
     * @param string $acknowledgedProvider the provider whose processing basis an administrator confirmed ('' = none)
     * @param string $budgetAlertEmail the site's budget alert recipient ('' = none; see AiBudgetAlertRecipient)
     */
    public function __construct(
        public string $provider,
        public bool $providerChosen,
        public bool $allowImageInput,
        public bool $allowPageTitle,
        public int $providerChangedAt = 0,
        public int $budgetMicroUsd = 0,
        public int $budgetWarningPercent = 0,
        public bool $budgetHardStop = false,
        public string $acknowledgedProvider = '',
        public int $acknowledgedBy = 0,
        public int $acknowledgedAt = 0,
        public string $budgetAlertEmail = '',
    ) {}

    /** The processing acknowledgement exists for the active provider (it never carries over to the other one). */
    public function isAcknowledged(): bool
    {
        return $this->acknowledgedAt > 0 && $this->acknowledgedProvider !== '' && $this->acknowledgedProvider === $this->provider;
    }

    public function hasBudget(): bool
    {
        return $this->budgetMicroUsd > 0;
    }
}
