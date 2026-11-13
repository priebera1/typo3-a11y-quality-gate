<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * German AI settings copy that changed meaning or left the Atlas glossary (Atlas audit F-17, F-29):
 * - the hard stop lets requests in progress finish (AiBudgetService checks before a request); the German text said
 *   they are terminated ("beendet");
 * - a TYPO3 site is a "Website" in the German UI, never a "Standort" (location) or "Site".
 */
final class GermanAiSettingsCopyTest extends TestCase
{
    private const GERMAN = __DIR__ . '/../../../Resources/Private/Language/de.locallang.xlf';

    #[Test]
    public function theHardStopLetsRequestsInProgressFinish(): void
    {
        $target = self::target('settings.ai.limits.hardStopHelp');

        self::assertStringContainsString('abgeschlossen', $target);
        self::assertStringNotContainsString('beendet', $target);
        self::assertStringNotContainsString('abgebrochen', $target);
    }

    #[Test]
    public function aSiteIsAWebsiteInTheGermanAiSettings(): void
    {
        $offenders = [];
        foreach (self::units() as $id => $target) {
            if (!str_starts_with($id, 'settings.ai.')) {
                continue;
            }
            if (preg_match('/standort|\bsitespezifisch|\bSite\b|TYPO3-Site\b/iu', $target) === 1) {
                $offenders[] = $id . ': ' . $target;
            }
        }

        self::assertSame([], $offenders);
        self::assertSame('Websitespezifischer Schlüssel', self::target('settings.ai.siteSpecificKey'));
        self::assertSame('Websitespezifischer verschlüsselter Schlüssel', self::target('settings.ai.siteSpecificEncryptedKey'));
    }

    private static function target(string $id): string
    {
        $units = self::units();
        self::assertArrayHasKey($id, $units);

        return $units[$id];
    }

    /** @return array<string,string> */
    private static function units(): array
    {
        $document = new \DOMDocument();
        $document->load(self::GERMAN);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
        $units = [];
        foreach ($xpath->query('//x:trans-unit') ?: [] as $unit) {
            if ($unit instanceof \DOMElement) {
                $units[$unit->getAttribute('id')] = trim((string)$xpath->query('x:target', $unit)?->item(0)?->textContent);
            }
        }

        return $units;
    }
}
