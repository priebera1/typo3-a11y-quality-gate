<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiFeatureAccessPolicy;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;

/**
 * The 14-day Agency Trial is an ordinary Agency licence (`aqg_live_…` key, plan `agency`, `expires_at` = the
 * trial end) that aqg-api marks only with the additive `agency_trial` field. It must get every Agency feature —
 * AI suggestions, the Statement Assistant, PDF, multi-site — and must never be mistaken for the retired
 * account-less `aqg_trial_…` key (plan `trial`), whose reduced scope `isTrial` describes.
 */
final class AgencyTrialEntitlementTest extends TestCase
{
    private function agencyTrialResult(bool $valid = true): LicenceValidationResult
    {
        return LicenceValidationResult::fromResponseDto(LicenceValidationResponseDto::fromArray([
            'success' => $valid,
            'valid' => $valid,
            'plan' => 'agency',
            'features' => ['crawler', 'export_pdf', 'multi_site', 'pro_rules'],
            'expires_at' => '2026-11-02T10:00:00.000Z',
            'agency_trial' => ['ends_at' => '2026-11-02T10:00:00.000Z'],
        ]));
    }

    #[Test]
    public function anAgencyTrialIsAnAgencyLicenceNotARetiredTrialKey(): void
    {
        $result = $this->agencyTrialResult();

        self::assertTrue($result->valid);
        self::assertSame('agency', $result->plan);
        self::assertFalse($result->isTrial);
        self::assertSame(strtotime('2026-11-02T10:00:00.000Z'), $result->entitlementExpiresAt());
    }

    #[Test]
    public function anAgencyTrialGetsAiSuggestionsAndTheStatementAssistant(): void
    {
        $result = $this->agencyTrialResult();
        $status = (object)['valid' => $result->valid, 'isTrial' => $result->isTrial, 'plan' => $result->plan, 'hasCrawler' => true];

        self::assertTrue((new AiFeatureAccessPolicy())->isAllowed($status));

        $statement = new \ReflectionMethod(SettingsController::class, 'hasStatementGeneratorCapability');
        $settings = (new \ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        self::assertTrue($statement->invoke($settings, $status));
    }

    #[Test]
    public function anEndedAgencyTrialGetsNothing(): void
    {
        $result = $this->agencyTrialResult(false);
        $status = (object)['valid' => $result->valid, 'isTrial' => $result->isTrial, 'plan' => $result->plan, 'hasCrawler' => false];

        self::assertFalse((new AiFeatureAccessPolicy())->isAllowed($status));
    }
}
