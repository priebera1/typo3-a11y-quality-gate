<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;

/**
 * N-16 (final customer acceptance): the frontend overview PDF said "43 occurrences on 5 of 5 checked pages" for a
 * 39-page site. It now carries the same page-tree coverage the Overview shows (C-01), in English like every PDF.
 */
final class RemoteOverviewCoverageNoteTest extends TestCase
{
    #[Test]
    public function aPartialScanSaysHowMuchOfThePageTreeWasChecked(): void
    {
        $note = RemoteExportBuilder::coverageNote(['state' => 'partial', 'inventory' => 39, 'checked' => 5, 'failed' => 0, 'notReached' => 34]);
        self::assertSame('Partial coverage: 5 of 39 public pages of the page tree were checked; 34 were not reached. Pages that were not checked can still have issues.', $note);
        self::assertStringContainsString('2 could not be loaded', RemoteExportBuilder::coverageNote(['state' => 'partial', 'inventory' => 10, 'checked' => 7, 'failed' => 2, 'notReached' => 1]));
    }

    #[Test]
    public function completeAndUnknownCoverageAreStatedHonestly(): void
    {
        self::assertSame('Complete coverage: all 39 public pages of the page tree were checked.', RemoteExportBuilder::coverageNote(['state' => 'complete', 'inventory' => 39, 'checked' => 39, 'failed' => 0, 'notReached' => 0]));
        self::assertSame('Coverage unknown: this scan did not record which TYPO3 pages it checked.', RemoteExportBuilder::coverageNote(['state' => 'unknown', 'inventory' => 39, 'checked' => 5, 'failed' => 0, 'notReached' => 0]));
        self::assertSame('', RemoteExportBuilder::coverageNote(null), 'Page scans and restricted reports carry no tree coverage.');
    }

    #[Test]
    public function theOverviewPdfPrintsTheNoteUnderTheVerdict(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Templates/Export/RemoteOverviewPdf.html');
        self::assertMatchesRegularExpression('/aqr-verdict.*\R.*\{coverageNote\}/', $template);
    }
}
