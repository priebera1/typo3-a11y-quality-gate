<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

use Priebera\A11yQualityGate\Ai\Service\AiImagePayloadBuilder;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;

/**
 * Reads the image an <img> in editor content shows, so it can be sent for an alt-text suggestion. Only files of a real
 * TYPO3 storage are read: AQG never downloads a URL named in content (no server-side request to hosts an editor
 * typed). Processed images are traced back to their original file.
 *
 * Editors often have no file mount for the folder of an image that is embedded in content they may edit. FAL's read
 * permission is therefore relaxed for exactly one case: a file of a public storage that the saved version of this
 * field already shows (placed there by someone allowed to). Any other image (typed into the source, or added in this
 * editing session) needs TYPO3's normal read permission of the current user. A TYPO3 file uid must belong to the
 * image the src shows, so the description is always of the image the editor sees.
 */
final class EditorAiImageResolver
{
    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly StorageRepository $storageRepository,
        private readonly ProcessedFileRepository $processedFileRepository,
        private readonly AiImagePayloadBuilder $payloadBuilder,
    ) {}

    /** The image can be read and sent. */
    public const READABLE = '';
    /** The src names another host, a data: URL or anything else AQG never fetches. */
    public const EXTERNAL = 'image_external';
    /** A local path that no TYPO3 file storage contains. */
    public const NOT_IN_STORAGE = 'image_not_in_storage';
    /** The storage has no file at this path (also an indexed file that is marked missing). */
    public const MISSING = 'image_missing';
    /** The file exists but is not an image. */
    public const NOT_AN_IMAGE = 'image_not_image';
    /** The current user may not read the file, or a TYPO3 file uid does not belong to the src. */
    public const NO_ACCESS = 'image_no_access';
    /** The file exists and may be read, but could not be prepared (unsupported format, corrupt, too large). */
    public const UNREADABLE = 'image_unreadable';

    /**
     * @param array<string,string> $attributes of the <img>
     * @return string|null a base64 data URL of the prepared image, or null when the image cannot be read safely
     */
    public function imageDataUrl(array $attributes, EditorAiRecordContext $context, string $storedHtml = ''): ?string
    {
        return $this->read($attributes, $context, $storedHtml, true)['dataUrl'];
    }

    /**
     * Why this image cannot be sent, without preparing it (no image processing, no provider request): one of the
     * constants above, READABLE ('') when it can. The same storage, permission and identity checks as
     * imageDataUrl() apply, in the same order, so a file the user may not read is reported as NO_ACCESS, never as
     * MISSING: the answer does not reveal whether a file the user cannot read exists.
     *
     * @param array<string,string> $attributes of the <img>
     */
    public function availability(array $attributes, EditorAiRecordContext $context, string $storedHtml = ''): string
    {
        return $this->read($attributes, $context, $storedHtml, false)['reason'];
    }

    /**
     * Why imageDataUrl() returned null for this image (also preparation failures), READABLE when it would not.
     *
     * @param array<string,string> $attributes of the <img>
     */
    public function failureReason(array $attributes, EditorAiRecordContext $context, string $storedHtml = ''): string
    {
        return $this->read($attributes, $context, $storedHtml, true)['reason'];
    }

    /**
     * @param array<string,string> $attributes
     * @return array{reason:string,dataUrl:?string}
     */
    private function read(array $attributes, EditorAiRecordContext $context, string $storedHtml, bool $prepare): array
    {
        $refused = static fn (string $reason): array => ['reason' => $reason, 'dataUrl' => null];
        $fileUid = (int)($attributes['data-htmlarea-file-uid'] ?? 0);
        if ($fileUid <= 0 && $this->localPath((string)($attributes['src'] ?? '')) === '') {
            return $refused(self::EXTERNAL);
        }
        [$storage, $identifier] = $this->locate($attributes);
        if (!$storage instanceof ResourceStorage) {
            // An unknown file uid is reported as missing only for the image the saved content already shows; for any
            // other uid the answer must not tell whether such a file exists.
            return $refused($fileUid > 0 ? ($this->storedHtmlShows($storedHtml, $attributes) ? self::MISSING : self::NO_ACCESS) : self::NOT_IN_STORAGE);
        }
        if ($storage->getUid() <= 0) {
            // The implicit fallback storage (the whole public directory) is never read.
            return $refused(self::NOT_IN_STORAGE);
        }

        $evaluatePermissions = $storage->getEvaluatePermissions();
        $alreadyPublished = $storage->isPublic() && $this->storedHtmlShows($storedHtml, $attributes);
        if ($alreadyPublished) {
            $storage->setEvaluatePermissions(false);
        }
        try {
            if ($fileUid <= 0) {
                // Without the relaxation above, TYPO3's read permission for the image's folder (file mounts, folder
                // permissions) comes first, so a person who may not read the folder never learns whether a file exists.
                if (!$alreadyPublished && !str_contains($identifier, '/_processed_/') && !$this->mayReadFolderOf($storage, $identifier)) {
                    return $refused(self::NO_ACCESS);
                }
                try {
                    if (!str_contains($identifier, '/_processed_/') && !$storage->hasFile($identifier)) {
                        return $refused(self::MISSING);
                    }
                } catch (\TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException|\TYPO3\CMS\Core\Resource\Exception\InsufficientFolderReadPermissionsException) {
                    return $refused(self::NO_ACCESS);
                }
            }
            try {
                $file = $fileUid > 0 ? $this->resourceFactory->getFileObject($fileUid) : $this->original($storage, $identifier);
            } catch (\TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException|\TYPO3\CMS\Core\Resource\Exception\InsufficientFileAccessPermissionsException) {
                return $refused(self::NO_ACCESS);
            } catch (\Throwable) {
                return $refused($alreadyPublished ? self::MISSING : self::NO_ACCESS);
            }
            if (!$file instanceof File || $file->getStorage()->getUid() !== $storage->getUid()) {
                // Unknown processed variants and foreign files: precise only where the relaxation applies.
                return $refused($alreadyPublished ? self::MISSING : self::NO_ACCESS);
            }
            // Read permission before any existence answer (file uids and processed paths skip the folder check above).
            if (!$alreadyPublished && !$file->checkActionPermission('read')) {
                return $refused(self::NO_ACCESS);
            }
            if ($file->isDeleted()) {
                return $refused(self::MISSING);
            }
            // A TYPO3 RTE image carries its file uid; it must be the file its src shows.
            if ($fileUid > 0 && !$this->sameFile($file, (string)($attributes['src'] ?? ''))) {
                return $refused(self::NO_ACCESS);
            }
            try {
                if ($file->isMissing() || !$file->exists()) {
                    return $refused(self::MISSING);
                }
            } catch (\TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException|\TYPO3\CMS\Core\Resource\Exception\InsufficientFolderReadPermissionsException) {
                return $refused(self::NO_ACCESS);
            }
            if (!str_starts_with($file->getMimeType(), 'image/')) {
                return $refused(self::NOT_AN_IMAGE);
            }
            if (!$prepare) {
                return ['reason' => self::READABLE, 'dataUrl' => null];
            }
            try {
                return ['reason' => self::READABLE, 'dataUrl' => $this->payloadBuilder->build(new ImageFindingContext(
                    issue: [],
                    fileReference: [],
                    siteIdentifier: $context->siteIdentifier,
                    pageUid: $context->pageUid,
                    languageUid: $context->languageUid,
                    sourceTable: $context->table,
                    sourceUid: $context->uid,
                    sourceField: $context->field,
                    fileReferenceUid: 0,
                    fileUid: $file->getUid(),
                    fingerprint: '',
                    issueTimestamp: 0,
                    fileReferenceTimestamp: 0,
                ))->dataUrl];
            } catch (\Throwable) {
                return $refused(self::UNREADABLE);
            }
        } catch (\Throwable) {
            return $refused(self::UNREADABLE);
        } finally {
            $storage->setEvaluatePermissions($evaluatePermissions);
        }
    }

    private function mayReadFolderOf(ResourceStorage $storage, string $identifier): bool
    {
        $folderIdentifier = rtrim(str_replace('\\', '/', dirname('/' . ltrim($identifier, '/'))), '/') . '/';
        try {
            return $storage->checkFolderActionPermission('read', new Folder($storage, $folderIdentifier, basename($folderIdentifier)));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,string> $attributes */
    private function storedHtmlShows(string $storedHtml, array $attributes): bool
    {
        $src = trim((string)($attributes['src'] ?? ''));
        if ($storedHtml === '' || $src === '') {
            return false;
        }
        $decoded = html_entity_decode($storedHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return str_contains($decoded, 'src="' . $src . '"') || str_contains($decoded, "src='" . $src . "'");
    }

    private function sameFile(File $file, string $src): bool
    {
        $srcPath = $this->localPath($src);
        $publicUrl = (string)($file->getPublicUrl() ?? '');
        $filePath = $this->localPath($publicUrl);
        if ($srcPath === '' || $filePath === '') {
            return false;
        }
        if ($srcPath === $filePath) {
            return true;
        }
        // RTE images often show a processed variant of the file.
        if (str_contains($srcPath, '/_processed_/')) {
            [$storage, $identifier] = $this->locate(['src' => $src]);
            $original = $storage instanceof ResourceStorage ? $this->original($storage, $identifier) : null;
            return $original instanceof File && $original->getUid() === $file->getUid();
        }

        return false;
    }

    /** @return array{0:?ResourceStorage,1:string,2:int} storage, file identifier and (for TYPO3 RTE images) file uid */
    private function locate(array $attributes): array
    {
        $fileUid = (int)($attributes['data-htmlarea-file-uid'] ?? 0);
        try {
            if ($fileUid > 0) {
                return [$this->resourceFactory->getFileObject($fileUid)->getStorage(), '', $fileUid];
            }
            $path = $this->localPath((string)($attributes['src'] ?? ''));
            if ($path === '') {
                return [null, '', 0];
            }
            $identifier = $path;
            $storage = $this->storageRepository->getStorageObject(0, [], $identifier);

            return [$storage, $identifier, 0];
        } catch (\Throwable) {
            return [null, '', 0];
        }
    }

    private function original(ResourceStorage $storage, string $identifier): ?File
    {
        if (str_contains($identifier, '/_processed_/')) {
            $processed = $this->processedFileRepository->findByStorageAndIdentifier($storage, $identifier);
            return $processed?->getOriginalFile();
        }
        $file = $storage->getFile($identifier);

        return $file instanceof File ? $file : null;
    }

    /** A path below the public directory, or '' for anything that would need a request to another host. */
    private function localPath(string $src): string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '' || str_starts_with($src, '//') || preg_match('/^[a-z][a-z0-9+.-]*:/i', $src) === 1) {
            return '';
        }
        $path = rawurldecode(ltrim((string)strtok($src, '?#'), '/'));
        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            return '';
        }

        return $path;
    }
}
