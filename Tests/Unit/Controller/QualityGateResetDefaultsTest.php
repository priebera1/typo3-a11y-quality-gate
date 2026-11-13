<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;

/**
 * "Reset to defaults" in Settings › Quality Gate restores the gate a fresh installation has (D-01: warn on any open
 * critical finding), never "off". Server (SettingsController) and browser (settings-quality-gate.js) must agree.
 */
final class QualityGateResetDefaultsTest extends TestCase
{
    private const EXTENSION_ROOT = __DIR__ . '/../../..';

    #[Test]
    public function theShippedDefaultGateWarns(): void
    {
        self::assertSame(
            ['publish_mode' => 1, 'threshold_critical' => 0, 'threshold_warning' => -1],
            RulesetRepository::DEFAULT_GATE
        );
    }

    #[Test]
    public function theServerResetUsesTheShippedDefaultGate(): void
    {
        $source = (string)file_get_contents(self::EXTENSION_ROOT . '/Classes/Controller/SettingsController.php');
        $start = strpos($source, 'if ($resetToDefaults) {');
        self::assertNotFalse($start, 'reset branch not found');
        $branch = substr($source, $start, (int)strpos($source, '}', $start) - $start);

        self::assertStringContainsString('$globalData = RulesetRepository::DEFAULT_GATE;', $branch);
        self::assertStringNotContainsString("'publish_mode' => 0", $branch);
    }

    #[Test]
    public function theBrowserResetSelectsWarn(): void
    {
        $source = (string)file_get_contents(
            self::EXTENSION_ROOT . '/Resources/Public/JavaScript/backend/settings-quality-gate.js'
        );

        self::assertStringContainsString('input[name="qualityGate[global][publish_mode]"][value="1"]', $source);
        self::assertStringNotContainsString('input[name="qualityGate[global][publish_mode]"][value="0"]', $source);
    }
}
