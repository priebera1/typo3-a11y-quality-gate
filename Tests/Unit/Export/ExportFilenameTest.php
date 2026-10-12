<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\ExportFilename;

/**
 * One filename convention for every PDF and CSV export: `aqg-{content|frontend}-{overview|detail|acceptance}-{subject}-
 * {date}-{time}.{ext}`. Earlier names mixed prefixes (`a11y-`, `remote-`, `aqg-acceptance-`) and the internal ID of a
 * frontend page record.
 */
final class ExportFilenameTest extends TestCase
{
    private const AT = 1791533640; // 2026-10-09 08:14 UTC

    protected function setUp(): void
    {
        date_default_timezone_set('UTC');
    }

    #[Test]
    public function everyExportUsesSourceViewSubjectAndTimestamp(): void
    {
        self::assertSame('aqg-content-overview-main-2026-10-09-0814.pdf', ExportFilename::build('content', 'overview', 'main', self::AT, 'pdf'));
        self::assertSame('aqg-content-detail-738-2026-10-09-0814.csv', ExportFilename::build('content', 'detail', '738', self::AT, 'csv'));
        self::assertSame('aqg-frontend-overview-main-2026-10-09-0814.pdf', ExportFilename::build('frontend', 'overview', 'main', self::AT, 'pdf'));
        self::assertSame('aqg-frontend-detail-729-2026-10-09-0814.pdf', ExportFilename::build('frontend', 'detail', '729', self::AT, 'pdf'));
        self::assertSame('aqg-frontend-acceptance-main-2026-10-09-0814.csv', ExportFilename::build('frontend', 'acceptance', 'main', self::AT, 'csv'));
    }

    #[Test]
    public function componentsAreSafeForEveryFileSystem(): void
    {
        self::assertSame('aqg-content-overview-my-site-de-2026-10-09-0814.pdf', ExportFilename::build('content', 'overview', 'My Site/DE ', self::AT, 'pdf'));
        self::assertSame('aqg-frontend-detail-export-2026-10-09-0814.pdf', ExportFilename::build('frontend', 'detail', '../', self::AT, 'pdf'));
        self::assertSame('aqg-content-overview-export-2026-10-09-0814.csv', ExportFilename::build('Content"', 'Overview\\', '', self::AT, 'CSV'));
    }
}
