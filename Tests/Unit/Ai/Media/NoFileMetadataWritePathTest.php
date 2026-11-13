<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AQG writes to a hardcoded table/field set only (sys_file_reference alternative / tx_a11y_is_decorative). UX Atlas
 * open decision #1 (extend that set to sys_file_metadata) is not taken, so AI media must stay read-only: a suggestion
 * fills TYPO3's metadata form and TYPO3 Save stores it. These checks keep a server-side write path from coming back.
 */
final class NoFileMetadataWritePathTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    /** Database writes in any form: DataHandler maps, Doctrine writes, row locks, FAL metadata API writes. */
    private const WRITE_PATTERN = '/\b(?:process_datamap|process_cmdmap|executeStatement|createMetaDataRecord)\b|->\s*(?:update|insert|delete|truncate)\s*\(|FOR\s+UPDATE|getMetaData\(\)\s*->\s*(?:add|save|offsetSet)\s*\(/i';

    #[Test]
    public function noAjaxRouteAppliesFileMetadata(): void
    {
        $routes = require self::ROOT . '/Configuration/Backend/AjaxRoutes.php';

        self::assertIsArray($routes);
        self::assertArrayHasKey('a11y_ai_media_suggest_metadata', $routes, 'Suggestions (read-only) stay available.');
        foreach ($routes as $name => $route) {
            self::assertStringNotContainsString('apply_metadata', (string)$name);
            self::assertStringNotContainsString('apply-metadata', (string)($route['path'] ?? ''), $name);
            self::assertStringNotContainsString('applyMetadata', (string)($route['target'] ?? ''), $name);
        }
    }

    #[Test]
    public function noClassThatKnowsTheMetadataTableWritesToTheDatabase(): void
    {
        $offenders = [];
        foreach ($this->phpFiles(self::ROOT . '/Classes') as $file) {
            $code = (string)file_get_contents($file);
            if (!str_contains($code, 'sys_file_metadata') && !str_contains($code, 'SYS_FILE_METADATA')) {
                continue;
            }
            if (preg_match(self::WRITE_PATTERN, $this->withoutComments($code), $match) === 1) {
                $offenders[] = substr($file, strlen(self::ROOT) + 1) . ': ' . trim($match[0]);
            }
        }

        self::assertSame([], $offenders);
    }

    #[Test]
    public function noClassUsesTheFalMetadataRepositoryOrAFileMetadataWriter(): void
    {
        $offenders = [];
        foreach ($this->phpFiles(self::ROOT . '/Classes') as $file) {
            $code = $this->withoutComments((string)file_get_contents($file));
            // MetaDataRepository creates and updates records (findByFile() creates a missing one).
            if (preg_match('/\bMetaDataRepository\b|\bFileMetadataWriter\w*\b/', $code, $match) === 1) {
                $offenders[] = substr($file, strlen(self::ROOT) + 1) . ': ' . $match[0];
            }
        }

        self::assertSame([], $offenders);
        self::assertFileDoesNotExist(self::ROOT . '/Classes/Ai/Media/DataHandlerFileMetadataWriter.php');
        self::assertStringNotContainsString('FileMetadataWriter', (string)file_get_contents(self::ROOT . '/Configuration/Services.yaml'));
    }

    #[Test]
    public function noBackendScriptCallsAMetadataApplyRoute(): void
    {
        $offenders = [];
        foreach (glob(self::ROOT . '/Resources/Public/JavaScript/{,*/,*/*/}*.js', GLOB_BRACE) ?: [] as $file) {
            if (preg_match('/apply_metadata|applyMetadata/', (string)file_get_contents($file)) === 1) {
                $offenders[] = basename($file);
            }
        }

        self::assertSame([], $offenders);
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    private function withoutComments(string $code): string
    {
        $stripped = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $stripped .= is_array($token) ? $token[1] : $token;
        }

        return $stripped;
    }
}
