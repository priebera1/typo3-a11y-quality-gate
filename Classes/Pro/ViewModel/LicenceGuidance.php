<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\ViewModel;

/**
 * The next step for a licence that did not validate, derived from the licence API's reason code.
 *
 * Each reason gets an explanation and the call to action that can actually resolve it: an ended trial
 * leads to the plans, an expired licence to the renewal in the customer portal, an outage to a retry.
 * The mapping only chooses copy and links — it never grants a capability.
 */
final class LicenceGuidance
{
    public const ACTION_RETRY = 'retry';
    public const ACTION_PRICING = 'pricing';
    public const ACTION_PORTAL = 'portal';
    public const ACTION_SUPPORT = 'support';

    /**
     * @var array<string, array{0:string, 1:array{0:string,1:string}, 2:array{0:string,1:string}|null}>
     *      state => [translation key stem, [primary action, label key], [secondary action, label key]|null]
     */
    private const STATES = [
        'trial_expired' => ['trialExpired', [self::ACTION_PRICING, 'settings.licence.cta.choosePlan'], null],
        'expired' => ['expired', [self::ACTION_PORTAL, 'settings.licence.cta.renew'], [self::ACTION_PRICING, 'settings.licence.cta.comparePlans']],
        'inactive' => ['inactive', [self::ACTION_PORTAL, 'settings.licence.openCustomerPortal'], [self::ACTION_SUPPORT, 'settings.licence.contactSupport']],
        'domain_limit_reached' => ['domainLimitReached', [self::ACTION_PORTAL, 'settings.licence.manageDomains'], [self::ACTION_PRICING, 'settings.licence.cta.comparePlans']],
        'domain_mismatch' => ['domainMismatch', [self::ACTION_PORTAL, 'settings.licence.manageDomains'], [self::ACTION_SUPPORT, 'settings.licence.contactSupport']],
        'project_mismatch' => ['projectMismatch', [self::ACTION_SUPPORT, 'settings.licence.contactSupport'], [self::ACTION_PRICING, 'settings.licence.cta.comparePlans']],
        'trial_domain_mismatch' => ['trialDomainMismatch', [self::ACTION_PRICING, 'settings.licence.cta.choosePlan'], [self::ACTION_SUPPORT, 'settings.licence.contactSupport']],
        'trial_project_mismatch' => ['trialProjectMismatch', [self::ACTION_PRICING, 'settings.licence.cta.choosePlan'], [self::ACTION_SUPPORT, 'settings.licence.contactSupport']],
        'trial_not_verified' => ['trialNotVerified', [self::ACTION_RETRY, 'settings.licence.cta.validateAgain'], [self::ACTION_SUPPORT, 'settings.licence.contactSupport']],
        'trial_revoked' => ['trialRevoked', [self::ACTION_SUPPORT, 'settings.licence.contactSupport'], [self::ACTION_PRICING, 'settings.licence.cta.comparePlans']],
        'invalid_key' => ['invalidKey', [self::ACTION_PORTAL, 'settings.licence.openCustomerPortal'], [self::ACTION_SUPPORT, 'settings.licence.contactSupport']],
        'api_unreachable' => ['unavailable', [self::ACTION_RETRY, 'action.retry'], null],
        'rate_limited' => ['rateLimited', [self::ACTION_RETRY, 'action.retry'], null],
        'unknown' => ['unknown', [self::ACTION_RETRY, 'action.retry'], [self::ACTION_SUPPORT, 'settings.licence.contactSupport']],
    ];

    /** @var array<string, string> reason codes that share a state */
    private const ALIASES = [
        'licence_project_mismatch' => 'project_mismatch',
        'trial_invalid' => 'invalid_key',
        'empty_key' => 'invalid_key',
        'invalid' => 'invalid_key',
    ];

    private function __construct(
        public readonly string $state,
        public readonly string $titleKey,
        public readonly string $textKey,
        public readonly string $primaryAction,
        public readonly string $primaryLabelKey,
        public readonly string $secondaryAction,
        public readonly string $secondaryLabelKey,
    ) {
    }

    public static function forReason(?string $reason): self
    {
        $reason = strtolower(trim((string)$reason));
        $state = self::ALIASES[$reason] ?? $reason;
        if (!isset(self::STATES[$state])) {
            $state = 'unknown';
        }

        [$stem, $primary, $secondary] = self::STATES[$state];

        return new self(
            state: $state,
            titleKey: 'settings.licence.guidance.' . $stem . '.title',
            textKey: 'settings.licence.guidance.' . $stem . '.text',
            primaryAction: $primary[0],
            primaryLabelKey: $primary[1],
            secondaryAction: $secondary[0] ?? '',
            secondaryLabelKey: $secondary[1] ?? '',
        );
    }

    /**
     * Every translation key the guidance can reference, so translation parity can be verified.
     *
     * @return list<string>
     */
    public static function translationKeys(): array
    {
        $keys = [];
        foreach (self::STATES as [$stem, $primary, $secondary]) {
            $keys[] = 'settings.licence.guidance.' . $stem . '.title';
            $keys[] = 'settings.licence.guidance.' . $stem . '.text';
            $keys[] = $primary[1];
            if ($secondary !== null) {
                $keys[] = $secondary[1];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param array<string, string> $urls action => URL; an action without a URL is left out
     * @param \Closure(string): string $translate
     * @return array{state:string,title:string,text:string,actions:list<array{action:string,label:string,url:string,primary:bool,external:bool}>}
     */
    public function toView(array $urls, \Closure $translate): array
    {
        $actions = [];
        foreach ([[$this->primaryAction, $this->primaryLabelKey, true], [$this->secondaryAction, $this->secondaryLabelKey, false]] as [$action, $labelKey, $primary]) {
            $url = trim((string)($urls[$action] ?? ''));
            if ($action === '' || $url === '') {
                continue;
            }
            $actions[] = [
                'action' => $action,
                'label' => $translate($labelKey),
                'url' => $url,
                'primary' => $primary,
                // A retry re-validates inside the backend; every other action is a public website page.
                'external' => $action !== self::ACTION_RETRY,
            ];
        }

        return [
            'state' => $this->state,
            'title' => $translate($this->titleKey),
            'text' => $translate($this->textKey),
            'actions' => $actions,
        ];
    }
}
