<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * WCAG 2.1 AA 2.4.7 / 1.4.11: every AQG control that suppresses the UA focus outline must paint its own
 * visible focus indicator, and that indicator must differ from the hover state.
 *
 * AQG paints focus through one shared ring (--aqi-focus-ring / --aqi-focus-ring-inset) built from the literal
 * --aqi-focus token, whose contrast ModuleTextContrastTest measures. Translucent glows such as
 * `color-mix(in oklch, var(--aqi-focus) 25%, transparent)` measured ~1.4:1 and vanished in dark mode.
 */
final class ModuleFocusVisibilityTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../Resources/Public/Css/backend.css';

    /** A painted indicator: the shared verified ring, or an outline in the focus / system colour. */
    private const INDICATOR = '/box-shadow:\s*var\(--aqi-focus-ring|outline:\s*[^;}]*(var\(--aqi-focus\b|CanvasText|Highlight)/';

    #[Test]
    public function sharedButtonFocusUsesTheVerifiedRing(): void
    {
        $css = (string)file_get_contents(self::CSS);

        self::assertMatchesRegularExpression(
            '/(^|[},])\.aqg-module \.btn:focus-visible[^{]*\{[^}]*box-shadow:\s*var\(--aqi-focus-ring\)/',
            $css,
            'The shared AQG button family (links and buttons alike) must paint the verified focus ring.'
        );
    }

    #[Test]
    public function translucentGlowsAreNotUsedAsFocusIndicators(): void
    {
        $offenders = [];
        foreach ($this->focusRules() as [$selector, $body]) {
            if (preg_match('/(box-shadow|outline):[^;}]*color-mix\([^;}]*transparent/', $body) === 1) {
                $offenders[] = $selector;
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offenders)),
            'These focus states paint a translucent glow instead of var(--aqi-focus-ring): ' . implode(', ', $offenders)
        );
    }

    #[Test]
    public function forcedColorsModeRestoresAVisibleFocusOutline(): void
    {
        $css = (string)file_get_contents(self::CSS);

        // Forced-colors mode drops box-shadow, so the shared ring alone would leave no indicator at all.
        self::assertMatchesRegularExpression(
            '/@media\s*\(forced-colors:\s*active\)\s*\{[^@]*?\.aqg-module :focus-visible\{[^}]*outline:[^;}]*CanvasText/',
            $css
        );
    }

    #[Test]
    public function ruleActionKeyboardFocusIsNotIdenticalToHover(): void
    {
        $css = (string)file_get_contents(self::CSS);

        self::assertMatchesRegularExpression(
            '/\.a11y-page-detail \.aqg-rule-action:focus-visible\{[^}]*box-shadow:[^}]*\}/',
            $css,
            'The rule-action control removes its outline, so :focus-visible must add a visible ring.'
        );
    }

    #[Test]
    public function everyFocusRuleThatRemovesTheOutlineRestoresAnIndicator(): void
    {
        $outlineRemoved = [];
        $ringPainted = [];
        foreach ($this->focusRules() as [$selector, $body]) {
            if (preg_match('/outline:\s*(none|0)\b/', $body) === 1) {
                $outlineRemoved[$selector] = true;
            }

            // `box-shadow: none` or a translucent glow is no indicator; only the verified ring or an outline is.
            if (preg_match(self::INDICATOR, $body) === 1) {
                $ringPainted[$selector] = true;
            }
        }

        self::assertNotSame([], $outlineRemoved, 'Expected AQG focus rules in the built CSS.');

        $unguarded = array_values(array_diff(array_keys($outlineRemoved), array_keys($ringPainted)));

        self::assertSame(
            [],
            $unguarded,
            'These focus selectors remove the outline without painting the verified focus indicator: '
            . implode(', ', $unguarded)
        );
    }

    /**
     * Every focus-state selector of the built stylesheet with its declaration block. Conditional group rules are
     * flattened first; otherwise the first rule inside each @media block would be read as the at-rule's body.
     *
     * @return list<array{string, string}>
     */
    private function focusRules(): array
    {
        $css = (string)file_get_contents(self::CSS);
        self::assertNotSame('', $css, 'backend.css must be built before this test runs.');

        $css = (string)preg_replace('/@(media|supports|container)[^{]*\{/', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $rules = [];
        foreach ($matches as [, $selectors, $body]) {
            foreach (explode(',', $selectors) as $selector) {
                $selector = trim($selector);
                if ($selector !== '' && str_contains($selector, ':focus')) {
                    $rules[] = [$selector, $body];
                }
            }
        }

        return $rules;
    }
}
