<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\FormEngine\FieldControl\MediaAiFieldControl;

/**
 * Section 11 of the Atlas correction pass: on a translated sys_file_metadata record FormEngine hands l10n_parent over
 * as a relation structure, so the wand never offered "Translate from the default language". The default-language row
 * FormEngine loads for a translation is the signal.
 */
final class MediaAiFieldControlTranslationTest extends TestCase
{
    #[Test]
    public function aTranslationIsRecognisedFromFormEnginesDefaultLanguageRow(): void
    {
        $record = ['uid' => 3, 'sys_language_uid' => [1], 'l10n_parent' => [['table' => 'sys_file_metadata', 'uid' => 2]]];

        self::assertTrue($this->isTranslation(['databaseRow' => $record, 'defaultLanguageRow' => ['uid' => 2]], $record, 1));
        self::assertTrue($this->isTranslation(['databaseRow' => ['l10n_parent' => 2]], ['l10n_parent' => 2], 1), 'raw pointer fallback');
    }

    #[Test]
    public function aDefaultLanguageOrFreeModeRecordIsNoTranslation(): void
    {
        self::assertFalse($this->isTranslation(['defaultLanguageRow' => ['uid' => 2]], [], 0));
        self::assertFalse($this->isTranslation(['databaseRow' => ['l10n_parent' => 0]], ['l10n_parent' => 0], 1));
        self::assertFalse($this->isTranslation([], ['l10n_parent' => [['table' => 'sys_file_metadata', 'uid' => 2]]], 1));
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $record */
    private function isTranslation(array $data, array $record, int $languageUid): bool
    {
        $control = (new \ReflectionClass(MediaAiFieldControl::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(MediaAiFieldControl::class, 'data'))->setValue($control, $data);

        return (new \ReflectionMethod(MediaAiFieldControl::class, 'isTranslation'))->invoke($control, $record, $languageUid);
    }
}
