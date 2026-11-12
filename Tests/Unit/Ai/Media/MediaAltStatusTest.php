<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Media\MediaAltStatus;

/**
 * Atlas audit F-04 (c): the ALT status chip on an image's IRRE header states only what AQG knows from the stored record,
 * decided like FileReferenceAltRule: a string (also empty) overrides the file default, NULL inherits it.
 */
final class MediaAltStatusTest extends TestCase
{
    /** @return iterable<string,array{mixed,bool,string,string}> */
    public static function records(): iterable
    {
        yield 'own text' => ['Bar chart of water prices', false, 'Chart', MediaAltStatus::OWN];
        yield 'empty override hides the default' => ['', false, 'Chart', MediaAltStatus::MISSING];
        yield 'whitespace override' => ['  ', false, '', MediaAltStatus::MISSING];
        yield 'inherits a default' => [null, false, 'Chart', MediaAltStatus::INHERITED];
        yield 'inherits nothing' => [null, false, ' ', MediaAltStatus::MISSING];
        yield 'decorative wins' => ['', true, '', MediaAltStatus::DECORATIVE];
    }

    #[Test]
    #[DataProvider('records')]
    public function theStatusFollowsTheStoredRecord(mixed $alternative, bool $decorative, string $fileDefault, string $expected): void
    {
        self::assertSame($expected, MediaAltStatus::of($alternative, $decorative, $fileDefault));
    }

    #[Test]
    public function theChipCarriesTheStateInWordsAndAsAHook(): void
    {
        $html = MediaAltStatus::chipHtml(MediaAltStatus::MISSING);

        self::assertStringContainsString('class="aqg-media-altchip aqg-media-altchip--missing badge badge-danger"', $html);
        self::assertStringContainsString('data-aqg-alt-chip="missing"', $html);
        self::assertStringContainsString('>ALT missing</span>', $html);
        self::assertStringContainsString('>ALT own<', MediaAltStatus::chipHtml(MediaAltStatus::OWN));
        self::assertStringContainsString('>Decorative<', MediaAltStatus::chipHtml(MediaAltStatus::DECORATIVE));
        self::assertStringContainsString('aqg-media-altchip--missing', MediaAltStatus::chipHtml('<script>'), 'Unknown states fall back to the label of "missing".');
    }
}
