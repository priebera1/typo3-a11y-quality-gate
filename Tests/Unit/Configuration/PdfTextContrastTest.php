<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * WCAG 1.4.3 / EN 301 549 10.1.4.3 for the exported PDF reports: every text colour of the PDF stylesheets reaches
 * 4.5:1 on white. The reports are printed on white and light tints; the grey #7c828d used for labels, captions and
 * running headers measured 3.86:1 in the rendered exports. White text is only used on dark badges.
 */
final class PdfTextContrastTest extends TestCase
{
    private const PDF_CSS = __DIR__ . '/../../../Resources/Public/Css/Pdf/';
    private const PDF_TEMPLATES = __DIR__ . '/../../../Resources/Private/Templates/Export/';

    /**
     * @return iterable<string, array{string}>
     */
    public static function stylesheetProvider(): iterable
    {
        foreach (['base.css', 'local.css', 'statement.css'] as $file) {
            yield $file => [self::PDF_CSS . $file];
        }
        // Running headers and footers are styled inline in the templates (mPDF page headers).
        foreach (glob(self::PDF_TEMPLATES . '*Pdf.html') ?: [] as $template) {
            yield basename($template) => [$template];
        }
    }

    #[Test]
    #[DataProvider('stylesheetProvider')]
    public function everyTextColourMeetsAaOnWhite(string $path): void
    {
        $file = basename($path);
        $css = (string)file_get_contents($path);
        preg_match_all('/(?<![-\w])color:\s*#([0-9a-f]{6}|[0-9a-f]{3})\b/i', $css, $matches);
        if ($matches[1] === []) {
            self::assertStringEndsWith('.html', $file, $file . ' declares no text colours.');
            return;
        }

        $failing = [];
        foreach (array_unique(array_map('strtolower', $matches[1])) as $hex) {
            if (in_array($hex, ['fff', 'ffffff'], true)) {
                continue;
            }
            $ratio = $this->contrastOnWhite($hex);
            if ($ratio < 4.5) {
                $failing[] = sprintf('#%s (%.2f:1)', $hex, $ratio);
            }
        }

        self::assertSame([], $failing, $file . ': text colours below 4.5:1 on white.');
    }

    private function contrastOnWhite(string $hex): float
    {
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        $channel = static function (string $part): float {
            $value = hexdec($part) / 255;
            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $channel(substr($hex, 0, 2)) + 0.7152 * $channel(substr($hex, 2, 2)) + 0.0722 * $channel(substr($hex, 4, 2));

        return 1.05 / ($luminance + 0.05);
    }
}
