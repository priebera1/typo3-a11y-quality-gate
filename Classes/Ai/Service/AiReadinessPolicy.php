<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Usage\AiBudgetStatus;

/**
 * The one rule for "may editors of this site use AI?" (UX Atlas 01 A readiness line, D5): licence · provider chosen ·
 * key · verified model · processing acknowledgement for the active provider · budget not blocking. Every item must
 * hold. AiConfigurationResolver::resolve() applies it server-side before any non-test provider request (and so the
 * editor, media and queue availability, which all go through resolve()), and the Settings tab renders the same items,
 * so administrators see exactly why editors do or do not get AI.
 *
 * The licence is checked by the callers of resolve() (AiFeatureAccessService) before they resolve; the policy still
 * lists it so the tab shows it in the same line.
 */
final class AiReadinessPolicy
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const MISSING = 'missing';

    public const ITEM_LICENCE = 'licence';
    public const ITEM_PROVIDER = 'provider';
    public const ITEM_KEY = 'key';
    public const ITEM_MODEL = 'model';
    public const ITEM_ACKNOWLEDGEMENT = 'acknowledgement';
    public const ITEM_BUDGET = 'budget';

    public const ITEMS = [
        self::ITEM_LICENCE,
        self::ITEM_PROVIDER,
        self::ITEM_KEY,
        self::ITEM_MODEL,
        self::ITEM_ACKNOWLEDGEMENT,
        self::ITEM_BUDGET,
    ];

    /**
     * @param bool $modelVerified a model of the active provider is selected, available to the key and passed the
     *        connection test with the current key fingerprint, prompt and contract, and needs no revalidation
     */
    public static function evaluate(
        bool $licence,
        bool $providerChosen,
        bool $keyConfigured,
        bool $modelVerified,
        bool $acknowledged,
        AiBudgetStatus $budget,
    ): AiReadiness {
        return new AiReadiness([
            self::ITEM_LICENCE => $licence ? self::OK : self::MISSING,
            self::ITEM_PROVIDER => $providerChosen ? self::OK : self::MISSING,
            self::ITEM_KEY => $keyConfigured ? self::OK : self::MISSING,
            self::ITEM_MODEL => $modelVerified ? self::OK : self::MISSING,
            self::ITEM_ACKNOWLEDGEMENT => $acknowledged ? self::OK : self::MISSING,
            self::ITEM_BUDGET => self::budgetState($budget),
        ]);
    }

    private static function budgetState(AiBudgetStatus $budget): string
    {
        if ($budget->isBlocking()) {
            return self::MISSING;
        }

        // A warning, a reached warning-only budget or a budget without a price inform, but never block.
        return in_array($budget->state, [AiBudgetStatus::STATE_NONE, AiBudgetStatus::STATE_UNDER], true) ? self::OK : self::WARN;
    }
}
