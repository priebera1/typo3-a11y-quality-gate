<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;

/**
 * A page that did not load was never checked: its Frontend page PDF must not read like a clean result.
 */
final class RemotePageVerdictTest extends TestCase
{
    #[Test]
    public function aCheckedPageCountsIssueTypesAndOccurrences(): void
    {
        self::assertSame('14 issue types · 41 occurrences', RemoteExportBuilder::pageVerdict(14, 41, false));
        self::assertSame('1 issue type · 1 occurrence', RemoteExportBuilder::pageVerdict(1, 1, false));
        self::assertSame('No automated findings', RemoteExportBuilder::pageVerdict(0, 0, false));
    }

    #[Test]
    public function aFailedPageWithoutFindingsIsReportedAsNotChecked(): void
    {
        self::assertSame('Page not checked: it did not load during the scan', RemoteExportBuilder::pageVerdict(0, 0, true));
        // Findings stored before a later failure still count.
        self::assertSame('2 issue types · 3 occurrences', RemoteExportBuilder::pageVerdict(2, 3, true));
    }
}
