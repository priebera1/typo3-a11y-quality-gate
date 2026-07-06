<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\Contract\FileReferenceSchemaServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;

final class ImageAltTextValidatorTest extends TestCase
{
    #[Test]
    public function returnsTrimmedReviewedText(): void
    {
        self::assertSame('A cyclist crossing a bridge', $this->subject()->validate('  A cyclist crossing a bridge  '));
    }

    #[Test]
    public function rejectsEmptyText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject()->validate('   ');
    }

    #[Test]
    public function acceptsTextAtTheActualStorageLimitWithoutTruncation(): void
    {
        $value = str_repeat('x', 16);
        self::assertSame($value, $this->subject(16)->validate($value));
    }

    #[Test]
    public function rejectsOverlongText(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->subject(16)->validate(str_repeat('x', 17));
    }
    private function subject(int $storageLimit = 1024): ImageAltTextValidator
    {
        $schemaService = $this->createMock(FileReferenceSchemaServiceInterface::class);
        $schemaService->method('alternativeStorageLimit')->willReturn($storageLimit);

        return new ImageAltTextValidator($schemaService);
    }
}
