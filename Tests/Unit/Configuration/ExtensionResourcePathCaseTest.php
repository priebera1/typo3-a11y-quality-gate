<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Production servers have case-sensitive file systems; macOS development checkouts usually do not. The statement PDF
 * loaded `Css/pdf/statement.css` while the file is `Css/Pdf/statement.css`, so on Linux it rendered unstyled.
 */
final class ExtensionResourcePathCaseTest extends TestCase
{
    private const EXTENSION_ROOT = __DIR__ . '/../../../';

    #[Test]
    public function everyExtensionResourcePathInPhpMatchesTheFileCase(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::EXTENSION_ROOT . 'Classes', \FilesystemIterator::SKIP_DOTS));
        $paths = [];
        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                preg_match_all("/'EXT:a11y_quality_gate\\/([A-Za-z0-9_.\\/-]+)'/", (string)file_get_contents($file->getPathname()), $matches);
                foreach ($matches[1] as $path) {
                    $paths[$path] = true;
                }
            }
        }
        self::assertArrayHasKey('Resources/Public/Css/Pdf/statement.css', $paths);

        $mismatched = array_values(array_filter(array_keys($paths), fn (string $path): bool => !$this->existsWithExactCase($path)));

        self::assertSame([], $mismatched, 'These EXT: paths do not exist with this exact case.');
    }

    private function existsWithExactCase(string $relativePath): bool
    {
        $directory = rtrim(self::EXTENSION_ROOT, '/');
        foreach (array_filter(explode('/', $relativePath), static fn (string $segment): bool => $segment !== '') as $segment) {
            if (!is_dir($directory) || !in_array($segment, scandir($directory) ?: [], true)) {
                return false;
            }
            $directory .= '/' . $segment;
        }

        return true;
    }
}
