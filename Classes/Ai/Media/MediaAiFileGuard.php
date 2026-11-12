<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Ai\Service\AiImagePayloadBuilder;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Service\BackendUserService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Server-side checks of one file before AI media touches it: the sys_file record and the stored file exist, it is an
 * image format AQG can send (JPEG, PNG, WebP, or SVG rasterised by TYPO3), it is within the size limit, the user may
 * read it through FAL (file mounts and storage permissions), and which allow-listed metadata fields the user may edit
 * (FAL "editMeta", table modify access and exclude fields). A file uid from the browser is only ever an identifier.
 */
final class MediaAiFileGuard
{
    public const RASTER_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    public const SVG_MIME_TYPES = ['image/svg+xml', 'image/svg'];

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly BackendUserService $backendUserService,
    ) {}

    /**
     * @param bool $placedInPublicStorage the file is already shown by a record the user may edit (FAL reference flow):
     *        FAL's read check is relaxed for files of a public storage, exactly like the editor image resolver
     */
    public function inspect(int $fileUid, bool $placedInPublicStorage = false): MediaAiFile
    {
        if ($fileUid <= 0) {
            return new MediaAiFile($fileUid, MediaAiFile::STATUS_UNAVAILABLE);
        }
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
        } catch (\Throwable) {
            return new MediaAiFile($fileUid, MediaAiFile::STATUS_UNAVAILABLE);
        }
        if (!$file instanceof File || $file->isDeleted()) {
            return new MediaAiFile($fileUid, MediaAiFile::STATUS_UNAVAILABLE);
        }

        $storage = $file->getStorage();
        $relaxRead = $placedInPublicStorage && $storage->isPublic();
        $readable = $relaxRead || $this->can($file, 'read');
        $mimeType = strtolower(trim($file->getMimeType()));
        $kind = match (true) {
            in_array($mimeType, self::RASTER_MIME_TYPES, true) => MediaAiFile::KIND_RASTER,
            in_array($mimeType, self::SVG_MIME_TYPES, true) => MediaAiFile::KIND_SVG,
            default => '',
        };
        $base = [
            'uid' => $file->getUid(),
            'kind' => $kind,
            'name' => $readable ? $file->getName() : '',
            'folderName' => $readable ? $this->folderName($file) : '',
            'mimeType' => $mimeType,
            'size' => $readable ? $file->getSize() : 0,
            'width' => $readable ? (int)$file->getProperty('width') : 0,
            'height' => $readable ? (int)$file->getProperty('height') : 0,
        ];

        if (!$readable) {
            // Nothing about a file the user cannot read: not its name, type or size, not even that it exists.
            return new MediaAiFile($fileUid, MediaAiFile::STATUS_UNAVAILABLE);
        }
        if (!$this->exists($file)) {
            return new MediaAiFile(...$base, status: MediaAiFile::STATUS_MISSING);
        }
        if ($kind === '') {
            return new MediaAiFile(...$base, status: MediaAiFile::STATUS_UNSUPPORTED);
        }
        $sizeLimit = $kind === MediaAiFile::KIND_SVG ? SvgTextExtractor::MAX_BYTES : AiImagePayloadBuilder::MAX_ORIGINAL_BYTES;
        if ($file->getSize() <= 0 || $file->getSize() > $sizeLimit) {
            return new MediaAiFile(...$base, status: MediaAiFile::STATUS_TOO_LARGE);
        }

        $editable = $this->editableFields($file);

        return new MediaAiFile(
            ...$base,
            status: MediaAiFile::STATUS_ELIGIBLE,
            editableFields: $editable,
            file: $file,
        );
    }

    /**
     * The allow-listed sys_file_metadata fields this user may change on this file: FAL's editMeta permission (storage,
     * file mounts, the file's own permissions), table modify access, the field exists in TCA and its exclude flag.
     *
     * @return list<string>
     */
    public function editableFields(File $file): array
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || !$this->can($file, 'editMeta')) {
            return [];
        }
        if (!$backendUser->isAdmin() && !$backendUser->check('tables_modify', Tables::SYS_FILE_METADATA)) {
            return [];
        }

        $fields = [];
        foreach (MediaAiFieldPolicy::METADATA_FIELDS as $field) {
            $column = $GLOBALS['TCA'][Tables::SYS_FILE_METADATA]['columns'][$field] ?? null;
            if (!is_array($column) || (bool)($column['config']['readOnly'] ?? false)) {
                continue;
            }
            if (!$backendUser->isAdmin()
                && (bool)($column['exclude'] ?? false)
                && !$backendUser->check('non_exclude_fields', Tables::SYS_FILE_METADATA . ':' . $field)) {
                continue;
            }
            $fields[] = $field;
        }

        return $fields;
    }

    private function can(File $file, string $action): bool
    {
        try {
            return $file->checkActionPermission($action);
        } catch (\Throwable) {
            return false;
        }
    }

    private function exists(File $file): bool
    {
        try {
            return !$file->isMissing() && $file->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function folderName(File $file): string
    {
        try {
            return $file->getParentFolder()->getName();
        } catch (\Throwable) {
            return '';
        }
    }
}
