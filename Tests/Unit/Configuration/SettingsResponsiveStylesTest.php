<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Settings navigation (owner UX pass 3.0.0): one row of tabs with intact labels at every width. When the TYPO3
 * content area cannot hold them, the row scrolls horizontally (settings-tabs.js keeps the active tab in view and
 * fades the clipped edge); it never wraps into two lines and never widens the document.
 */
final class SettingsResponsiveStylesTest extends TestCase
{
    #[Test]
    public function settingsTabsNeverWrapAndKeepTheirLabelsIntact(): void
    {
        $scss = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Scss/views/_settings.scss');

        self::assertStringContainsString('.aqg-narrow .a11y-settings.aqg-module .aqg-tabs,', $scss);
        self::assertStringContainsString('.a11y-settings.aqg-module.aqg-narrow .aqg-tabs', $scss);
        self::assertStringNotContainsString('text-wrap: balance;', $scss);
        self::assertDoesNotMatchRegularExpression('/\.aqg-tab\s*\{[^}]*white-space:\s*normal;/', $scss, 'Tab labels never break.');
        self::assertDoesNotMatchRegularExpression('/\.aqg-tabs\s*\{[^}]*flex-wrap:\s*wrap;/', $scss, 'The tab row never wraps.');
    }

    #[Test]
    public function compiledSettingsCssScrollsTheTabRowWithVisibleFocus(): void
    {
        $css = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Public/Css/backend.css');

        self::assertMatchesRegularExpression('/\.a11y-settings\.aqg-module \.aqg-tabs\{[^}]*overflow-x:auto;[^}]*overflow-y:hidden/', $css);
        self::assertMatchesRegularExpression('/\.a11y-settings\.aqg-module \.aqg-tab\{[^}]*flex:none;[^}]*white-space:nowrap/', $css);
        self::assertMatchesRegularExpression('/\.aqg-tabs\[data-aqg-overflow-end=true\]\{[^}]*mask-image/', $css);
        // The scrolling row clips outer rings, so the tab focus ring is drawn inside the tab.
        self::assertMatchesRegularExpression('/\.a11y-settings\.aqg-module \.aqg-tab:focus-visible\{[^}]*box-shadow:var\(--aqi-focus-ring-inset/', $css);
        self::assertStringNotContainsString('.a11y-settings.aqg-module .aqg-tabs { overflow-x: hidden;', (string)preg_replace('/\s+/', ' ', $css));
    }

    #[Test]
    public function settingsLoadTheTabScrollModule(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/Classes/Controller/SettingsController.php');
        self::assertStringContainsString("'@priebera/a11y-quality-gate/backend/settings-tabs.js'", $controller);
        self::assertFileExists(dirname(__DIR__, 3) . '/Resources/Public/JavaScript/backend/settings-tabs.js');
    }
}
