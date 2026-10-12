<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Every control that starts a content scan carries the selected language.
 *
 * The click handler posts `languageUid`; a control without `data-language-uid` started the scan in the default
 * language while a translation was selected (Overview table rows, Content page "Scan this page" / "Scan again").
 */
final class ScanActionLanguageTemplateTest extends TestCase
{
    #[Test]
    public function everyContentScanControlCarriesTheSelectedLanguage(): void
    {
        $root = dirname(__DIR__, 3) . '/Resources/Private';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $controls = 0;
        $missing = [];

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'html') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            preg_match_all('/<(?:button|a)\b[^>]*data-action="a11y-(?:rescan|scan-all)"[^>]*>/s', $source, $matches);
            foreach ($matches[0] as $tag) {
                $controls++;
                if (!str_contains($tag, 'data-language-uid="{')) {
                    $missing[] = substr($file->getPathname(), strlen($root) + 1) . ': ' . preg_replace('/\s+/', ' ', $tag);
                }
            }
        }

        self::assertGreaterThan(4, $controls, 'The scan controls were not found; the pattern needs updating.');
        self::assertSame([], $missing);
    }
}
