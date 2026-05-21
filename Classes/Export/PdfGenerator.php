<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class PdfGenerator
{
    /**
     * @param array<string, string> $imageVars
     */
    public function render(
        string $html,
        string $title = 'AQG Report',
        array $imageVars = [],
        string $css = '',
    ): string {
        $tempDir = $this->prepareTempDir();

        $mpdf = new Mpdf([
            'format' => 'A4',
            'margin_top' => 28,
            'margin_right' => 14,
            'margin_bottom' => 18,
            'margin_left' => 14,
            'margin_header' => 6,
            'margin_footer' => 6,
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('Accessibility Quality Gate');

        // Keep mPDF page placeholders out of Fluid parsing and variable escaping.
        $html = strtr($html, [
            '###AQG_PAGENO###' => '{PAGENO}',
            '###AQG_NBPG###' => '{nbpg}',
        ]);

        foreach ($imageVars as $name => $binaryContent) {
            $mpdf->imageVars[$name] = $binaryContent;
        }

        $resolvedCss = trim($css) !== '' ? $css : $this->readDefaultCss();

        if ($resolvedCss !== '') {
            $mpdf->WriteHTML($resolvedCss, HTMLParserMode::HEADER_CSS);
        }

        $pageChrome = $this->extractPageChrome($html);
        $hasPageChrome = $pageChrome['header'] !== '' || $pageChrome['footer'] !== '';

        if ($pageChrome['header'] !== '') {
            $mpdf->SetHTMLHeader($pageChrome['header']);
        }
        if ($pageChrome['footer'] !== '') {
            $mpdf->SetHTMLFooter($pageChrome['footer']);
        }

        // mPDF only writes the active header/footer when a page is created.
        // Create the first page explicitly after the header/footer has been set,
        // otherwise the first page can be generated without the page chrome.
        if ($hasPageChrome) {
            $mpdf->AddPage('', '', '', '', '', 14, 14, 28, 18, 6, 6);
        }

        $mpdf->WriteHTML($pageChrome['body'], HTMLParserMode::HTML_BODY);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }


    /**
     * @return array{body:string, header:string, footer:string}
     */
    private function extractPageChrome(string $html): array
    {
        $header = $this->extractFirstNamedHtmlBlock($html, 'htmlpageheader');
        $footer = $this->extractFirstNamedHtmlBlock($html, 'htmlpagefooter');

        $body = preg_replace('/<htmlpageheader\b[^>]*>.*?<\/htmlpageheader>/is', '', $html);
        $body = is_string($body) ? $body : $html;
        $body = preg_replace('/<htmlpagefooter\b[^>]*>.*?<\/htmlpagefooter>/is', '', $body);
        $body = is_string($body) ? $body : $html;
        $body = preg_replace('/<sethtmlpage(?:header|footer)\b[^>]*\/?>/i', '', $body);
        $body = is_string($body) ? $body : $html;

        return [
            'body' => $body,
            'header' => $header,
            'footer' => $footer,
        ];
    }

    private function extractFirstNamedHtmlBlock(string $html, string $tagName): string
    {
        if (!preg_match('/<' . preg_quote($tagName, '/') . '\b[^>]*>(.*?)<\/' . preg_quote($tagName, '/') . '>/is', $html, $matches)) {
            return '';
        }

        return trim((string)($matches[1] ?? ''));
    }

    private function readDefaultCss(): string
    {
        $path = GeneralUtility::getFileAbsFileName(
            'EXT:a11y_quality_gate/Resources/Public/Css/Pdf/base.css'
        );

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return '';
        }

        $css = file_get_contents($path);

        return is_string($css) ? $css : '';
    }

    private function prepareTempDir(): string
    {
        $tempDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'aqg_mpdf';

        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        $this->cleanupOldTempFiles($tempDir);

        return $tempDir;
    }

    private function cleanupOldTempFiles(string $tempDir): void
    {
        $maxAge = 3600;
        $now = time();

        foreach (glob($tempDir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $mtime = filemtime($file);
            if ($mtime === false) {
                continue;
            }

            if (($now - $mtime) > $maxAge) {
                @unlink($file);
            }
        }
    }
}
