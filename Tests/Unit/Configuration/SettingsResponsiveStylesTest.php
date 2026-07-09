<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SettingsResponsiveStylesTest extends TestCase
{
    #[Test]
    public function settingsTabsWrapForExplicitNarrowModuleState(): void
    {
        $scss = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Private/Scss/views/_settings.scss',
        );
        self::assertIsString($scss);

        self::assertStringContainsString(
            '.aqg-narrow .a11y-settings.aqg-module .aqg-tabs,',
            $scss,
        );
        self::assertStringContainsString(
            '.a11y-settings.aqg-module.aqg-narrow .aqg-tabs',
            $scss,
        );
        self::assertStringContainsString('flex-wrap: wrap;', $scss);
        self::assertStringContainsString('row-gap: 2px;', $scss);
        self::assertStringContainsString('min-width: 0;', $scss);
        self::assertStringContainsString('white-space: normal;', $scss);
        self::assertStringContainsString('overflow-wrap: anywhere;', $scss);
    }

    #[Test]
    public function compiledSettingsCssContainsMobileWrapFallbackWithoutOverflowMasking(): void
    {
        $css = file_get_contents(
            dirname(__DIR__, 3) . '/Resources/Public/Css/backend.css',
        );
        self::assertIsString($css);

        $normalizedCss = preg_replace('/\s+/', ' ', $css) ?? $css;

        self::assertMatchesRegularExpression('/@media\s*\(max-width:\s*575\.98px\)/', $css);
        self::assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*575\.98px\)\s*\{(?=[\s\S]*?\.a11y-settings\.aqg-module\s+\.aqg-tabs\s*\{[^}]*flex-wrap:\s*wrap;)(?=[\s\S]*?\.a11y-settings\.aqg-module\s+\.aqg-tab\s*\{[^}]*white-space:\s*normal;[^}]*overflow-wrap:\s*anywhere;?)/',
            $css,
        );
        self::assertStringNotContainsString(
            '.a11y-settings.aqg-module .aqg-tabs { overflow-x: hidden;',
            $normalizedCss,
        );
    }
}
