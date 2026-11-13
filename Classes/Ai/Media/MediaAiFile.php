<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use TYPO3\CMS\Core\Resource\File;

/**
 * One file as AI media sees it after the server-side checks: whether it is an image AQG can send, whether the current
 * user may read it and which allow-listed metadata fields the user may edit. Never built from client data alone.
 */
final readonly class MediaAiFile
{
    public const STATUS_ELIGIBLE = 'eligible';
    public const STATUS_MISSING = 'missing';
    public const STATUS_UNSUPPORTED = 'unsupported';
    public const STATUS_TOO_LARGE = 'too_large';
    /** Not found or not readable for this user: the two are deliberately indistinguishable. */
    public const STATUS_UNAVAILABLE = 'unavailable';
    public const STATUS_READ_ONLY = 'read_only';

    public const KIND_RASTER = 'raster';
    public const KIND_SVG = 'svg';

    /** @param list<string> $editableFields */
    public function __construct(
        public int $uid,
        public string $status,
        public string $kind = '',
        public string $name = '',
        public string $folderName = '',
        public string $mimeType = '',
        public int $size = 0,
        public int $width = 0,
        public int $height = 0,
        public array $editableFields = [],
        public ?File $file = null,
    ) {}

    public function isEligible(): bool
    {
        return $this->status === self::STATUS_ELIGIBLE;
    }

    /** @return array<string,mixed> what the browser may see about the file (no storage paths, no identifiers) */
    public function toArray(): array
    {
        return [
            'uid' => $this->uid,
            'status' => $this->status,
            'kind' => $this->kind,
            'name' => $this->name,
            'mimeType' => $this->mimeType,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'editableFields' => $this->editableFields,
        ];
    }
}
