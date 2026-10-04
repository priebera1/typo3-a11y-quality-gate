<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The exported PDF reports as measured in the generated files: labels, key/value rows and the running header and
 * footer rendered at 5-7.5 pt. mPDF also shrinks a whole table when one word (a URL, a job ID, the page counter)
 * does not fit its column, which pushed 8 pt text back to 6.9 pt. The reports are untagged (mPDF writes no
 * structure tree), so each one says so and points to the CSV export.
 */
final class PdfReadabilityTest extends TestCase
{
    private const MINIMUM_PT = 8.0;
    private const PDF_CSS = __DIR__ . '/../../../Resources/Public/Css/Pdf/';
    private const PDF_TEMPLATES = __DIR__ . '/../../../Resources/Private/Templates/Export/';
    private const REPORTS = [
        'LocalOverviewPdf.html',
        'LocalPagePdf.html',
        'RemoteOverviewPdf.html',
        'RemotePagePdf.html',
        'AcceptanceEvidencePdf.html',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function styleSourceProvider(): iterable
    {
        foreach (['base.css', 'local.css', 'statement.css'] as $file) {
            yield $file => [self::PDF_CSS . $file];
        }
        foreach (glob(self::PDF_TEMPLATES . '*Pdf.html') ?: [] as $template) {
            yield basename($template) => [$template];
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reportProvider(): iterable
    {
        foreach (self::REPORTS as $report) {
            yield $report => [$report];
        }
    }

    #[Test]
    #[DataProvider('styleSourceProvider')]
    public function noTextIsSetBelowEightPoints(string $path): void
    {
        preg_match_all('/font-size:\s*([\d.]+)\s*(pt|px|em|rem|%)?/i', (string)file_get_contents($path), $matches, PREG_SET_ORDER);

        $tooSmall = [];
        foreach ($matches as $match) {
            $value = (float)$match[1];
            $unit = strtolower($match[2] ?? '');
            if ($value === 0.0) {
                continue; // whitespace collapse between inline-block cells, no text
            }
            self::assertContains($unit, ['pt', 'px'], basename($path) . ': relative font sizes cannot be checked against the floor.');
            $points = $unit === 'px' ? $value * 0.75 : $value;
            if ($points < self::MINIMUM_PT) {
                $tooSmall[] = $match[0];
            }
        }

        self::assertSame([], $tooSmall, basename($path) . ': text below 8 pt.');
    }

    #[Test]
    public function tablesWithUrlsWrapLongWordsInsteadOfShrinking(): void
    {
        $base = (string)file_get_contents(self::PDF_CSS . 'base.css');
        $local = (string)file_get_contents(self::PDF_CSS . 'local.css');
        $acceptance = (string)file_get_contents(self::PDF_TEMPLATES . 'AcceptanceEvidencePdf.html');

        self::assertMatchesRegularExpression('/\.aqgp-runhead,\s*\.aqgp-runfoot \{[^}]*overflow: wrap;/', $base, 'Running header and footer');
        self::assertMatchesRegularExpression('/\.aqgp-meta \{[^}]*overflow: wrap;/', $local, 'Local report metadata (site, path)');
        self::assertMatchesRegularExpression('/\.aqge-table \{[^}]*overflow: wrap;/', $acceptance, 'Acceptance evidence start URLs and job IDs');
    }

    #[Test]
    public function frontendReportsHaveADescriptiveDocumentTitle(): void
    {
        // The viewer shows the document title instead of the file name (/DisplayDocTitle), so "AQG" alone says less
        // than the file name did.
        $builder = (string)file_get_contents(__DIR__ . '/../../../Classes/Export/RemoteExportBuilder.php');

        self::assertStringNotContainsString("title: 'AQG',", $builder);
        self::assertSame(2, substr_count($builder, "title: 'AQG Frontend Overview Report',"));
        self::assertSame(2, substr_count($builder, "title: 'AQG Frontend Page Detail Report',"));
    }

    #[Test]
    #[DataProvider('reportProvider')]
    public function everyReportDisclosesThatItIsUntaggedAndHasAnOutline(string $report): void
    {
        $template = (string)file_get_contents(self::PDF_TEMPLATES . $report);

        self::assertStringContainsString('not tagged for screen readers', $template);
        self::assertStringContainsString('CSV export', $template, 'The note must name the accessible alternative.');
        self::assertMatchesRegularExpression('/<bookmark content="[^"]+" level="0" \/>/', $template, 'Sections need bookmarks for the PDF outline.');
    }
}
