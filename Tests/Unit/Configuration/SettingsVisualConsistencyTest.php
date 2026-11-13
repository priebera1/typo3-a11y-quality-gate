<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Final 3.0 visual consistency pass: the remaining differences between Settings tabs stay closed.
 *
 * Regression guard:
 * - the AI suggestions and Statement tabs used 31px default buttons beside the 29px `btn-sm` saves of every other tab;
 * - the Statement form painted explicit Dark with slate literals (#111827 / #334155 / #cbd5e1) instead of the --aqi-*
 *   tokens of the other tabs, and its radios kept the browser/TYPO3 blue;
 * - the AI fixes empty states used their own dashed, left-aligned box instead of the shared .aqg-empty anatomy.
 */
final class SettingsVisualConsistencyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    #[Test]
    public function everySettingsActionButtonUsesTheSmallSize(): void
    {
        $offenders = [];
        foreach (glob(self::ROOT . 'Resources/Private/Partials/Settings/*.html') ?: [] as $file) {
            preg_match_all('/<(?:button|a)\b[^>]*\bclass="btn btn-(?:primary|default|danger|warning)\b[^"]*"/', (string)file_get_contents($file), $matches);
            foreach ($matches[0] as $tag) {
                if (!preg_match('/\bbtn-(?:sm|xs)\b/', $tag)) {
                    $offenders[] = basename($file) . ': ' . $tag;
                }
            }
        }

        self::assertSame([], $offenders, 'Settings buttons are btn-sm (29px), like the save bars and the Atlas.');
    }

    #[Test]
    public function statementStylesUseThemeTokensInsteadOfColourLiterals(): void
    {
        $scss = (string)file_get_contents(self::ROOT . 'Resources/Private/Scss/views/_settings.scss');
        $start = strpos($scss, '/* Accessibility Statement Draft Assistant */');
        $end = strpos($scss, '/* Statement Assistant final W3C-style sections */');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $statement = substr($scss, $start, $end - $start);

        // Literals only as the fallback inside var(--token, #fallback); never as the value itself.
        $withoutFallbacks = preg_replace('/var\(--[\w-]+,\s*#[0-9a-fA-F]{3,8}\)/', '', $statement);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', (string)$withoutFallbacks);
        self::assertStringContainsString('accent-color: var(--aqi-fg);', $statement);
    }

    #[Test]
    public function aiFixesEmptyStatesUseTheSharedEmptyState(): void
    {
        $js = (string)file_get_contents(self::ROOT . 'Resources/Public/JavaScript/remediation/remediation-queue.js');
        $template = (string)file_get_contents(self::ROOT . 'Resources/Private/Templates/Remediation/Index.html');

        self::assertStringContainsString("class: 'aqg-remediation__state aqg-empty'", $js);
        foreach (["emptyState('search'", "emptyState('check'", "emptyState('clock'"] as $call) {
            self::assertStringContainsString($call, $js);
        }
        self::assertStringContainsString('<section class="aqg-remediation__state aqg-empty"', $template);
    }
}
