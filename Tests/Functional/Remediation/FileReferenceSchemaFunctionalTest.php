<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Remediation\FileReferenceSchemaService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;

final class FileReferenceSchemaFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function canonicalDecorativeColumnFromVersion160IsPresent(): void
    {
        $subject = $this->get(FileReferenceSchemaService::class);

        self::assertTrue($subject->hasDecorativeColumn());
    }

    #[Test]
    public function alternativeStorageLimitCanBeReadFromTheRealSchema(): void
    {
        $subject = $this->get(FileReferenceSchemaService::class);

        self::assertGreaterThan(0, $subject->alternativeStorageLimit());
        self::assertSame($subject->alternativeStorageLimit(), $subject->alternativeStorageLimit());
    }
}
