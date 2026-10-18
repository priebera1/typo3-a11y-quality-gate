<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\PdfGenerator;

/**
 * Renders real PDFs with mPDF and reads the file: what the reports can offer assistive technology without a
 * structure tree (language, displayed title, bookmark outline), that a template's own <style> block is applied
 * instead of printed (the acceptance evidence PDF showed its CSS as text on page one), and that mPDF's switch of the
 * multibyte encoding does not outlive the render.
 */
final class PdfGeneratorTest extends TestCase
{
    private const CSS = 'body { color: #1e232a; }';

    #[Test]
    public function aReportDeclaresItsLanguageTitleAndBookmarkOutline(): void
    {
        $pdf = (new PdfGenerator())->render(
            '<bookmark content="Findings" level="0" /><h2>Findings</h2><p>One finding.</p>',
            'Accessibility report',
            [],
            self::CSS,
            'de',
        );

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('/Lang (de)', $pdf);
        self::assertMatchesRegularExpression('/\/DisplayDocTitle\s+true/', $pdf);
        self::assertStringContainsString('/Outlines', $pdf);
        self::assertStringContainsString('/PageMode /UseOutlines', $pdf);
    }

    #[Test]
    public function anInvalidLanguageFallsBackToEnglish(): void
    {
        $pdf = (new PdfGenerator())->render('<p>Report</p>', 'Report', [], self::CSS, 'en) /Evil (x');

        self::assertStringContainsString('/Lang (en)', $pdf);
        self::assertStringNotContainsString('Evil', $pdf);
    }

    #[Test]
    public function aTemplateStyleBlockIsAppliedAndNotPrinted(): void
    {
        $pdf = (new PdfGenerator())->render(
            '<style>.aqge-note { color: #123456; }</style><p class="aqge-note">Styled note</p>',
            'Evidence',
            [],
            self::CSS,
        );
        $content = $this->contentStreams($pdf);

        self::assertStringNotContainsString('aqge-note', $content, 'The <style> block was printed as page text.');
        self::assertMatchesRegularExpression('/0\.071\d* 0\.204\d* 0\.337\d* rg/', $content, 'The template colour was not applied.');
    }

    #[Test]
    public function aLongUrlWrapsAt8PointWithoutWarnings(): void
    {
        $warnings = [];
        set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $pdf = (new PdfGenerator())->render(
                '<table style="width: 100%; overflow: wrap;"><tr><td style="font-size: 8pt;">Start URL · 30 Sep 2026</td>'
                . '<td style="font-family: courier; font-size: 8pt;">https://www.example.org/a-rather-long-path/with-several-segments/and-a-page-slug</td>'
                . '<td style="font-family: courier; font-size: 8pt;">af77c3b0-32b0-4078-977e-6d3b4945ac70</td></tr></table>',
                'Evidence',
                [],
                self::CSS,
            );
            trigger_error('after render', E_USER_WARNING);
        } finally {
            restore_error_handler();
        }

        preg_match_all('/\/F\d+ ([\d.]+) Tf/', $this->contentStreams($pdf), $sizes);
        self::assertNotSame([], $sizes[1]);
        self::assertGreaterThanOrEqual(7.99, min(array_map('floatval', $sizes[1])), 'mPDF shrank the table instead of wrapping.');
        self::assertSame(['after render'], $warnings, 'Only the known mPDF letter-wrap warning may be dropped, and the caller\'s handler must be back.');
    }

    #[Test]
    public function renderingRestoresTheMultibyteEncoding(): void
    {
        $encoding = mb_internal_encoding();

        (new PdfGenerator())->render('<p>Report</p>', 'Report', [], self::CSS);

        // mPDF leaves windows-1252 behind otherwise, and mb_strlen() then counts "Müller" as seven characters.
        self::assertSame($encoding, mb_internal_encoding());
        self::assertSame(6, mb_strlen('Müller'));
    }

    #[Test]
    public function theReportsAreStillUntagged(): void
    {
        $pdf = (new PdfGenerator())->render('<h1>Report</h1><table><tr><th>Rule</th></tr></table>', 'Report', [], self::CSS);

        // The export UI, the PDFs and the documentation say the reports are not tagged. If a generator ever writes a
        // structure tree, that wording and the CSV-as-accessible-copy guidance must be revisited together.
        self::assertStringNotContainsString('/StructTreeRoot', $pdf);
        self::assertDoesNotMatchRegularExpression('/\/MarkInfo\s*<<\s*\/Marked\s+true/', $pdf);
    }

    /**
     * Regression (2.0.2, AQG-202-03): the statement draft has a footer but no running header, yet every page kept the
     * report header's 28 mm top margin; with that waste a four-page statement spilled a few lines onto a fifth page.
     * A footer-only document starts below a 16 mm margin, a document with a running header still reserves 28 mm.
     */
    #[Test]
    public function onlyADocumentWithARunningHeaderReservesHeaderSpace(): void
    {
        $footer = '<htmlpagefooter name="f"><div>Footer</div></htmlpagefooter><sethtmlpagefooter name="f" value="on" />';
        $header = '<htmlpageheader name="h"><div>Header</div></htmlpageheader><sethtmlpageheader name="h" value="on" show-this-page="1" />';
        // Taller than a page below a 28 mm top margin, shorter than one below 16 mm (A4, 18 mm bottom margin).
        $body = '<div style="height: 255mm; margin: 0; padding: 0;"></div><p style="margin: 0;">End</p>';

        $footerOnly = (new PdfGenerator())->render($footer . $body, 'Statement', [], self::CSS);
        $withHeader = (new PdfGenerator())->render($header . $footer . $body, 'Report', [], self::CSS);

        self::assertSame(1, $this->pageCount($footerOnly));
        self::assertSame(2, $this->pageCount($withHeader));
    }

    private function pageCount(string $pdf): int
    {
        return preg_match_all('/\/Type\s*\/Page(?![a-zA-Z])/', $pdf);
    }

    private function contentStreams(string $pdf): string
    {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches);
        $content = '';
        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $content .= $inflated === false ? $stream : $inflated;
        }

        return $content;
    }

    /**
     * Regression (audit C4): core-font mode printed every character outside Windows-1252 as "?" — a Cyrillic snippet,
     * and Slovak, Czech, Polish or Hungarian letters. The reports embed a Unicode font now.
     */
    #[Test]
    public function textOutsideWindows1252UsesAnEmbeddedUnicodeFont(): void
    {
        $pdf = (new PdfGenerator())->render('<p>Predvolený text č ľ ť ň ł ő Данный</p>', 'Unicode', [], self::CSS);

        self::assertStringContainsString('/FontFile2', $pdf, 'The text font must be embedded.');
        self::assertStringContainsString('/ToUnicode', $pdf, 'Text must stay extractable and searchable.');
        self::assertStringNotContainsString('/BaseFont /Helvetica', $pdf);
        self::assertStringNotContainsString('/WinAnsiEncoding', $pdf);
    }

    /**
     * Frontend snippets quote the scanned page verbatim, including `lang="zh"` content. DejaVu has no CJK glyphs, so
     * those characters must come from mPDF's bundled substitution font instead of printing as empty boxes.
     */
    #[Test]
    public function charactersMissingFromTheReportFontUseTheBundledSubstitutionFont(): void
    {
        $pdf = (new PdfGenerator())->render('<p>Snippet <code>&lt;p lang="zh"&gt;中文内容&lt;/p&gt;</code></p>', 'CJK', [], self::CSS);

        self::assertMatchesRegularExpression('#/BaseFont /MPDF[A-Z]+\+Sun-ExtA#', $pdf);
    }
}
