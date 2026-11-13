<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * U-10 (2026-11-05 company acceptance): a PRO licence whose key starts with `aqg_test_` was shown as TRIAL and lost PDF and
 * multi-site, because the plan came from the key prefix. The API's answer decides; the prefix is only the fallback when the
 * answer names no plan (outage, invalid key).
 */
final class ProCapabilityPlanSourceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[Test]
    public function aValidatedPlanWinsOverATrialLookingKeyPrefix(): void
    {
        $status = $this->service(new LicenceValidationResult(valid: true, plan: 'pro'), trialKey: true)->getStatus('example.org', '3.0.0');

        self::assertSame('pro', $status->plan);
        self::assertFalse($status->isTrial);
        self::assertTrue($status->hasExportPdf);
    }

    #[Test]
    public function aTrialAnswerStaysATrial(): void
    {
        $status = $this->service(new LicenceValidationResult(valid: true, plan: 'trial', isTrial: true), trialKey: false)->getStatus('example.org', '3.0.0');

        self::assertSame('trial', $status->plan);
        self::assertTrue($status->isTrial);
        self::assertFalse($status->hasExportPdf);
    }

    #[Test]
    public function withoutAPlanInTheAnswerTheKeyPrefixStillMarksATrial(): void
    {
        $status = $this->service(LicenceValidationResult::invalid('api_unreachable'), trialKey: true)->getStatus('example.org', '3.0.0');

        self::assertTrue($status->isTrial);
    }

    private function service(LicenceValidationResult $result, bool $trialKey): ProCapabilityService
    {
        $licence = $this->createMock(ProLicenceService::class);
        $licence->method('validate')->willReturn($result);
        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn(true);
        $settings->method('isTrialKey')->willReturn($trialKey);

        return new ProCapabilityService($licence, $settings);
    }
}
