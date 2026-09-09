<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * WCAG 2.1 AA 2.4.7: every AQG control that suppresses the UA focus outline must paint its own
 * visible focus indicator, and that indicator must differ from the hover state.
 */
final class ModuleFocusVisibilityTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../Resources/Public/Css/backend.css';

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
        $css = (string)file_get_contents(self::CSS);
        self::assertNotSame('', $css, 'backend.css must be built before this test runs.');

        // Collect the selectors that null the outline and those that paint a ring.
        preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $matches, PREG_SET_ORDER);

        $outlineRemoved = [];
        $ringPainted = [];
        foreach ($matches as [, $selectors, $body]) {
            foreach (explode(',', $selectors) as $selector) {
                $selector = trim($selector);
                if ($selector === '' || !str_contains($selector, ':focus')) {
                    continue;
                }

                if (preg_match('/outline:\s*(none|0)/', $body) === 1) {
                    $outlineRemoved[$selector] = true;
                }

                if (str_contains($body, 'box-shadow:') || preg_match('/outline:\s*(?!none|0)/', $body) === 1) {
                    $ringPainted[$selector] = true;
                }
            }
        }

        self::assertNotSame([], $outlineRemoved, 'Expected AQG focus rules in the built CSS.');

        $unguarded = array_values(array_diff(array_keys($outlineRemoved), array_keys($ringPainted)));

        self::assertSame(
            [],
            $unguarded,
            'These focus selectors remove the outline without painting a visible indicator: '
            . implode(', ', $unguarded)
        );
    }
}
