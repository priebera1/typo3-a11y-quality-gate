<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Utility\ScanUrlUtility;

/**
 * Comparable-URL normalisation for remote scan identity.
 *
 * Regression guard: normalising with `strtolower()` over the whole URL made `/Foo` and `/foo`
 * compare equal, so a scan selected by an explicit `remoteScanUid` could be attributed to a
 * different page whose slug differed only in case.
 */
final class ScanUrlUtilityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function normalisationProvider(): iterable
    {
        yield 'scheme is lowercased' => ['HTTPS://example.test/foo', 'https://example.test/foo'];
        yield 'host is lowercased' => ['https://Example.TEST/foo', 'https://example.test/foo'];
        yield 'trailing slash is dropped' => ['https://example.test/foo/', 'https://example.test/foo'];
        yield 'path case is preserved' => ['https://example.test/Foo', 'https://example.test/Foo'];
        yield 'query case is preserved' => ['https://example.test/a?Tx=Ab', 'https://example.test/a?Tx=Ab'];
        yield 'port is kept' => ['https://example.test:8443/foo', 'https://example.test:8443/foo'];
        yield 'empty stays empty' => ['', ''];
        yield 'whitespace is trimmed' => ['  https://example.test/foo  ', 'https://example.test/foo'];
    }

    #[Test]
    #[DataProvider('normalisationProvider')]
    public function onlyCaseInsensitiveComponentsAreLowercased(string $input, string $expected): void
    {
        self::assertSame($expected, ScanUrlUtility::comparable($input));
    }

    #[Test]
    public function pathsDifferingOnlyInCaseNeverCompareEqual(): void
    {
        self::assertNotSame(
            ScanUrlUtility::comparable('https://example.test/Foo'),
            ScanUrlUtility::comparable('https://example.test/foo'),
            'Case-different paths are different resources and must never be merged.'
        );
    }

    #[Test]
    public function theSameResourceSpelledDifferentlyStillCompareEqual(): void
    {
        self::assertSame(
            ScanUrlUtility::comparable('HTTPS://Example.TEST/foo/'),
            ScanUrlUtility::comparable('https://example.test/foo'),
        );
    }
}
