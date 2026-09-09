<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The declared release version must be the same everywhere it is published.
 *
 * `ext_emconf.php` feeds TER, the composer `extra.typo3/cms.version` feeds Packagist and the
 * installed package metadata that AQG reports to its API. A mismatch ships an extension that
 * announces two different versions depending on how it was installed.
 */
final class ReleaseVersionConsistencyTest extends TestCase
{
    private const EXPECTED_VERSION = '1.9.2';

    #[Test]
    public function extEmconfAndComposerDeclareTheSameVersion(): void
    {
        self::assertSame(self::EXPECTED_VERSION, $this->emconfVersion());
        self::assertSame(self::EXPECTED_VERSION, $this->composerVersion());
    }

    #[Test]
    public function theChangelogDocumentsTheDeclaredVersion(): void
    {
        $changelog = (string)file_get_contents(__DIR__ . '/../../../CHANGELOG.md');

        self::assertStringContainsString(
            '## [' . self::EXPECTED_VERSION . ']',
            $changelog,
            'A release must ship with its own changelog section.'
        );
    }

    private function emconfVersion(): string
    {
        $EM_CONF = [];
        $_EXTKEY = 'a11y_quality_gate';
        require __DIR__ . '/../../../ext_emconf.php';

        return (string)($EM_CONF[$_EXTKEY]['version'] ?? '');
    }

    private function composerVersion(): string
    {
        $composer = json_decode(
            (string)file_get_contents(__DIR__ . '/../../../composer.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return (string)($composer['extra']['typo3/cms']['version'] ?? '');
    }
}
