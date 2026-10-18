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
        foreach (['report.css', 'statement.css'] as $file) {
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
        // One print system for every report (1.9.9): its tables and running header/footer wrap long words.
        $report = (string)file_get_contents(self::PDF_CSS . 'report.css');

        self::assertMatchesRegularExpression('/\.aqr-runhead,\s*\.aqr-runfoot \{[^}]*overflow: wrap;/', $report, 'Running header and footer');
        self::assertMatchesRegularExpression('/\.aqr-table \{[^}]*overflow: wrap;/', $report, 'Report tables (URLs, job IDs, snippets)');
        // Sections flow (audit F1): only the Content site report starts its reference tables on a new page.
        foreach (self::REPORTS as $report) {
            self::assertSame(
                $report === 'LocalOverviewPdf.html' ? 1 : 0,
                substr_count((string)file_get_contents(self::PDF_TEMPLATES . $report), '<pagebreak'),
                $report . ': forced page breaks'
            );
        }
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

    /**
     * Regression: occurrence 1.1 sat in a table nested inside the rule's second column, 1.2+ in a separate table with
     * a margin, so mPDF laid the columns out twice and the rows did not line up. Every occurrence row now renders the
     * same fixed-width cells as a direct row of a rule-level table.
     */
    #[Test]
    #[DataProvider('occurrenceGridProvider')]
    public function occurrenceRowsOfARuleShareOneColumnGrid(string $report, string $section): void
    {
        $template = (string)file_get_contents(self::PDF_TEMPLATES . $report);

        self::assertDoesNotMatchRegularExpression('/<td>\s*<table class="aqr-table aqr-occ/', $template, 'No occurrence table nested in a rule cell.');
        self::assertStringNotContainsString('aqr-occ--more', $template, 'No indented continuation table.');
        preg_match('/<f:section name="' . $section . '">(.*?)<\/f:section>/s', $template, $row);
        self::assertNotEmpty($row, 'The occurrence row section exists.');
        self::assertStringContainsString('<td class="aqr-grid__no" style="width:8mm"></td>', $row[1], 'Every row starts with the rule-number spacer.');
        self::assertMatchesRegularExpression('/<td class="aqr-grid__idx" style="width:11mm">/', $row[1]);
    }

    public static function occurrenceGridProvider(): iterable
    {
        yield 'Content page report' => ['LocalPagePdf.html', 'Occurrence'];
        yield 'Frontend page report' => ['RemotePagePdf.html', 'Node'];
    }

    /**
     * Regression (2.0.2, AQG-202-03): the statement PDF ended a page with a definition label ("Responsible unit")
     * whose value started the next page, and spilled its closing notes onto an almost empty last page. A heading or
     * label keeps at least one following line on its page; short units are not split. Whole sections are not kept
     * together: mPDF would move them and leave large gaps.
     */
    #[Test]
    public function theStatementKeepsHeadingsAndLabelsWithTheirContent(): void
    {
        $css = preg_replace('/\s+/', ' ', (string)file_get_contents(self::PDF_CSS . 'statement.css'));

        self::assertMatchesRegularExpression('/\.aqg-statement-pdf h2, \.aqg-statement-pdf dt \{ page-break-after: avoid; \}/', $css);
        self::assertMatchesRegularExpression('/\.aqg-statement-pdf li, \.aqg-statement-meta, \.aqg-warning-banner, \.aqg-statement-contact-warning \{ page-break-inside: avoid; \}/', $css);
        self::assertDoesNotMatchRegularExpression('/\.aqg-statement-section[^{]*\{[^}]*page-break-inside/', $css);
    }
}
