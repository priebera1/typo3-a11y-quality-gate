<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * A preview-scan template once shipped `key: '/Volumes/…/LLL:EXT:…/locallang.xlfction.scanning'` (a local path spliced
 * into a label key): Fluid renders the default text, so nothing failed visibly. Every label reference must be a
 * well-formed AQG LLL key, and no shipped file may contain a local file-system path.
 */
final class LabelReferenceSyntaxTest extends TestCase
{
    #[Test]
    public function everyAqgLabelReferenceNamesALanguageFileAndIsNotPrefixedByAPath(): void
    {
        $broken = [];
        foreach ($this->files(['html', 'php']) as $file) {
            $content = (string)file_get_contents($file);
            // Key suffixes may be dynamic (Fluid "{…}", PHP concatenation); the part up to the file name may not.
            preg_match_all('~(\S?)LLL:EXT:a11y_quality_gate/Resources/Private/Language/([^:\'"\s]*)(:?)~', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as [$reference, $before, $languageFile]) {
                // A bare directory prefix (BackendLanguageService) and a whole-file reference (Modules.php) are fine.
                $pathPrefixed = $before === '/' || $before === '.';
                $unknownFile = $languageFile !== '' && (preg_match('~^[a-z_.]+\.xlf$~', $languageFile) !== 1
                    || !is_file($this->root() . '/Resources/Private/Language/' . $languageFile));
                if ($pathPrefixed || $unknownFile) {
                    $broken[] = basename($file) . ': ' . $reference;
                }
            }
        }

        self::assertSame([], $broken);
    }

    #[Test]
    public function noShippedFileContainsALocalPath(): void
    {
        $leaks = [];
        foreach ($this->files(['html', 'php', 'js', 'xlf', 'scss', 'css', 'yaml']) as $file) {
            if (preg_match('~/(Volumes|Users|home)/[A-Za-z]~', (string)file_get_contents($file)) === 1) {
                $leaks[] = substr($file, strlen($this->root()) + 1);
            }
        }

        self::assertSame([], $leaks);
    }

    /**
     * @param list<string> $extensions
     * @return list<string>
     */
    private function files(array $extensions): array
    {
        $files = [];
        foreach (['Classes', 'Configuration', 'Resources'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && in_array($file->getExtension(), $extensions, true)) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
