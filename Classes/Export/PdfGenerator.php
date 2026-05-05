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
            'margin_top' => 16,
            'margin_right' => 14,
            'margin_bottom' => 16,
            'margin_left' => 14,
            'tempDir' => $tempDir,
        ]);

        $mpdf->SetTitle($title);
        $mpdf->SetAuthor('Accessibility Quality Gate');

        foreach ($imageVars as $name => $binaryContent) {
            $mpdf->imageVars[$name] = $binaryContent;
        }

        $resolvedCss = trim($css) !== '' ? $css : $this->readDefaultCss();

        if ($resolvedCss !== '') {
            $mpdf->WriteHTML($resolvedCss, HTMLParserMode::HEADER_CSS);
        }

        $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);

        return $mpdf->Output('', Destination::STRING_RETURN);
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