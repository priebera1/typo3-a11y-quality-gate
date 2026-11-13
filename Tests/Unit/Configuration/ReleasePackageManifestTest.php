<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * NEW-07 (resilience audit): the release archive must contain the extension and nothing of the development setup.
 *
 * A release archive is `git archive` of a commit (Packagist and GitHub zips; .gitattributes export-ignore applies).
 * Every top-level entry of the working tree is therefore either on the shipped allowlist or export-ignored here, so a
 * new development file (an AI agent guide, an IDE folder, local QA data) cannot ship unnoticed — even when it is only
 * ignored locally, or force-added and committed.
 */
final class ReleasePackageManifestTest extends TestCase
{
    /** What a release ships at the top level. */
    private const SHIPPED = [
        'CHANGELOG.md', 'Classes', 'Configuration', 'Documentation', 'LICENSE.txt', 'README.md', 'Resources',
        'composer.json', 'ext_conf_template.txt', 'ext_emconf.php', 'ext_localconf.php', 'ext_tables.sql',
    ];

    /** Development-only content that must be export-ignored, whether it exists in this checkout or not. */
    private const NEVER_SHIPPED = [
        'CLAUDE.md', 'AGENTS.md', '.claude', '.impeccable', '.idea', '.vscode', '.ddev', 'var', 'Tests', '.github',
        'node_modules', 'package.json', 'package-lock.json', 'phpunit.xml', 'phpunit.unit.xml', 'vitest.config.js',
        '.gitignore', '.gitattributes', 'Build',
    ];

    #[Test]
    public function developmentOnlyContentIsExportIgnored(): void
    {
        $ignored = $this->exportIgnored();
        foreach (self::NEVER_SHIPPED as $entry) {
            self::assertContains($entry, $ignored, $entry . ' must carry export-ignore in .gitattributes');
        }
        self::assertContains('Resources/Private/Scss', $ignored, 'Sass sources are not shipped (the compiled CSS is).');
    }

    #[Test]
    public function everyTopLevelEntryIsShippedOnPurposeOrExportIgnored(): void
    {
        $ignored = $this->exportIgnored();
        $unexpected = [];
        foreach (scandir($this->root()) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.git' || in_array($entry, self::SHIPPED, true) || in_array($entry, $ignored, true)) {
                continue;
            }
            $unexpected[] = $entry;
        }

        self::assertSame([], $unexpected, 'Add the entry to the release allowlist or export-ignore it in .gitattributes.');
    }

    #[Test]
    public function theShippedEntriesExist(): void
    {
        foreach (self::SHIPPED as $entry) {
            self::assertFileExists($this->root() . '/' . $entry);
        }
    }

    /** @return list<string> paths without leading/trailing slash */
    private function exportIgnored(): array
    {
        $paths = [];
        foreach (file($this->root() . '/.gitattributes', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !preg_match('/^(\S+)\s+.*\bexport-ignore\b/', $line, $match)) {
                continue;
            }
            $paths[] = trim($match[1], '/');
        }

        return $paths;
    }

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }
}
