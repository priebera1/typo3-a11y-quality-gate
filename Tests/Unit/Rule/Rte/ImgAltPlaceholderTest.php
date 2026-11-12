<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\ImgAltRedundantPhraseRule;
use Priebera\A11yQualityGate\Service\DictionaryRegistry;
use Priebera\A11yQualityGate\Service\PlaceholderAltText;

/**
 * U-10 (2026-11-05 company acceptance): an image with alt="image" passed every check. A placeholder is only the whole alt
 * text being a generic word (optionally numbered); a real description that contains such a word is never flagged.
 */
final class ImgAltPlaceholderTest extends TestCase
{
    #[Test]
    #[DataProvider('placeholders')]
    public function aGenericWordAsTheWholeAltTextIsAPlaceholder(string $alt): void
    {
        self::assertTrue(PlaceholderAltText::isPlaceholder($alt));
        $violations = $this->rule()->check($this->ctx(sprintf('<p><img src="a.jpg" alt="%s"></p>', htmlspecialchars($alt, ENT_QUOTES))));
        self::assertCount(1, $violations);
        self::assertSame('Image alt text is a placeholder, not a description.', $violations[0]->message);
    }

    /** @return array<string, array{string}> */
    public static function placeholders(): array
    {
        return [
            'image' => ['image'], 'Image.' => ['Image.'], 'photo 1' => ['photo 1'], 'Bild' => ['Bild'],
            'Foto' => ['Foto'], 'picture' => ['Picture'], 'img_02' => ['img_02'], 'alt text' => ['alt text'],
            'placeholder' => ['Placeholder'], 'Platzhalter' => ['Platzhalter'], 'graphic' => ['Grafik'],
        ];
    }

    #[Test]
    #[DataProvider('descriptions')]
    public function realDescriptionsAreNotPlaceholders(string $alt): void
    {
        self::assertFalse(PlaceholderAltText::isPlaceholder($alt));
        self::assertSame([], $this->rule()->check($this->ctx(sprintf('<p><img src="a.jpg" alt="%s"></p>', htmlspecialchars($alt, ENT_QUOTES)))));
    }

    /** @return array<string, array{string}> */
    public static function descriptions(): array
    {
        return [
            'image of a workshop' => ['Mechanic adjusting the brakes of a cargo bike'],
            'contains word' => ['Photo booth at the summer party'],
            'brand' => ['Northwind Logo'],
            'icon with meaning' => ['Download'],
            'German description' => ['Bildungszentrum in Bratislava'],
        ];
    }

    private function rule(): ImgAltRedundantPhraseRule
    {
        $dictionary = $this->createMock(DictionaryRegistry::class);
        $dictionary->method('resolveForContext')->willReturn([]);

        return new ImgAltRedundantPhraseRule($dictionary);
    }

    private function ctx(string $content): CheckContext
    {
        return new CheckContext(siteIdentifier: 'main', pageUid: 1, sourceLangUid: 0, sourceTable: Tables::TT_CONTENT, sourceUid: 42, sourceField: 'bodytext', content: $content);
    }
}
