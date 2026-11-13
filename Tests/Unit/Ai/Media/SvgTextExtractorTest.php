<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Media\SvgTextExtractor;

final class SvgTextExtractorTest extends TestCase
{
    #[Test]
    public function readsTitleDescriptionAndTextLabelsOnceInDocumentOrder(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><title>Water cycle</title><desc>Seven steps</desc>'
            . '<text x="1">Evaporation</text><text><tspan>Rain</tspan> <tspan>fall</tspan></text><text>Evaporation</text>'
            . '<text>  </text><script>alert(1)</script></svg>';

        self::assertSame(['Water cycle', 'Seven steps', 'Evaporation', 'Rain fall'], (new SvgTextExtractor())->labels($svg));
    }

    #[Test]
    public function entitiesDoctypesAndBrokenMarkupYieldNothing(): void
    {
        $extractor = new SvgTextExtractor();

        self::assertSame([], $extractor->labels('<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/passwd">]><svg><text>&x;</text></svg>'));
        self::assertSame([], $extractor->labels('<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg><text>A</text></svg>'));
        self::assertSame([], $extractor->labels('<svg><text>unclosed'));
        self::assertSame([], $extractor->labels(''));
        self::assertSame([], $extractor->labels('<svg>' . str_repeat(' ', SvgTextExtractor::MAX_BYTES) . '</svg>'));
    }

    #[Test]
    public function labelsAreBoundedAndContainNoMarkupCharacters(): void
    {
        $texts = '';
        for ($i = 0; $i < 60; $i++) {
            $texts .= '<text>Label ' . $i . ' &lt;b&gt;</text>';
        }
        $labels = (new SvgTextExtractor())->labels('<svg>' . $texts . '<text>' . str_repeat('x', 300) . '</text></svg>');

        self::assertCount(40, $labels);
        self::assertSame('Label 0 b', $labels[0]);
    }
}
