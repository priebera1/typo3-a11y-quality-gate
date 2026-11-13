<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\PdfGenerator;
use Priebera\A11yQualityGate\ViewHelpers\PdfExportAvailableViewHelper;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * PDF export is Composer-only (I-03, option B): the TER package for Classic mode does not include mPDF. Every PDF
 * control is rendered only where mPDF loads; elsewhere the view says that PDF needs a Composer installation and keeps
 * the CSV / HTML / text alternatives, instead of a PDF link that can only download a notice.
 */
final class PdfComposerOnlyTemplateTest extends TestCase
{
    private const PRIVATE = __DIR__ . '/../../../Resources/Private/';
    private const NAMESPACE = 'xmlns:aqg="http://typo3.org/ns/Priebera/A11yQualityGate/ViewHelpers"';

    /**
     * @return iterable<string, array{string, string, string|null}>
     */
    public static function pdfControlProvider(): iterable
    {
        yield 'export menu' => ['Partials/Shared/ExportButtons.html', '<a href="{pdfUrl}"', 'export.pdf.composerOnly'];
        yield 'acceptance evidence' => ['Partials/Remote/ScanComparison.html', 'href="{comparison.acceptanceEvidence.pdfUrl}"', 'export.pdf.composerOnly'];
        yield 'statement assistant' => ['Partials/Settings/TabStatement.html', 'js-aqg-statement-pdf', 'settings.statement.pdfComposerOnly'];
    }

    #[Test]
    #[DataProvider('pdfControlProvider')]
    public function everyPdfControlIsRenderedOnlyWhereMpdfLoads(string $template, string $controlMarker, string $composerOnlyKey): void
    {
        $html = (string)file_get_contents(self::PRIVATE . $template);

        self::assertStringContainsString(self::NAMESPACE, $html, $template . ' must declare the aqg ViewHelper namespace.');
        self::assertSame(1, substr_count($html, $controlMarker), $template . ': exactly one PDF control expected.');

        $control = strpos($html, $controlMarker);
        $open = strrpos(substr($html, 0, (int)$control), '<aqg:pdfExportAvailable>');
        $close = strpos($html, '</aqg:pdfExportAvailable>', (int)$control);
        self::assertNotFalse($open, $template . ': the PDF control must sit inside <aqg:pdfExportAvailable>.');
        self::assertNotFalse($close, $template . ': <aqg:pdfExportAvailable> must be closed after the PDF control.');
        self::assertFalse(
            strpos(substr($html, (int)$open, (int)$control - (int)$open), '</aqg:pdfExportAvailable>'),
            $template . ': the condition must still be open at the PDF control.'
        );

        $block = substr($html, (int)$open, (int)$close - (int)$open);
        $elseAt = strpos($block, '<f:else>');
        if ($elseAt !== false) {
            self::assertStringNotContainsString($controlMarker, substr($block, $elseAt), $template . ': the else branch must not offer PDF.');
        }
        self::assertStringContainsString($composerOnlyKey, $html, $template . ': without mPDF the view must say that PDF needs Composer.');
    }

    #[Test]
    public function theAcceptanceEvidenceKeepsItsCsvDownloadWithoutMpdf(): void
    {
        $html = (string)file_get_contents(self::PRIVATE . 'Partials/Remote/ScanComparison.html');
        preg_match('/<f:else>(.*?)<\/f:else>\s*<\/aqg:pdfExportAvailable>/s', $html, $else);

        self::assertNotEmpty($else, 'The acceptance evidence needs an else branch.');
        self::assertStringContainsString('href="{comparison.acceptanceEvidence.csvUrl}"', $else[1]);
        self::assertStringContainsString('export.pdf.composerOnly', $else[1]);
    }

    #[Test]
    public function theLabelsSayPdfNeedsAComposerInstallation(): void
    {
        foreach (['locallang.xlf', 'de.locallang.xlf'] as $file) {
            $labels = (string)file_get_contents(self::PRIVATE . 'Language/' . $file);
            foreach (['export.pdf.composerOnly', 'export.pdf.libraryMissing', 'settings.statement.pdfComposerOnly'] as $key) {
                self::assertMatchesRegularExpression(
                    '/<trans-unit id="' . preg_quote($key, '/') . '">\s*<source>[^<]*Composer installations[^<]*<\/source>/',
                    $labels,
                    $file . ': ' . $key
                );
            }
        }
    }

    #[Test]
    public function theProductCopySaysPdfIsComposerOnly(): void
    {
        $root = __DIR__ . '/../../../';
        $readme = (string)file_get_contents($root . 'README.md');
        $emconf = (string)file_get_contents($root . 'ext_emconf.php');
        $installation = (string)file_get_contents($root . 'Documentation/Installation/Index.rst');
        $troubleshooting = (string)file_get_contents($root . 'Documentation/Troubleshooting/Index.rst');

        self::assertStringContainsString('PDF export¹', $readme);
        self::assertStringContainsString('works only in Composer installations', $readme);
        self::assertStringContainsString('use every feature except PDF export', $readme);
        self::assertStringContainsString('CSV reporting (PDF reports in Composer installations)', $emconf);
        self::assertStringNotContainsString('PDF/CSV reporting', $emconf);
        self::assertStringContainsString('PDF export is available only in Composer installations', $installation);
        // Option A (providing mPDF in Classic mode) was not chosen; the docs must not suggest it.
        foreach ([$installation, $troubleshooting] as $doc) {
            self::assertStringNotContainsString('must be provided by the', $doc);
        }
        self::assertFileDoesNotExist($root . 'Resources/Private/Php/mpdf', 'mPDF is not bundled into the TER package.');
    }

    #[Test]
    public function theConditionFollowsTheLibraryAvailability(): void
    {
        $context = $this->createStub(RenderingContextInterface::class);

        self::assertSame(PdfGenerator::isAvailable(), PdfExportAvailableViewHelper::verdict([], $context));
        // The test runtime is a Composer installation, which installs mPDF.
        self::assertTrue(PdfExportAvailableViewHelper::verdict([], $context));
    }
}
