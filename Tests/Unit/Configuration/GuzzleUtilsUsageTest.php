<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TYPO3 accepts Guzzle 8, whose `GuzzleHttp\Utils` has no `jsonEncode()` or `jsonDecode()`.
 * AQG does not require Guzzle itself, so extension code uses PHP's native JSON functions.
 */
final class GuzzleUtilsUsageTest extends TestCase
{
    #[Test]
    public function extensionClassesDoNotReferenceGuzzleUtils(): void
    {
        $classesDirectory = __DIR__ . '/../../../Classes';
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($classesDirectory, \FilesystemIterator::SKIP_DOTS)
        );

        $offenders = [];
        foreach ($files as $file) {
            if ($file->getExtension() === 'php'
                && str_contains((string)file_get_contents($file->getPathname()), 'GuzzleHttp\\Utils')) {
                $offenders[] = substr($file->getPathname(), strlen($classesDirectory) + 1);
            }
        }
        sort($offenders);

        self::assertSame([], $offenders, 'Use json_encode()/json_decode() instead of GuzzleHttp\\Utils.');
    }
}
