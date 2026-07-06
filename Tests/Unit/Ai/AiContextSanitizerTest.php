<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiContextSanitizer;

final class AiContextSanitizerTest extends TestCase
{
    #[Test]
    public function htmlEntitiesControlsAndWhitespaceAreNormalized(): void
    {
        $result = (new AiContextSanitizer())->sanitizeNullable(
            " <strong>Headquarters&nbsp;in</strong>\x01  Vienna\n ",
            100,
        );

        self::assertSame('Headquarters in Vienna', $result);
    }

    #[Test]
    public function genericLabelsAreNotSent(): void
    {
        self::assertNull((new AiContextSanitizer())->sanitizeNullable('Hero image', 100));
    }


    #[Test]
    public function genericCurrentAltCanBePreservedForQualityContext(): void
    {
        self::assertSame(
            'Image',
            (new AiContextSanitizer())->sanitizeNullable('Image', 100, false),
        );
    }

    #[Test]
    public function contextIsBoundedWithoutSendingWholeContent(): void
    {
        self::assertSame('0123456789', (new AiContextSanitizer())->sanitizeNullable('0123456789abcdef', 10));
    }
}
