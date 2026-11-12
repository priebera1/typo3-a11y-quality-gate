<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Pricing\AiPricingStatusService;
use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Settings\AiBackendUserNames;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettings;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertRecipient;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetStatus;
use Priebera\A11yQualityGate\Ai\Usage\AiMoney;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageSummaryService;
use Priebera\A11yQualityGate\Utility\BackendLocaleFormatter;

final class AiSettingsUiStateBuilder
{
    /** Features named under a person in the usage report; the rest is "+N more". */
    private const PERSON_FEATURES_SHOWN = 3;

    private const CONNECTION_STATUSES = [
        'not_configured',
        'not_verified',
        'connected',
        'connection_failed',
    ];

    public function __construct(
        private readonly AiConfigurationResolverInterface $configurationResolver,
        private readonly ?AiUsageSummaryService $usageSummaryService = null,
        private readonly ?AiFeatureAccessServiceInterface $featureAccessService = null,
        private readonly ?AiPricingStatusService $pricingStatus = null,
        private readonly ?AiBackendUserNames $userNames = null,
        private readonly ?AiSiteSettingsService $siteSettingsService = null,
    ) {}

    /**
     * Dates, numbers and amounts are formatted in the backend user's language ($formatter only for tests). $usageMonth
     * ("YYYY-MM") picks the usage report's month; anything else shows the current month.
     *
     * @return array<string,mixed>
     */
    public function build(string $siteIdentifier, ?BackendLocaleFormatter $formatter = null, string $usageMonth = ''): array
    {
        $formatter ??= BackendLocaleFormatter::forBackendUser();
        $status = $this->configurationResolver->status($siteIdentifier);
        $configured = (bool)($status['configured'] ?? false);
        $selectedModelAvailable = (bool)($status['selectedModelAvailable'] ?? false);
        $connectionStatus = trim((string)($status['connectionStatus'] ?? 'not_configured'));
        if (!in_array($connectionStatus, self::CONNECTION_STATUSES, true)) {
            $connectionStatus = $configured ? 'not_verified' : 'not_configured';
        }

        $state = $status;
        $state['configured'] = $configured;
        $state['linkTextSuggestionsEnabled'] = (bool)($status['linkTextSuggestionsEnabled'] ?? false);
        $state['modelSelected'] = $selectedModelAvailable;
        $state['selectedModelAvailable'] = $selectedModelAvailable;
        $state['status'] = $connectionStatus;
        $state['errorCode'] = trim((string)($status['lastTestErrorCode'] ?? ''));
        $state['lastTestedAt'] = max(0, (int)($status['lastTestedAt'] ?? 0));
        $state['lastVerifiedAt'] = max(0, (int)($status['lastVerifiedAt'] ?? 0));
        $state['availableModels'] = $this->normalizeAvailableModels($status['availableModels'] ?? []);
        $state['unsupportedModels'] = $this->normalizeUnsupportedModels($status['unsupportedModels'] ?? []);
        $state['keyHintDisplay'] = self::keyHintDisplay((string)($status['keyHint'] ?? ''));
        $state['provider'] = (string)($status['provider'] ?? '');
        $state['providerLabel'] = (string)($status['providerLabel'] ?? '');
        $state['providerChosen'] = (bool)($status['providerChosen'] ?? false);
        $state['providers'] = $this->normalizeProviders($status['providers'] ?? []);
        $state['revalidationRequired'] = (bool)($status['revalidationRequired'] ?? false);
        $state['allowImageInput'] = (bool)($status['allowImageInput'] ?? true);
        $state['allowPageTitle'] = (bool)($status['allowPageTitle'] ?? true);
        $state['verified'] = $connectionStatus === 'connected';
        $usage = $this->usageSummaryService?->month($siteIdentifier, $usageMonth);
        if (is_array($usage)) {
            $usage = $this->localizeUsage($usage, $formatter);
        }
        $state['usage'] = $usage;
        // The same licence check that shows AI to editors (PRO or Agency); the settings stay editable without it.
        $state['licenceAllowsAi'] = $siteIdentifier !== '' && ($this->featureAccessService?->isAvailable($siteIdentifier) ?? true);
        // The readiness line uses the policy result of the resolver; the licence item is the same check editors get.
        $readiness = is_array($status['readiness'] ?? null) ? $status['readiness'] : [];
        $readiness[AiReadinessPolicy::ITEM_LICENCE] = $state['licenceAllowsAi'] ? AiReadinessPolicy::OK : AiReadinessPolicy::MISSING;
        $items = [];
        foreach (AiReadinessPolicy::ITEMS as $item) {
            $value = (string)($readiness[$item] ?? '');
            $items[$item] = in_array($value, [AiReadinessPolicy::OK, AiReadinessPolicy::WARN], true) ? $value : AiReadinessPolicy::MISSING;
        }
        $state['readiness'] = $items;
        $state['readyForEditors'] = (new AiReadiness($items))->isReady();
        $state['acknowledgement'] = $this->acknowledgement($status, $formatter);
        $state['budget'] = $this->budget($status, $formatter) + $this->budgetAlert($siteIdentifier, $status);
        $state['pricing'] = $this->pricing($state, (string)($status['selectedModelId'] ?? ''), $formatter);
        if ($this->pricingStatus !== null) {
            $prices = $this->pricingStatus->modelPrices($state['provider'], $state['availableModels'], null, $formatter);
            foreach ($state['availableModels'] as $index => $model) {
                $state['availableModels'][$index]['price'] = $prices[$model['id']] ?? ['known' => false];
            }
        }
        // Grouping separator for f:format.number in the template ("1,332" / "1.332").
        $state['thousandsSeparator'] = $formatter->thousandsSeparator();
        $state['lastVerifiedLabel'] = $formatter->dateTime($state['lastVerifiedAt']);
        $state['lastTestedLabel'] = $formatter->dateTime($state['lastTestedAt']);
        $state['actions'] = [
            'refreshModelsEnabled' => $configured,
            // After the administrator's own failed test the key or model must change first. An editor request the
            // provider rejected (provider_<code>, AiUsageRecorder) pauses AI with "test the connection again" as the
            // way out, so that test stays available (N-10).
            'testConnectionEnabled' => $configured
                && $selectedModelAvailable
                && ($connectionStatus !== 'connection_failed' || str_starts_with((string)($state['errorCode'] ?? ''), 'provider_')),
        ];

        return $state;
    }

    /**
     * @param array<string,mixed> $status
     * @return array{confirmed:bool,provider:string,providerLabel:string,byName:string,at:int,atDate:string}
     */
    private function acknowledgement(array $status, BackendLocaleFormatter $formatter): array
    {
        $acknowledgement = is_array($status['acknowledgement'] ?? null) ? $status['acknowledgement'] : [];
        $confirmed = (bool)($acknowledgement['confirmed'] ?? false);
        $at = $confirmed ? max(0, (int)($acknowledgement['at'] ?? 0)) : 0;

        return [
            'confirmed' => $confirmed,
            'provider' => $confirmed ? (string)($acknowledgement['provider'] ?? '') : '',
            'providerLabel' => $confirmed ? AiProviderCatalog::label((string)($acknowledgement['provider'] ?? '')) : '',
            'byName' => $confirmed ? (string)$this->userNames?->name((int)($acknowledgement['by'] ?? 0)) : '',
            'at' => $at,
            'atDate' => $formatter->date($at),
        ];
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function budget(array $status, BackendLocaleFormatter $formatter): array
    {
        $budget = is_array($status['budget'] ?? null) ? $status['budget'] : [];
        $state = (string)($budget['state'] ?? AiBudgetStatus::STATE_NONE);
        $budgetMicro = max(0, (int)($budget['budgetMicroUsd'] ?? 0));
        $committed = max(0, (int)($budget['spentMicroUsd'] ?? 0)) + max(0, (int)($budget['reservedMicroUsd'] ?? 0));
        $periodEnd = max(0, (int)($budget['periodEnd'] ?? 0));

        return [
            'state' => $state,
            'hasBudget' => $budgetMicro > 0,
            'budgetMicroUsd' => $budgetMicro,
            'budgetLabel' => $formatter->usd($budgetMicro),
            'budgetInput' => AiMoney::toInput($budgetMicro),
            'warningPercent' => max(0, (int)($budget['warningPercent'] ?? 0)),
            // The warning marker on the budget bar, in steps of 5 % (a CSS class; the backend allows no inline styles).
            'markerStep' => (int)($budget['warningPercent'] ?? 0) > 0 ? min(100, max(5, (int)round((int)$budget['warningPercent'] / 5) * 5)) : 0,
            'warningInput' => (int)($budget['warningPercent'] ?? 0) > 0 ? (string)(int)$budget['warningPercent'] : '',
            'hardStop' => (bool)($budget['hardStop'] ?? false),
            'committedLabel' => $formatter->usd($committed),
            'percent' => max(0, (int)($budget['percent'] ?? 0)),
            'barPercent' => min(100, max(0, (int)($budget['percent'] ?? 0))),
            'priceKnown' => (bool)($budget['priceKnown'] ?? false),
            'blockCode' => (string)($budget['blockCode'] ?? ''),
            'blocking' => (string)($budget['blockCode'] ?? '') !== '',
            'resumeDate' => $formatter->date($periodEnd),
            'monthLabel' => $periodEnd > 0 ? $formatter->monthYear($periodEnd - 1) : '',
            'modelLabel' => (string)($status['selectedModelLabel'] ?? '') !== '' ? (string)$status['selectedModelLabel'] : (string)($status['selectedModelId'] ?? ''),
            'modelSelected' => trim((string)($status['selectedModelId'] ?? '')) !== '',
        ];
    }

    /**
     * The budget alert e-mail (F-39): the stored site address (form value) and the effective recipient with its source
     * (site, typo3 = [BE][warning_email_addr], none). Only administrators receive the UI state.
     *
     * @param array<string,mixed> $status
     * @return array{alertEmailInput:string,alertRecipient:string,alertRecipientSource:string,alertActive:bool}
     */
    private function budgetAlert(string $siteIdentifier, array $status): array
    {
        $budget = is_array($status['budget'] ?? null) ? $status['budget'] : [];
        $settings = null;
        if ($this->siteSettingsService !== null && trim($siteIdentifier) !== '') {
            try {
                $settings = $this->siteSettingsService->get($siteIdentifier);
            } catch (\Throwable) {
                $settings = null;
            }
        }
        $recipient = AiBudgetAlertRecipient::resolve($settings ?? new AiSiteSettings('', false, true, true));

        return [
            'alertEmailInput' => $settings?->budgetAlertEmail ?? '',
            'alertRecipient' => $recipient->address,
            'alertRecipientSource' => $recipient->source,
            'alertActive' => (int)($budget['budgetMicroUsd'] ?? 0) > 0 && (int)($budget['warningPercent'] ?? 0) > 0,
        ];
    }

    /**
     * Read-only automatic pricing (no administrator ever enters a price): the selected model's price and the
     * diagnostics. Never fails the settings screen.
     *
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private function pricing(array $state, string $selectedModelId, BackendLocaleFormatter $formatter): array
    {
        if ($this->pricingStatus === null) {
            return ['selected' => ['known' => false], 'diagnostics' => ['providers' => [], 'legacy' => [], 'anyFailed' => false]];
        }
        try {
            return [
                'selected' => $this->pricingStatus->modelPrice((string)$state['provider'], $selectedModelId, null, $formatter),
                'diagnostics' => $this->pricingStatus->diagnostics(null, $formatter),
            ];
        } catch (\Throwable) {
            return ['selected' => ['known' => false], 'diagnostics' => ['providers' => [], 'legacy' => [], 'anyFailed' => false]];
        }
    }

    /**
     * The usage report with locale labels: period, and per row every amount (estimated, worst case for requests without
     * usage data, reserved for pending attempts, exposure). A cost below one cent is already a bound ("< $0.01"), so the
     * template shows "≈" only in front of other amounts.
     *
     * @param array<string,mixed> $usage
     * @return array<string,mixed>
     */
    private function localizeUsage(array $usage, BackendLocaleFormatter $formatter): array
    {
        $periodStart = (int)($usage['periodStart'] ?? 0);
        $periodEnd = (int)($usage['periodEnd'] ?? 0);
        $usage['periodStartDate'] = $formatter->date($periodStart);
        $usage['periodEndDate'] = $periodEnd > 0 ? $formatter->date($periodEnd - 1) : '';
        $usage['monthLabel'] = $periodStart > 0 ? $formatter->monthYear($periodStart) : '';
        $localize = static function (array $row) use ($formatter): array {
            foreach (['estimated', 'bound', 'reserved', 'exposure', 'reported'] as $amount) {
                $micro = (int)($row[$amount . 'MicroUsd'] ?? 0);
                $row[$amount . 'Label'] = $formatter->usd($micro);
                $row[$amount . 'IsBound'] = BackendLocaleFormatter::isBelowOneCent($micro);
            }
            if ((string)($row['costLabel'] ?? '') !== '') {
                $row['costLabel'] = $formatter->usd((int)($row['costMicroUsd'] ?? 0));
            }
            $row['costIsBound'] = BackendLocaleFormatter::isBelowOneCent((int)($row['costMicroUsd'] ?? 0));
            $row['priced'] = max(0, (int)($row['requests'] ?? 0) - (int)($row['unknown'] ?? 0));

            return $row;
        };
        if (is_array($usage['rows'] ?? null)) {
            $usage['rows'] = array_map(static fn(mixed $row): mixed => is_array($row) ? $localize($row) : $row, $usage['rows']);
        }
        if (is_array($usage['totals'] ?? null)) {
            $usage['totals'] = $localize($usage['totals']);
        }
        $usage['people'] = $this->people(is_array($usage['people'] ?? null) ? $usage['people'] : [], $localize);
        $usage['peopleCount'] = count($usage['people']);
        $usage['hasUsage'] = (int)($usage['totals']['requests'] ?? 0) > 0;
        $usage['rounding'] = self::roundingNotes($usage['people'], is_array($usage['totals'] ?? null) ? $usage['totals'] : [], $formatter);

        return $usage;
    }

    /**
     * Where the per-person amounts, each rounded to the cent, do not add up to the site total rounded once from the
     * exact micro-USD sums: one note per amount (estimated spend, reserved) with both figures, so the administrator sees
     * the cent comes from rounding and not from a missing record. The exact values are unchanged (CSV has them).
     *
     * @param list<array<string,mixed>> $people
     * @param array<string,mixed> $totals
     * @return list<array{amount:string,rowsLabel:string,totalLabel:string}>
     */
    private static function roundingNotes(array $people, array $totals, BackendLocaleFormatter $formatter): array
    {
        $cents = static fn (int $micro): int => intdiv(max(0, $micro) + 5_000, 10_000);
        $notes = [];
        foreach (['estimated', 'reserved'] as $amount) {
            $key = $amount . 'MicroUsd';
            $rows = 0;
            foreach ($people as $person) {
                $rows += $cents((int)($person[$key] ?? 0));
            }
            $total = (int)($totals[$key] ?? 0);
            if ($people !== [] && $rows !== $cents($total)) {
                $notes[] = ['amount' => $amount, 'rowsLabel' => $formatter->usd($rows * 10_000), 'totalLabel' => $formatter->usd($total)];
            }
        }

        return $notes;
    }

    /**
     * "By person": every backend user with usage in the period, largest exposure first, with the current display name,
     * username and account state (deleted and disabled accounts keep their historical usage). be_user 0 = requests
     * without a backend user. Only administrators receive this state (Settings tab and AI settings AJAX).
     *
     * @param list<array<string,mixed>> $people
     * @param \Closure(array<string,mixed>):array<string,mixed> $localize
     * @return list<array<string,mixed>>
     */
    private function people(array $people, \Closure $localize): array
    {
        $rows = [];
        foreach ($people as $person) {
            $uid = (int)($person['beUser'] ?? 0);
            $account = $uid > 0
                ? ($this->userNames?->describe($uid) ?? ['name' => '#' . $uid, 'username' => '', 'state' => 'active'])
                : ['name' => '', 'username' => '', 'state' => 'none'];
            $features = is_array($person['features'] ?? null) ? array_values($person['features']) : [];
            $row = $localize($person);
            $row['name'] = $account['name'];
            // The username is shown only when it adds something to the display name.
            $row['username'] = $account['username'] !== $account['name'] ? $account['username'] : '';
            $row['accountState'] = $account['state'];
            // "#12" is shown next to the name, unless the name already is that id (an account without a record).
            $row['showId'] = $uid > 0 && $account['name'] !== '#' . $uid;
            $row['featuresShown'] = array_slice($features, 0, self::PERSON_FEATURES_SHOWN);
            $row['featuresMore'] = max(0, count($features) - self::PERSON_FEATURES_SHOWN);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * The stored key as the key card shows it: hints since 2.0 already read "sk-ant-…x9Q2"; an older hint is only the
     * last characters, and a very short key is "configured".
     */
    public static function keyHintDisplay(string $hint): string
    {
        $hint = trim($hint);

        return match (true) {
            $hint === '' => '',
            str_contains($hint, '…') => $hint,
            $hint === 'configured' => '••••••••',
            default => '••••' . $hint,
        };
    }

    /** @return list<array{id:string,label:string,active:bool,configured:bool,source:string,keyHint:string,selectedModelId:string,selectedModelLabel:string}> */
    private function normalizeProviders(mixed $providers): array
    {
        if (!is_array($providers)) {
            return [];
        }

        $normalized = [];
        foreach ($providers as $provider) {
            if (!is_array($provider) || trim((string)($provider['id'] ?? '')) === '') {
                continue;
            }
            $normalized[] = [
                'id' => trim((string)$provider['id']),
                'label' => (string)($provider['label'] ?? ''),
                'active' => (bool)($provider['active'] ?? false),
                'configured' => (bool)($provider['configured'] ?? false),
                'source' => (string)($provider['source'] ?? 'none'),
                'keyHint' => (string)($provider['keyHint'] ?? ''),
                'selectedModelId' => (string)($provider['selectedModelId'] ?? ''),
                'selectedModelLabel' => (string)($provider['selectedModelLabel'] ?? ''),
            ];
        }

        return $normalized;
    }

    /** @return list<array{id:string,label:string,price?:array<string,mixed>}> */
    private function normalizeAvailableModels(mixed $models): array
    {
        if (!is_array($models)) {
            return [];
        }

        $normalized = [];
        foreach ($models as $model) {
            if (!is_array($model)) {
                continue;
            }
            $id = trim((string)($model['id'] ?? ''));
            $label = trim((string)($model['label'] ?? ''));
            if ($id === '' || $label === '') {
                continue;
            }
            $normalized[$id] = ['id' => $id, 'label' => $label];
        }

        return array_values($normalized);
    }

    /** @return list<string> */
    private function normalizeUnsupportedModels(mixed $models): array
    {
        if (!is_array($models)) {
            return [];
        }

        $normalized = [];
        foreach ($models as $modelId) {
            $modelId = trim((string)$modelId);
            if ($modelId !== '') {
                $normalized[$modelId] = $modelId;
            }
        }

        return array_values($normalized);
    }
}
