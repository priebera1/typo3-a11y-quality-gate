<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Routing\UriBuilder;

/**
 * The approved way into AI metadata for one file (UX Atlas 04 A, open decision #1 not taken): TYPO3's own metadata
 * form of the file, where the AQG wand beside each field fills the form and TYPO3 Save stores it. The link edits the
 * existing default-language record only; it never creates a record and never writes anything itself.
 */
final class MediaAiMetadataEditLink
{
    public function __construct(
        private readonly FileMetadataRecordResolver $recordResolver,
        private readonly UriBuilder $uriBuilder,
    ) {}

    /** FormEngine URL of the file's metadata record, or '' when the file has no metadata record (yet). */
    public function url(int $fileUid, string $returnUrl = ''): string
    {
        $metadataUid = (int)($this->recordResolver->find($fileUid, 0)['uid'] ?? 0);
        if ($metadataUid <= 0) {
            return '';
        }
        $parameters = ['edit' => [Tables::SYS_FILE_METADATA => [$metadataUid => 'edit']]];
        if ($returnUrl !== '') {
            $parameters['returnUrl'] = $returnUrl;
        }

        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', $parameters);
    }
}
