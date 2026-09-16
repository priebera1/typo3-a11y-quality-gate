<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiFeatureAccessPolicy;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;

/**
 * Plan copy follows the entitlement code, which is authoritative: this pins the entitlements the copy
 * describes and rejects copy that sells a Free feature as paid-only.
 */
final class CommercialCopyConsistencyTest extends TestCase
{
    private const LANGUAGE_DIRECTORY = __DIR__ . '/../../../Resources/Private/Language/';

    #[Test]
    public function planEntitlementsMatchTheDocumentedPlanScope(): void
    {
        // Trial: frontend scans, but no PDF export and no multi-site.
        self::assertTrue($this->plan('planProvidesRemoteCrawler', 'trial'));
        self::assertFalse($this->plan('planProvidesPdfExport', 'trial'));
        self::assertFalse($this->plan('planProvidesMultiSite', 'trial'));

        // PRO: PDF export, no multi-site. Agency: everything.
        self::assertTrue($this->plan('planProvidesRemoteCrawler', 'pro'));
        self::assertTrue($this->plan('planProvidesPdfExport', 'pro'));
        self::assertFalse($this->plan('planProvidesMultiSite', 'pro'));
        self::assertTrue($this->plan('planProvidesPdfExport', 'agency'));
        self::assertTrue($this->plan('planProvidesMultiSite', 'agency'));

        // Free has no licensed crawler; its frontend scans are the separate Free Remote Preview.
        self::assertFalse($this->plan('planProvidesRemoteCrawler', ''));

        $ai = new AiFeatureAccessPolicy();
        self::assertFalse($ai->isAllowed((object)['valid' => true, 'isTrial' => true, 'plan' => 'trial']));
        self::assertTrue($ai->isAllowed((object)['valid' => true, 'isTrial' => false, 'plan' => 'pro']));
        self::assertTrue($ai->isAllowed((object)['valid' => true, 'isTrial' => false, 'plan' => 'agency']));

        $statement = new \ReflectionMethod(SettingsController::class, 'hasStatementGeneratorCapability');
        $settings = (new \ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        self::assertFalse($statement->invoke($settings, (object)['valid' => true, 'isTrial' => true, 'hasCrawler' => true, 'plan' => 'trial']));
        self::assertTrue($statement->invoke($settings, (object)['valid' => true, 'isTrial' => false, 'hasCrawler' => true, 'plan' => 'pro']));
    }

    #[Test]
    public function trialCopyNamesThePaidOnlyFeatures(): void
    {
        $trial = $this->englishSource('settings.licence.trial.activeText');

        foreach (['PDF export', 'AI suggestions', 'Statement Assistant', 'PRO or Agency'] as $expected) {
            self::assertStringContainsString($expected, $trial);
        }
    }

    #[Test]
    public function copyDoesNotSellFreeFrontendScansAsPaidOnly(): void
    {
        $offenders = [];
        foreach ($this->englishSources() as $id => $source) {
            if (
                preg_match('/\b(?:frontend|remote)[- ]scan(?:s|ning)?\b[^.]*\b(?:available|only)\s+(?:in|with)\s+PRO\b/i', $source) === 1
                || preg_match('/\bPRO[- ]only\b|\bunlimited scanning\b/i', $source) === 1
            ) {
                $offenders[] = $id . ': ' . $source;
            }
        }

        self::assertSame([], $offenders);
        self::assertStringContainsString('Free Remote Preview', $this->englishSource('settings.remoteAccess.locked.text'));
        self::assertArrayNotHasKey('pageModuleIndicator.proHint.frontendScan', $this->englishSources());
    }

    #[Test]
    public function blockingModeIsDescribedAsTrialProAndAgency(): void
    {
        // PublishHook blocks for any valid licence, trial included.
        $labels = (string)file_get_contents(self::LANGUAGE_DIRECTORY . 'locallang_db.xlf');

        self::assertStringContainsString('Block — prevent publish (Trial, PRO or Agency)', $labels);
    }

    private function plan(string $method, string $plan): bool
    {
        $service = (new \ReflectionClass(ProCapabilityService::class))->newInstanceWithoutConstructor();

        return (bool)(new \ReflectionMethod(ProCapabilityService::class, $method))->invoke($service, $plan);
    }

    private function englishSource(string $id): string
    {
        $sources = $this->englishSources();
        self::assertArrayHasKey($id, $sources);

        return $sources[$id];
    }

    /**
     * @return array<string, string>
     */
    private function englishSources(): array
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load(self::LANGUAGE_DIRECTORY . 'locallang.xlf'));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        $sources = [];
        foreach ($xpath->query('//x:trans-unit') ?: [] as $unit) {
            if ($unit instanceof \DOMElement) {
                $sources[$unit->getAttribute('id')] = (string)$unit->getElementsByTagName('source')->item(0)?->textContent;
            }
        }

        return $sources;
    }
}
