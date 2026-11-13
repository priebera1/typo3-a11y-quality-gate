<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Media\MediaAiFieldPolicy;

final class MediaAiFieldPolicyTest extends TestCase
{
    #[Test]
    public function onlyAlternativeTitleAndDescriptionAreOnTheAllowList(): void
    {
        self::assertSame(['alternative', 'title', 'description'], MediaAiFieldPolicy::METADATA_FIELDS);
        foreach (['width', 'height', 'file', 'uid', 'pid', 'sys_language_uid', 'l10n_parent', 'categories', 'caption', 'creator', 'copyright', 'location_city'] as $field) {
            self::assertFalse(MediaAiFieldPolicy::isMetadataField($field), $field);
        }
        self::assertSame(['alternative', 'description'], MediaAiFieldPolicy::filterFields(['description', 'width', 'alternative', ['nested'], 'alternative']));
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function rejectedValues(): iterable
    {
        yield 'markup' => ['alternative', 'A <b>bold</b> chart', 'markup'];
        yield 'script' => ['description', '<script>alert(1)</script>', 'markup'];
        yield 'control character' => ['title', "Logo\x07", 'markup'];
        yield 'url' => ['alternative', 'See https://example.org for details', 'url'];
        yield 'www' => ['title', 'www.example.org', 'url'];
        yield 'javascript' => ['description', 'javascript:alert(1)', 'url'];
        yield 'empty' => ['alternative', "  \n ", 'empty'];
        yield 'too long' => ['alternative', str_repeat('a', 251), 'too_long'];
        yield 'not allowed field' => ['width', '100', 'field_not_allowed'];
    }

    #[Test]
    #[DataProvider('rejectedValues')]
    public function unsafeOrInvalidValuesAreRejectedNotRepaired(string $field, string $value, string $reason): void
    {
        self::assertSame(['', $reason], (new MediaAiFieldPolicy())->validate($field, $value));
    }

    #[Test]
    public function valuesAreNormalisedPlainText(): void
    {
        $policy = new MediaAiFieldPolicy();

        self::assertSame(['Bar chart of water prices', ''], $policy->validate('alternative', "  \"Bar chart\n of   water prices\" "));
        self::assertSame(["Step one.\n\nStep two.", ''], $policy->validate('description', "Step one.\r\n\r\n\r\n\r\nStep two."));
        self::assertSame(['Wörter mit Umlaut – 2,24 €', ''], $policy->validate('title', 'Wörter mit Umlaut – 2,24 €'));
    }
}
