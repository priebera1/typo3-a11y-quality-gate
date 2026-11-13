<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

interface AiConfigurationManagerInterface
{
    /**
     * Stores the key of the site's active provider. A given $provider must match the active provider, so a form
     * rendered before a provider switch cannot store its key under the wrong provider.
     */
    public function save(string $siteIdentifier, #[\SensitiveParameter] string $apiKey, ?string $provider = null): void;

    public function selectModel(string $siteIdentifier, string $modelId): void;

    public function setLinkTextSuggestionsEnabled(string $siteIdentifier, bool $enabled): void;

    /**
     * Makes $provider the site's only active provider. The other provider's key and settings are kept; the new
     * provider's earlier verification is cleared, so AI stays paused until a connection test passes.
     *
     * @return bool whether the active provider changed
     */
    public function selectProvider(string $siteIdentifier, string $provider, int $backendUserUid): bool;

    public function savePrivacy(string $siteIdentifier, bool $allowImageInput, bool $allowPageTitle): void;

    /**
     * Saves the site's monthly budget (UX Atlas 01 E "Limits"). An empty $budget removes it. A budget needs at least
     * $1, a price for the active model, and an explicit hard-stop choice ('1' pause AI for editors, '0' warn only);
     * $warningPercent is optional (1–100). $alertEmail is the budget alert recipient (F-39): null keeps the stored one,
     * '' removes it, anything else must be one valid e-mail address. It is kept when the budget is removed.
     *
     * @throws \InvalidArgumentException with a safe code as message: invalid_budget, invalid_warning_percent,
     *         hard_stop_required, invalid_alert_email
     */
    public function saveBudget(string $siteIdentifier, string $budget, string $warningPercent, string $hardStop, int $backendUserUid, ?string $alertEmail = null): void;

    /**
     * Records the processing acknowledgement for the site's active provider (UX Atlas 01 F). $provider must still be
     * the active provider, so a form rendered before a switch cannot confirm the other one.
     */
    public function acknowledgeProcessing(string $siteIdentifier, string $provider, int $backendUserUid): void;
}
