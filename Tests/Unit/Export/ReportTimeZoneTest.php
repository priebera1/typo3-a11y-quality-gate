<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use ReflectionClass;
use ReflectionMethod;

/**
 * Report dates use the installation's time zone (TYPO3 sets PHP's default from `phpTimeZone`), as the backend does.
 * The frontend scan PDFs used a fixed Europe/Bratislava instead.
 */
final class ReportTimeZoneTest extends TestCase
{
    private string $previousTimeZone = 'UTC';

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousTimeZone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimeZone);
        parent::tearDown();
    }

    #[Test]
    public function frontendScanReportDatesFollowTheInstallationTimeZone(): void
    {
        $builder = (new ReflectionClass(RemoteExportBuilder::class))->newInstanceWithoutConstructor();
        $format = new ReflectionMethod(RemoteExportBuilder::class, 'formatPdfDate');

        // 2026-07-01 12:00 UTC
        date_default_timezone_set('America/New_York');
        self::assertSame('01 Jul 2026 · 08:00 EDT', $format->invoke($builder, 1_782_907_200));

        date_default_timezone_set('UTC');
        self::assertSame('01 Jul 2026 · 12:00 UTC', $format->invoke($builder, 1_782_907_200));
    }

    #[Test]
    public function noReportOrScanDateIsFormattedInAFixedTimeZone(): void
    {
        $root = __DIR__ . '/../../../Classes/';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            self::assertDoesNotMatchRegularExpression(
                '#new \\\\?DateTimeZone\(\s*[\'"](?!UTC[\'"])[A-Za-z]+/#',
                (string)file_get_contents($file->getPathname()),
                $file->getPathname() . ' formats a date in a fixed regional time zone'
            );
        }
    }
}
