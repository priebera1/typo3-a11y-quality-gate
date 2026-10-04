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
}
