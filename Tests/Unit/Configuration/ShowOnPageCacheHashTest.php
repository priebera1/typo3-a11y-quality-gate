<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FUN-01 (2026-10 Sol audit): "Show on page" for an occurrence without a record marker opened
 * `…?aqgDebug=1&aqgs=<selector>`, and TYPO3 answered 404 "Request parameters could not be validated (&cHash empty)":
 * `aqgs` was not among the parameters AQG keeps out of cHash validation. Every query parameter the "Show on page"
 * builder sets must be excluded there and removed again before a URL is stored or shown.
 */
final class ShowOnPageCacheHashTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    #[Test]
    public function everyShowOnPageParameterIsExcludedFromCacheHashValidation(): void
    {
        $builder = (string)file_get_contents(self::ROOT . '/Resources/Public/JavaScript/backend/pro/pro-module.js');
        preg_match('/handleHighlightNode\(button\) \{(.*?)\n    \}/s', $builder, $method);
        self::assertNotEmpty($method, 'The "Show on page" builder exists.');
        preg_match_all("/searchParams\\.set\\('([A-Za-z0-9_]+)'/", $method[1], $parameters);
        self::assertContains('aqgs', $parameters[1]);

        $localconf = (string)file_get_contents(self::ROOT . '/ext_localconf.php');
        preg_match("/foreach \\(\\[(.*?)\\] as \\\$parameterName\\)/s", $localconf, $exclusions);
        $sanitizer = (string)file_get_contents(self::ROOT . '/Classes/Rendered/RenderedFrontendUrlSanitizer.php');

        foreach (array_unique($parameters[1]) as $parameter) {
            self::assertStringContainsString("'" . $parameter . "'", $exclusions[1] ?? '', $parameter . ' is excluded from cHash validation.');
            self::assertStringContainsString("'" . $parameter . "'", $sanitizer, $parameter . ' is removed before a URL is stored.');
        }
    }
}
