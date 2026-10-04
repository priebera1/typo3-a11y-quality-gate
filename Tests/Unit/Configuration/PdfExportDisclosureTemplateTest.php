<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * mPDF writes untagged PDFs, so every PDF export control tells the user before the download and names the
 * accessible alternative: the CSV export, or the HTML version of the accessibility statement. The note is the PDF
 * control's accessible description, so a screen reader user hears it on the control itself.
 */
final class PdfExportDisclosureTemplateTest extends TestCase
{
    private const PRIVATE = __DIR__ . '/../../../Resources/Private/';

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function pdfControlProvider(): iterable
    {
        yield 'local page detail' => ['Templates/PageDetail/Show.html', 'href="{exportPdfUrl}"', 'export.pdf.untaggedNote'];
        yield 'remote page detail' => ['Templates/RemotePageDetail/Show.html', 'href="{exportPdfUrl}"', 'export.pdf.untaggedNote'];
        yield 'acceptance evidence' => ['Partials/Remote/ScanComparison.html', 'href="{comparison.acceptanceEvidence.pdfUrl}"', 'remote.acceptance.pdfUntaggedNote'];
        yield 'statement assistant' => ['Partials/Settings/TabStatement.html', 'js-aqg-statement-pdf', 'settings.statement.pdfUntaggedNote'];
    }

    #[Test]
    #[DataProvider('pdfControlProvider')]
    public function thePdfControlIsDescribedByTheUntaggedNote(string $template, string $controlMarker, string $labelKey): void
    {
        $html = (string)file_get_contents(self::PRIVATE . $template);

        self::assertMatchesRegularExpression(
            '/<(?:a|button)\b[^>]*' . preg_quote($controlMarker, '/') . '[^>]*aria-describedby="([^"]+)"/',
            $html,
            $template . ': the PDF control needs the untagged note as its description.'
        );
        preg_match('/<(?:a|button)\b[^>]*' . preg_quote($controlMarker, '/') . '[^>]*aria-describedby="([^"]+)"/', $html, $match);

        self::assertMatchesRegularExpression(
            '/id="' . preg_quote($match[1], '/') . '"[^>]*>\s*<f:translate key="[^"]*' . preg_quote($labelKey, '/') . '"/',
            $html,
            $template . ': the described-by element must carry the untagged note.'
        );
    }

    #[Test]
    public function theExportMenuShowsTheNoteWithAUniqueIdPerMenu(): void
    {
        $partial = (string)file_get_contents(self::PRIVATE . 'Partials/Shared/ExportButtons.html');

        self::assertMatchesRegularExpression('/<a href="\{pdfUrl\}"[^>]*aria-describedby="\{pdfNoteId\}"/', $partial);
        self::assertMatchesRegularExpression('/id="\{pdfNoteId\}">\s*<f:translate\s+key="[^"]*export\.pdf\.untaggedNote"/', $partial);

        $ids = [];
        foreach (['LocalPanel', 'RemoteActions', 'RemotePanel'] as $caller) {
            $html = (string)file_get_contents(self::PRIVATE . 'Partials/Overview/' . $caller . '.html');
            self::assertMatchesRegularExpression("/partial=\"Shared\\/ExportButtons\"[^\\/]*pdfNoteId: '([a-z0-9-]+)'/s", $html, $caller);
            preg_match("/partial=\"Shared\\/ExportButtons\"[^\\/]*pdfNoteId: '([a-z0-9-]+)'/s", $html, $match);
            $ids[] = $match[1];
        }

        self::assertSame($ids, array_values(array_unique($ids)), 'Each export menu needs its own note id.');
    }

    #[Test]
    public function theStatementNoteRecommendsPublishingTheHtmlVersion(): void
    {
        $labels = (string)file_get_contents(self::PRIVATE . 'Language/locallang.xlf');

        self::assertMatchesRegularExpression(
            '/<trans-unit id="settings\.statement\.pdfUntaggedNote">\s*<source>[^<]*not tagged for screen readers[^<]*as HTML[^<]*<\/source>/',
            $labels
        );
    }
}
