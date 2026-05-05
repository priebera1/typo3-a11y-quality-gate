<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\ViewModel;

final class ProStatusViewModel
{
    /**
     * @param list<string> $features
     */
    public function __construct(
        public readonly bool $configured,
        public readonly bool $valid,
        public readonly bool $proAvailable,
        public readonly string $plan,
        public readonly array $features,
        public readonly ?string $reason,
        public readonly ?string $reasonLabel,
        public readonly string $statusLabel,
        public readonly bool $showProHints,
        public readonly bool $hasCrawler,
        public readonly bool $hasExportPdf,
        public readonly bool $hasMultiSite,
        public readonly bool $hasProRules,
        public readonly ?string $expiresAt = null,
        public readonly string $domain = '',
        public readonly bool $isTrial = false,
        public readonly ?string $trialExpiresAt = null,
        public readonly ?string $trialStartedAt = null,
    ) {
    }

    public static function notConfigured(bool $showProHints, string $domain = ''): self
    {
        return new self(
            configured: false,
            valid: false,
            proAvailable: false,
            plan: '',
            features: [],
            reason: 'not_configured',
            reasonLabel: 'Licence not configured',
            statusLabel: 'Licence not configured',
            showProHints: $showProHints,
            hasCrawler: false,
            hasExportPdf: false,
            hasMultiSite: false,
            hasProRules: false,
            expiresAt: null,
            domain: $domain,
            isTrial: false,
            trialExpiresAt: null,
            trialStartedAt: null,
        );
    }
}