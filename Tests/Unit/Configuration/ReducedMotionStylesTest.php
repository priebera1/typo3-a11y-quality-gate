<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every element AQG animates without end (scan spinners, indeterminate progress bars, status pulses) is named in
 * a `prefers-reduced-motion: reduce` rule of the same stylesheet, so a visitor who asked for less motion gets the
 * slower spinner or the static bar instead of the running animation.
 */
final class ReducedMotionStylesTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../Resources/Public/Css/';

    /**
     * @return iterable<string, array{string}>
     */
    public static function stylesheetProvider(): iterable
    {
        foreach (['backend.css', 'toolbar.css', 'page-module-indicator.css', 'ckeditor.css'] as $file) {
            yield $file => [$file];
        }
    }

    #[Test]
    #[DataProvider('stylesheetProvider')]
    public function everyEndlessAnimationHasAReducedMotionRule(string $file): void
    {
        $css = (string)preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents(self::CSS . $file));
        self::assertNotSame('', $css, $file . ' must be built before this test runs.');

        $reducedMotion = '';
        if (preg_match_all('/@media\s*\(\s*prefers-reduced-motion:\s*reduce\s*\)\s*\{/', $css, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$match, $offset]) {
                $reducedMotion .= $this->blockAt($css, $offset + strlen($match) - 1);
            }
        }

        $animated = [];
        preg_match_all('/([^{}]+)\{[^{}]*animation:[^;{}]*infinite/', $css, $rules);
        foreach ($rules[1] as $selectorList) {
            foreach (explode(',', $selectorList) as $selector) {
                // The animated element: the last class of the selector.
                if (preg_match_all('/\.([\w-]+)/', $selector, $classes) > 0) {
                    $animated[] = end($classes[1]);
                }
            }
        }
        $animated = array_values(array_unique($animated));

        self::assertNotSame([], $animated, $file . ' is expected to animate a spinner or progress bar.');
        $missing = array_values(array_filter(
            $animated,
            static fn (string $class): bool => !str_contains($reducedMotion, '.' . $class)
        ));
        self::assertSame([], $missing, $file . ': endless animations without a prefers-reduced-motion rule.');
    }

    private function blockAt(string $css, int $open): string
    {
        $depth = 0;
        for ($i = $open, $length = strlen($css); $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return substr($css, $open, $i - $open + 1);
            }
        }

        self::fail('Unbalanced braces in a stylesheet.');
    }
}
