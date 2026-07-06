<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Hook;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Hook\FileReferenceDecorativeHook;
use Priebera\A11yQualityGate\Remediation\Contract\FileReferenceSchemaServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class FileReferenceDecorativeHookTest extends TestCase
{
    #[Test]
    public function decorativeStateIsPreservedWhenAlternativeTextIsEmpty(): void
    {
        $fields = ['tx_a11y_is_decorative' => 1, 'alternative' => ''];
        $this->subject()->processDatamap_preProcessFieldArray(
            $fields,
            'sys_file_reference',
            42,
            (new \ReflectionClass(DataHandler::class))->newInstanceWithoutConstructor(),
        );

        self::assertSame(1, $fields['tx_a11y_is_decorative']);
        self::assertSame('', $fields['alternative']);
    }

    #[Test]
    public function nonEmptyManualAlternativeTextResetsDecorativeStateAndWinsOverStaleFlag(): void
    {
        $fields = ['tx_a11y_is_decorative' => 1, 'alternative' => 'A reviewed description'];
        $this->subject()->processDatamap_preProcessFieldArray(
            $fields,
            'sys_file_reference',
            42,
            (new \ReflectionClass(DataHandler::class))->newInstanceWithoutConstructor(),
        );

        self::assertSame(0, $fields['tx_a11y_is_decorative']);
        self::assertSame('A reviewed description', $fields['alternative']);
    }

    #[Test]
    public function missingCanonicalColumnLeavesPayloadUntouched(): void
    {
        $fields = ['tx_a11y_is_decorative' => 1, 'alternative' => 'A reviewed description'];
        $schema = $this->createMock(FileReferenceSchemaServiceInterface::class);
        $schema->method('hasDecorativeColumn')->willReturn(false);

        (new FileReferenceDecorativeHook($schema))->processDatamap_preProcessFieldArray(
            $fields,
            'sys_file_reference',
            42,
            (new \ReflectionClass(DataHandler::class))->newInstanceWithoutConstructor(),
        );

        self::assertSame(1, $fields['tx_a11y_is_decorative']);
        self::assertSame('A reviewed description', $fields['alternative']);
    }

    private function subject(): FileReferenceDecorativeHook
    {
        $schema = $this->createMock(FileReferenceSchemaServiceInterface::class);
        $schema->method('hasDecorativeColumn')->willReturn(true);

        return new FileReferenceDecorativeHook($schema);
    }
}
