<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression guard: a generated toolbar template once carried keys like "locallang.xlfoolbar.title" (a shell expanded
 * "$L:t…"). They rendered their English defaults, so the German backend silently stayed English. Every translate key
 * of the toolbar templates must be a full LLL path to an existing unit.
 */
final class ToolbarTemplateTranslationKeysTest extends TestCase
{
    #[Test]
    public function everyToolbarTranslationKeyIsAFullPathToAnExistingUnit(): void
    {
        $root = dirname(__DIR__, 3);
        $units = [];
        preg_match_all('/<trans-unit id="([^"]+)"/', (string)file_get_contents($root . '/Resources/Private/Language/locallang.xlf'), $matches);
        $units = array_flip($matches[1]);
        $files = array_merge(glob($root . '/Resources/Private/Templates/ToolbarItems/*.html') ?: [], glob($root . '/Resources/Private/Partials/ToolbarItems/*.html') ?: []);
        self::assertNotSame([], $files);
        foreach ($files as $file) {
            preg_match_all('/key(?:="|:\s*\')([^"\']+)["\']/', (string)file_get_contents($file), $keys);
            foreach ($keys[1] as $key) {
                self::assertStringStartsWith('LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:', $key, basename($file));
                self::assertArrayHasKey(substr($key, strlen('LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:')), $units, basename($file) . ': ' . $key);
            }
        }
    }
}
