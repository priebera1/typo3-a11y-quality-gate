<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scan;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Scan\LegacySnippetIdentity;

final class LegacySnippetIdentityTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function legacySnippets(): iterable
    {
        yield 'Slovak letter as two byte entities' => [
            '<textarea name="msg">Predvolen&#xC3;&#xBD; text</textarea>',
            '<textarea name="msg">Predvolen&#xFD; text</textarea>',
        ];
        yield 'Latin, Cyrillic, CJK and symbols' => [
            '<p>&#xC4;&#x8D; &#xC5;&#x91; &#xE2;&#x82;&#xAC; &#xD0;&#x94; &#xE6;&#x97;&#xA5;</p>',
            '<p>&#x10D; &#x151; &#x20AC; &#x414; &#x65E5;</p>',
        ];
        yield 'a named entity next to a byte sequence' => [
            '<p>J&#xE1;n &#xC3;&#xA9;</p>',
            '<p>J&#xE1;n &#xE9;</p>',
        ];
        yield 'page element with record markers' => [
            '<body data-aqg-debug="1"> <div class="aqg-marker" data-aqg-table="tt_content" data-aqg-uid="1">',
            '<body>',
        ];
        yield 'page element keeps its own attributes' => [
            '<body class="home" data-aqg-debug="1"><main>',
            '<body class="home">',
        ];
        yield 'element with a marker wrapper inside' => [
            '<section><div class="aqg-marker" data-aqg-uid="5" data-aqg-ctype="text"><h2></h2>',
            '<section><h2></h2>',
        ];
    }

    #[Test]
    #[DataProvider('legacySnippets')]
    public function aLegacySnippetIsWrittenTheWay199WritesIt(string $stored, string $expected): void
    {
        self::assertSame($expected, LegacySnippetIdentity::upgrade($stored));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function currentSnippets(): iterable
    {
        yield 'repaired text' => ['<p>Predvolen&#xFD; text</p>'];
        yield 'Latin-1 letter, not a byte sequence' => ['<p>J&#xE1;n</p>'];
        yield 'non-breaking space' => ['<h2>&#xA0;</h2>'];
        yield 'page element' => ['<body>'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('currentSnippets')]
    public function aSnippetWithoutLegacyFormHasNoUpgrade(string $snippet): void
    {
        self::assertNull(LegacySnippetIdentity::upgrade($snippet));
    }
}
