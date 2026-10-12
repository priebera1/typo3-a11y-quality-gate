<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TYPO3 busts import-map URLs with a hash of the installed extension versions, which a path or VCS install keeps while
 * its files change. Browsers then ran earlier AQG scripts next to new templates (1.9.9: the row scan showed the old
 * "Scanning..." button text). Every AQG module is listed with its own content version.
 */
final class JavaScriptModuleVersionTest extends TestCase
{
    private const MODULE_ROOT = __DIR__ . '/../../../Resources/Public/JavaScript/';

    #[Test]
    public function everyModuleIsMappedWithItsContentVersion(): void
    {
        $imports = $this->imports();
        $files = $this->moduleFiles();

        self::assertNotEmpty($files);
        foreach ($files as $relativePath) {
            $specifier = '@priebera/a11y-quality-gate/' . $relativePath;
            self::assertArrayHasKey($specifier, $imports, $relativePath);
            $expected = substr((string)hash_file('sha256', self::MODULE_ROOT . $relativePath), 0, 12);
            self::assertSame(
                'EXT:a11y_quality_gate/Resources/Public/JavaScript/' . $relativePath . '?v=' . $expected,
                $imports[$specifier],
                $relativePath
            );
        }
    }

    #[Test]
    public function thePrefixStaysForSpecifiersWithTheirOwnQuery(): void
    {
        self::assertSame(
            'EXT:a11y_quality_gate/Resources/Public/JavaScript/',
            $this->imports()['@priebera/a11y-quality-gate/'] ?? null
        );
    }

    /**
     * @return array<string, string>
     */
    private function imports(): array
    {
        $configuration = require __DIR__ . '/../../../Configuration/JavaScriptModules.php';
        self::assertIsArray($configuration);
        self::assertSame(['backend'], $configuration['dependencies'] ?? null);

        return $configuration['imports'] ?? [];
    }

    /**
     * @return list<string>
     */
    private function moduleFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULE_ROOT, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile() && $file->getExtension() === 'js') {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(self::MODULE_ROOT)));
            }
        }
        sort($files);

        return $files;
    }
}
