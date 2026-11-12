<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

/**
 * How a prepared change reaches TYPO3. Only "image_reference_alt" is written by AQG on the server (the existing
 * allow-listed DataHandler path to sys_file_reference.alternative); rich text and Plain HTML are applied by the
 * person in their editor and stored with TYPO3 Save (Atlas D3). "manual" carries guidance only.
 */
final class RemediationTargetKind
{
    public const IMAGE_REFERENCE_ALT = 'image_reference_alt';
    public const RICH_TEXT = 'rich_text';
    public const MANUAL = 'manual';

    public static function isServerWritable(string $kind): bool
    {
        return $kind === self::IMAGE_REFERENCE_ALT;
    }

    public static function isEditorApplied(string $kind): bool
    {
        return $kind === self::RICH_TEXT;
    }

    private function __construct()
    {
    }
}
