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
     * mPDF writes no structure tree, so the reports are untagged PDFs: screen readers get no headings, tables or
     * reading order from them. The UI and the reports say so and point to the CSV export as the accessible copy.
     * What mPDF can write is set here: the document language, the title shown instead of the file name, and a
     * bookmark outline built from the templates' <bookmark> tags.
     *
     * @param array<string, string> $imageVars
     */
    public function render(
        string $html,
        string $title = 'AQG Report',
        array $imageVars = [],
        string $css = '',
        string $language = 'en',
    ): string {
        $tempDir = $this->prepareTempDir();

        $mpdf = new Mpdf([
            'mode' => 'c',
            'format' => 'A4',
            'default_font' => 'helvetica',
            'margin_top' => 28,
            'margin_right' => 14,
            'margin_bottom' => 18,
            'margin_left' => 14,
            'margin_header' => 6,
            'margin_footer' => 6,
            'tempDir' => $tempDir,
        ]);

        $previousErrorHandler = set_error_handler(
            static function (int $level, string $message, string $file = '', int $line = 0) use (&$previousErrorHandler): bool {
                if (self::isMpdfLetterWrapWarning($level, $message, $file)) {
                    return true;
                }

                return $previousErrorHandler !== null && (bool)$previousErrorHandler($level, $message, $file, $line);
            }
        );

        try {
            $mpdf->autoScriptToLang = false;
            $mpdf->autoLangToFont = false;
            $mpdf->useSubstitutions = false;
            if (property_exists($mpdf, 'simpleTables')) {
                $mpdf->simpleTables = true;
            }
            if (property_exists($mpdf, 'packTableData')) {
                $mpdf->packTableData = true;
            }
            $mpdf->SetDefaultFont('helvetica');
            $mpdf->SetTitle($title);
            $mpdf->SetAuthor('Accessibility Quality Gate');
            $language = preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $language) === 1 ? $language : 'en';
            $mpdf->default_lang = $language;
            $mpdf->currentLang = $language;
            $mpdf->SetDisplayPreferences('/DisplayDocTitle');

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

            // The body is written in HTML_BODY mode, which does not read <style> blocks: without this, a template's own
            // rules were printed as text on the first page and never applied.
            [$html, $templateCss] = $this->extractStyleBlocks($html);
            if ($templateCss !== '') {
                $mpdf->WriteHTML($templateCss, HTMLParserMode::HEADER_CSS);
            }

            $pageChrome = $this->extractPageChrome($html);
            $hasPageChrome = $pageChrome['header'] !== '' || $pageChrome['footer'] !== '';
            $body = $pageChrome['body'];
            $coverWithoutHeader = str_contains($body, 'AQG_COVER_WITHOUT_HEADER');

            if ($pageChrome['footer'] !== '') {
                $mpdf->SetHTMLFooter($pageChrome['footer']);
            }

            if ($coverWithoutHeader && $hasPageChrome) {
                // Remote frontend PDFs have a designed cover page. Keep the footer on
                // the cover, but start the running header only after the first pagebreak.
                $mpdf->AddPage('', '', '', '', '', 14, 14, 28, 18, 6, 6);
                [$coverHtml, $restHtml] = $this->splitAtFirstPagebreak($body);
                $mpdf->WriteHTML($coverHtml, HTMLParserMode::HTML_BODY);

                if ($pageChrome['header'] !== '') {
                    $mpdf->SetHTMLHeader($pageChrome['header']);
                }
                if ($restHtml !== '') {
                    $mpdf->WriteHTML($restHtml, HTMLParserMode::HTML_BODY);
                }

                return $mpdf->Output('', Destination::STRING_RETURN);
            }

            if ($pageChrome['header'] !== '') {
                $mpdf->SetHTMLHeader($pageChrome['header']);
            }

            // mPDF only writes the active header/footer when a page is created.
            // Create the first page explicitly after the header/footer has been set,
            // otherwise the first page can be generated without the page chrome.
            if ($hasPageChrome) {
                $mpdf->AddPage('', '', '', '', '', 14, 14, 28, 18, 6, 6);
            }

            $mpdf->WriteHTML($body, HTMLParserMode::HTML_BODY);

            return $mpdf->Output('', Destination::STRING_RETURN);
        } finally {
            restore_error_handler();
            // mPDF switches mb_internal_encoding() to windows-1252 for its core fonts and has no destructor: only
            // cleanup() restores it. Without this, mb_strlen() counted bytes for the rest of the request.
            $mpdf->cleanup();
        }
    }

    /**
     * Tables that can hold long URLs or job IDs use `overflow: wrap`, so mPDF wraps an over-long word instead of
     * shrinking the whole table below 8 pt. In core-font mode mPDF then splits each cell line with preg_split('//u')
     * on windows-1252 text: for a line with a non-ASCII character (·, ä) that returns false and foreach() warns. The
     * line is only skipped by the letter-width check and still renders correctly, so this one warning is dropped.
     */
    private static function isMpdfLetterWrapWarning(int $level, string $message, string $file): bool
    {
        return $level === E_WARNING
            && str_ends_with(str_replace('\\', '/', $file), '/mpdf/src/Mpdf.php')
            // PHP 8.2 says "bool given", 8.3+ "false given".
            && preg_match('/^foreach\(\) argument must be of type array\|object, (bool|false) given$/', $message) === 1;
    }


    /**
     * @return array{0:string,1:string} The HTML without its <style> blocks, and their CSS.
     */
    private function extractStyleBlocks(string $html): array
    {
        $css = [];
        $body = preg_replace_callback(
            '/<style\b[^>]*>(.*?)<\/style>/is',
            static function (array $matches) use (&$css): string {
                $css[] = trim($matches[1]);
                return '';
            },
            $html
        );

        return [is_string($body) ? $body : $html, trim(implode("\n", $css))];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitAtFirstPagebreak(string $html): array
    {
        if (!preg_match('/<pagebreak\b[^>]*\/?>(?:<\/pagebreak>)?/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            return [$html, ''];
        }

        $offset = (int)$matches[0][1];
        $length = strlen((string)$matches[0][0]);

        return [
            substr($html, 0, $offset),
            substr($html, $offset, $length) . substr($html, $offset + $length),
        ];
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
