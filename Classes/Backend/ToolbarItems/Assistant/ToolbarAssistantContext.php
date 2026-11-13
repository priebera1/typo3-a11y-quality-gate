<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant;

/**
 * The context the toolbar assistant analyses, resolved and permission-checked by ToolbarAssistantContextResolver.
 */
final class ToolbarAssistantContext
{
    public const TYPE_NONE = 'none';
    public const TYPE_UNAVAILABLE = 'unavailable';
    public const TYPE_PAGE = 'page';
    public const TYPE_RECORD = 'record';
    public const TYPE_FILE = 'file';
    public const TYPE_FOLDER = 'folder';

    /**
     * @param list<string> $changedFields
     */
    public function __construct(
        public readonly string $type,
        public readonly int $workspace = 0,
        public readonly string $siteIdentifier = '',
        public readonly int $pageUid = 0,
        public readonly int $languageUid = 0,
        public readonly string $table = '',
        public readonly int $recordUid = 0,
        public readonly string $recordTitle = '',
        public readonly string $recordType = '',
        public readonly string $pageTitle = '',
        public readonly int $fileUid = 0,
        public readonly string $fileName = '',
        public readonly bool $fileIsImage = false,
        public readonly string $fileAlternative = '',
        public readonly int $metadataUid = 0,
        public readonly bool $metadataEditable = false,
        public readonly bool $unsaved = false,
        public readonly array $changedFields = [],
    ) {
    }

    public static function none(int $workspace): self
    {
        return new self(self::TYPE_NONE, $workspace);
    }

    public static function unavailable(int $workspace): self
    {
        return new self(self::TYPE_UNAVAILABLE, $workspace);
    }

    public static function folder(int $workspace): self
    {
        return new self(self::TYPE_FOLDER, $workspace);
    }

    public function isEditingRecord(): bool
    {
        return $this->table !== '' && $this->recordUid > 0;
    }

    public function fieldChanged(string $field): bool
    {
        return $this->unsaved && in_array($field, $this->changedFields, true);
    }
}
