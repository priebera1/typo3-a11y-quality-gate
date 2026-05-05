<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Enum\FeatureFlag;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;

final class ProCapabilityService
{
    public function __construct(
        private readonly ProLicenceService $proLicenceService,
        private readonly ProSettings $proSettings,
    ) {
    }

    public function getStatus(string $domain, string $version): ProStatusViewModel
    {
        if (!$this->proSettings->isConfigured()) {
            return ProStatusViewModel::notConfigured(
                $this->proSettings->showProHints(),
                $domain
            );
        }

        $result = $this->proLicenceService->validate($domain, $version);

        $effectiveIsTrial = $result->isTrial || $this->proSettings->isTrialKey();
        $effectivePlan = $effectiveIsTrial ? 'trial' : $result->plan;

        $hasCrawler = $result->hasFeature(FeatureFlag::Crawler);
        $hasExportPdf = !$effectiveIsTrial && $result->hasFeature(FeatureFlag::ExportPdf);
        $hasMultiSite = !$effectiveIsTrial && $result->hasFeature(FeatureFlag::MultiSite);
        $hasProRules = $result->hasFeature(FeatureFlag::ProRules);

        return new ProStatusViewModel(
            configured: true,
            valid: $result->valid,
            proAvailable: $result->valid,
            plan: $effectivePlan,
            features: $result->features,
            reason: $result->reason,
            reasonLabel: $this->buildReasonLabel($result, $effectiveIsTrial),
            statusLabel: $this->buildStatusLabel($result, $effectiveIsTrial, $effectivePlan),
            showProHints: $this->proSettings->showProHints(),
            hasCrawler: $hasCrawler,
            hasExportPdf: $hasExportPdf,
            hasMultiSite: $hasMultiSite,
            hasProRules: $hasProRules,
            expiresAt: $result->expiresAt,
            domain: $domain,
            isTrial: $effectiveIsTrial,
            trialExpiresAt: $result->trialExpiresAt,
            trialStartedAt: $result->trialStartedAt,
        );
    }

    private function buildStatusLabel(
        LicenceValidationResult $result,
        bool $isTrial,
        string $plan,
    ): string {
        if ($result->valid) {
            if ($isTrial) {
                return 'Trial active';
            }

            return $plan !== ''
                ? 'Licence active — ' . ucfirst($plan)
                : 'Licence active';
        }

        return match ($result->reason) {
            'invalid_key' => 'Invalid licence key',
            'expired' => 'Licence expired',
            'inactive' => 'Licence inactive',
            'domain_mismatch' => 'Domain mismatch',
            'domain_limit_reached' => 'Domain limit reached',
            'project_mismatch', 'licence_project_mismatch', 'trial_project_mismatch' => 'Different TYPO3 project',
            'trial_expired' => 'Trial expired',
            'trial_domain_mismatch' => 'Trial domain mismatch',
            'trial_revoked' => 'Trial revoked',
            'trial_not_verified' => 'Trial not verified',
            'api_unreachable' => 'API unreachable',
            'not_configured' => 'Licence not configured',
            default => 'Licence unavailable',
        };
    }

    private function buildReasonLabel(
        LicenceValidationResult $result,
        bool $isTrial,
    ): ?string {
        if ($result->valid) {
            if ($isTrial) {
                return 'Trial licence is active.';
            }

            return 'Licence is active and ready.';
        }

        return match ($result->reason) {
            'invalid_key' => 'The configured licence key is invalid.',
            'expired' => 'The configured licence has expired.',
            'inactive' => 'The configured licence is inactive.',
            'domain_mismatch' => 'This TYPO3 site domain is not allowed for the configured licence.',
            'domain_limit_reached' => 'Domain limit reached for this licence. Log in to your portal to manage domains.',
            'project_mismatch', 'licence_project_mismatch' => 'This licence is registered to a different TYPO3 project. If this is unexpected, contact support.',
            'trial_expired' => 'This trial has expired.',
            'trial_domain_mismatch' => 'This trial belongs to a different domain.',
            'trial_project_mismatch' => 'This trial is registered to a different TYPO3 project.',
            'trial_revoked' => 'This trial was revoked.',
            'trial_not_verified' => 'This trial is not verified yet.',
            'api_unreachable' => 'The AQG API could not be reached. Free features still work normally.',
            'not_configured' => 'No AQG licence key is configured yet.',
            default => 'AQG licence is currently unavailable.',
        };
    }
}