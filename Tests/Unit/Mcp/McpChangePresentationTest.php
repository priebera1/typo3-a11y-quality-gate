<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Mcp\Remediation\McpChangePresentation;

/**
 * The approval page shows the changed part of a value with the element around it (remediations:apply-approved).
 */
final class McpChangePresentationTest extends TestCase
{
    #[Test]
    public function anAddedAttributeIsShownInItsWholeTag(): void
    {
        $change = McpChangePresentation::change(
            '<form action="/search"><input type="search" aria-label="Search terms"><button type="submit" class="icon-search"></button></form>',
            '<form action="/search"><input type="search" aria-label="Search terms"><button type="submit" class="icon-search" aria-label="Search"></button></form>',
        );

        self::assertSame('<button type="submit" class="icon-search"', $change['lead']);
        self::assertSame('', $change['removed']);
        self::assertSame(' aria-label="Search"', $change['added']);
        self::assertSame('>', $change['trail']);
        self::assertSame([true, true, 'aria-label', true], [$change['leadCut'], $change['trailCut'], $change['attribute'], $change['inTag']]);
    }

    #[Test]
    public function aChangedAttributeValueNamesThatAttribute(): void
    {
        $change = McpChangePresentation::change('<p><img src="/a.png" alt=""></p>', '<p><img src="/a.png" alt="Logo of the bakery"></p>');

        self::assertSame(['<img src="/a.png" alt="', '', 'Logo of the bakery', '">', 'alt'], [$change['lead'], $change['removed'], $change['added'], $change['trail'], $change['attribute']]);
    }

    #[Test]
    public function changedTextIsShownWithItsSurroundingTextAndNeverSplitsACharacter(): void
    {
        $change = McpChangePresentation::change('<p>Öffnungszeiten: <a href="/k">hier klicken</a>.</p>', '<p>Öffnungszeiten: <a href="/k">Kontakt und Anfahrt</a>.</p>');

        self::assertSame('<p>Öffnungszeiten: <a href="/k">', $change['lead']);
        self::assertSame('hier klicken', $change['removed']);
        self::assertSame('Kontakt und Anfahrt', $change['added']);
        self::assertSame('</a>.</p>', $change['trail']);
        self::assertSame(['', false], [$change['attribute'], $change['inTag']]);

        $umlaut = McpChangePresentation::change('Größe', 'Grüße');
        self::assertSame(['Gr', 'ö', 'ü', 'ße'], [$umlaut['lead'], $umlaut['removed'], $umlaut['added'], $umlaut['trail']]);
    }

    #[Test]
    public function longContextIsCut(): void
    {
        $change = McpChangePresentation::change(str_repeat('a', 200) . 'X' . str_repeat('b', 200), str_repeat('a', 200) . 'Y' . str_repeat('b', 200));

        self::assertSame([80, 80, true, true], [strlen($change['lead']), strlen($change['trail']), $change['leadCut'], $change['trailCut']]);
    }
}
