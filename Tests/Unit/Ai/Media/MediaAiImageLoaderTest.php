<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Media\MediaAiImageLoader;

final class MediaAiImageLoaderTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function svgsWithExternalReferences(): iterable
    {
        yield 'image href' => ['<svg xmlns="http://www.w3.org/2000/svg"><image href="file:///etc/passwd"/></svg>'];
        yield 'xlink href' => ['<svg xmlns:xlink="http://www.w3.org/1999/xlink"><image xlink:href="https://evil.example/x.png"/></svg>'];
        yield 'use of other file' => ['<svg><use href="sprite.svg#icon"/></svg>'];
        yield 'css url' => ['<svg><rect style="fill:url(http://169.254.169.254/latest)"/></svg>'];
        yield 'css import' => ['<svg><style>@import "https://evil.example/a.css";</style></svg>'];
        yield 'entity' => ['<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY x SYSTEM "file:///etc/hosts">]><svg>&x;</svg>'];
        yield 'foreign object' => ['<svg><foreignObject><iframe src="x"/></foreignObject></svg>'];
        yield 'script' => ['<svg><script>fetch("x")</script></svg>'];
        yield 'stylesheet pi' => ['<?xml-stylesheet href="https://evil.example/s.css"?><svg/>'];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('svgsWithExternalReferences')]
    public function svgsThatReferToAnythingOutsideThemselvesAreNeverRasterised(string $svg): void
    {
        self::assertTrue(MediaAiImageLoader::svgHasExternalReferences($svg));
    }

    #[Test]
    public function selfContainedSvgsWithInternalReferencesAreRasterised(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink"><defs><marker id="a"/><linearGradient id="g"/></defs>'
            . '<path marker-end="url(#a)" fill="url(\'#g\')"/><use xlink:href="#a"/><text>Well</text></svg>';

        self::assertFalse(MediaAiImageLoader::svgHasExternalReferences($svg));
    }
}
