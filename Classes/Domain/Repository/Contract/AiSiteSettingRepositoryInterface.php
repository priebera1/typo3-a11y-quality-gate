<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

interface AiSiteSettingRepositoryInterface
{
    /** @return array<string,mixed>|null */
    public function findBySiteIdentifier(string $siteIdentifier): ?array;

    /** Also clears the processing acknowledgement: it names one provider and never carries over to another. */
    public function saveProvider(string $siteIdentifier, string $provider, int $backendUserUid): void;

    public function savePrivacy(string $siteIdentifier, bool $allowImageInput, bool $allowPageTitle): void;

    public function saveLinkTextSuggestionsEnabled(string $siteIdentifier, bool $enabled): void;

    /** $budgetMicroUsd 0 removes the budget; $warningPercent 0 means no warning threshold. */
    public function saveBudget(string $siteIdentifier, int $budgetMicroUsd, int $warningPercent, bool $hardStop, int $backendUserUid): void;

    /** The site's budget alert recipient; '' removes it. The caller validates the address. */
    public function saveBudgetAlertEmail(string $siteIdentifier, string $email): void;

    /** Records that $backendUserUid confirmed the processing basis for $provider on this site (user and time only). */
    public function saveAcknowledgement(string $siteIdentifier, string $provider, int $backendUserUid): void;
}
