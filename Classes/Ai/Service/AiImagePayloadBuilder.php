<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiProcessedImageMaterializerInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiImagePayload;
use Priebera\A11yQualityGate\Ai\Exception\AiImagePayloadException;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Service\ImageService;

final class AiImagePayloadBuilder
{
    public const MAX_ORIGINAL_BYTES = 25_000_000;
    public const MAX_PAYLOAD_BYTES = 2_000_000;
    public const MAX_PAYLOAD_PIXELS = 4_000_000;
    public const MAX_LONGEST_EDGE = 1600;

    /** @var list<int> */
    public const PROCESSING_EDGE_CANDIDATES = [1600, 1280, 1024];

    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ImageService $imageService,
        private readonly AiProcessedImageMaterializerInterface $processedImageMaterializer,
    ) {}

    public function build(ImageFindingContext $context): AiImagePayload
    {
        $baseDiagnostics = [
            'findingUid' => (int)($context->issue['uid'] ?? 0),
            'fileReferenceUid' => $context->fileReferenceUid,
            'originalFileUid' => $context->fileUid,
        ];

        $file = $this->resolveOriginalFile($context, $baseDiagnostics);

        $baseDiagnostics['originalFileUid'] = $file->getUid();
        $mimeType = strtolower(trim($file->getMimeType()));
        if (!in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            throw new AiImagePayloadException(
                'Only JPEG, PNG and WebP images are supported for AI suggestions.',
                1771002201,
                null,
                $baseDiagnostics,
            );
        }

        $originalSize = $file->getSize();
        if ($originalSize <= 0 || $originalSize > self::MAX_ORIGINAL_BYTES) {
            throw new AiImagePayloadException(
                'The original image exceeds the safe processing limit.',
                1771002202,
                null,
                $baseDiagnostics + ['processedByteSize' => $originalSize],
            );
        }

        // Avoid a derivative when the original already satisfies every payload boundary.
        if ($originalSize <= self::MAX_PAYLOAD_BYTES) {
            try {
                $originalContents = $file->getContents();
                $originalInspection = $this->inspectPayload($originalContents);
                if ($originalInspection['valid'] || $this->canUseOriginalPayload($originalInspection)) {
                    return $this->createPayload($originalContents, $originalInspection['mimeType']);
                }
            } catch (\Throwable) {
                // Continue with FAL processing. The final exception contains safe diagnostics.
            }
        }

        $lastDiagnostics = $baseDiagnostics;
        $lastException = null;
        $lastErrorCode = 1771002203;

        foreach (self::PROCESSING_EDGE_CANDIDATES as $edge) {
            $payload = $this->buildProcessedPayload(
                $file,
                $edge,
                $baseDiagnostics,
                $lastDiagnostics,
                $lastException,
                $lastErrorCode,
            );
            if ($payload instanceof AiImagePayload) {
                return $payload;
            }
        }

        throw new AiImagePayloadException(
            'The image could not be prepared safely for AI processing.',
            $lastErrorCode,
            $lastException,
            $lastDiagnostics,
        );
    }

    /**
     * Resolve the original FAL file by sys_file.uid first. This keeps TYPO3 13/14 image
     * preparation independent from display/public paths such as fileadmin/user_upload/foo.jpg.
     * The file UID comes from sys_file_reference.uid_local and lets ResourceFactory use the
     * real storage + identifier tuple, for example storage=1 and /user_upload/foo.jpg.
     *
     * @param array<string, bool|int|string|null> $baseDiagnostics
     */
    private function resolveOriginalFile(ImageFindingContext $context, array $baseDiagnostics): File
    {
        $fileObjectException = null;

        if ($context->fileUid > 0) {
            try {
                return $this->resourceFactory->getFileObject($context->fileUid);
            } catch (\Throwable $exception) {
                $fileObjectException = $exception;
            }
        }

        try {
            $fileReference = $this->resourceFactory->getFileReferenceObject($context->fileReferenceUid);
            return $fileReference->getOriginalFile();
        } catch (\Throwable $exception) {
            throw new AiImagePayloadException(
                'The selected image is no longer available.',
                1771002200,
                $fileObjectException ?? $exception,
                $baseDiagnostics + [
                    'fileUidResolutionAttempted' => $context->fileUid > 0,
                    'fileReferenceUidResolutionAttempted' => $context->fileReferenceUid > 0,
                ],
            );
        }
    }

    /**
     * @param array<string, bool|int|string|null> $baseDiagnostics
     * @param array<string, bool|int|string|null> $lastDiagnostics
     */
    private function buildProcessedPayload(
        File $file,
        int $edge,
        array $baseDiagnostics,
        array &$lastDiagnostics,
        ?\Throwable &$lastException,
        int &$lastErrorCode,
    ): ?AiImagePayload {
        $instructions = [
            'width' => $edge . 'm',
            'height' => $edge . 'm',
        ];

        try {
            // Use the original File explicitly. TYPO3 can accept a FileReference too, but the AI
            // payload is based on the original resource plus server-derived reference context.
            $processedFile = $this->imageService->applyProcessingInstructions($file, $instructions);
        } catch (\Throwable $exception) {
            $lastException = $exception;
            $lastErrorCode = 1771002203;
            $lastDiagnostics = $baseDiagnostics + [
                'requestedDimensions' => $edge . 'x' . $edge,
                'processingAttempt' => 1,
                'forcedProcessingAttempted' => false,
                'processedResourceAvailable' => false,
                'processedResourceReadable' => false,
            ];
            return null;
        }

        $diagnostics = $this->processedDiagnostics($processedFile, $edge, 1, false, $baseDiagnostics);
        $lastDiagnostics = $diagnostics;

        if ($processedFile->usesOriginalFile()) {
            return $this->readOriginalResult(
                $file,
                $diagnostics,
                $lastDiagnostics,
                $lastException,
                $lastErrorCode,
            );
        }

        $recoveryAttempted = false;
        while (true) {
            [$completed, $available] = $this->processedState($processedFile, $lastException);
            $recoveryDiagnostics = $this->recoveryDiagnostics($lastDiagnostics);
            $diagnostics = $this->processedDiagnostics(
                $processedFile,
                $edge,
                $recoveryAttempted ? 2 : 1,
                $recoveryAttempted,
                $baseDiagnostics,
            );
            $lastDiagnostics = $diagnostics + $recoveryDiagnostics + [
                'processedCompleted' => $completed,
                'processedResourceAvailable' => $available,
                'processedResourceReadable' => false,
            ];

            if (!$completed || !$available) {
                $lastErrorCode = 1771002203;
                if ($recoveryAttempted) {
                    return null;
                }
                $processedFile = $this->recoverProcessedFile(
                    $file,
                    $instructions,
                    $processedFile,
                    $lastDiagnostics,
                    $lastException,
                );
                $recoveryAttempted = true;
                if (!$processedFile instanceof ProcessedFile) {
                    return null;
                }
                if ($processedFile->usesOriginalFile()) {
                    return $this->readOriginalResult(
                        $file,
                        $this->processedDiagnostics($processedFile, $edge, 2, true, $baseDiagnostics)
                            + $this->recoveryDiagnostics($lastDiagnostics),
                        $lastDiagnostics,
                        $lastException,
                        $lastErrorCode,
                    );
                }
                continue;
            }

            try {
                $contents = $processedFile->getContents();
            } catch (\Throwable $exception) {
                $lastException = $exception;
                $lastErrorCode = 1771002203;
                if ($recoveryAttempted) {
                    return null;
                }
                $processedFile = $this->recoverProcessedFile(
                    $file,
                    $instructions,
                    $processedFile,
                    $lastDiagnostics,
                    $lastException,
                    true,
                );
                $recoveryAttempted = true;
                if (!$processedFile instanceof ProcessedFile) {
                    return null;
                }
                continue;
            }

            $inspection = $this->inspectPayload($contents);
            $lastDiagnostics = $diagnostics + $recoveryDiagnostics + [
                'processedCompleted' => true,
                'processedResourceAvailable' => true,
                'processedResourceReadable' => true,
                'processedByteSize' => strlen($contents),
                'detectedMimeType' => $inspection['mimeType'],
                'detectedDimensions' => $inspection['width'] . 'x' . $inspection['height'],
            ];

            if ($inspection['valid']) {
                return $this->createPayload($contents, $inspection['mimeType']);
            }

            // A valid image that is still too large should be regenerated at 1280px and then
            // 1024px. Repeating the same dimensions cannot reduce its payload size.
            if (in_array($inspection['reason'], ['too_large', 'pixel_limit', 'edge_limit'], true)) {
                $lastErrorCode = 1771002204;
                return null;
            }

            $lastErrorCode = 1771002205;
            if ($recoveryAttempted) {
                return null;
            }

            // Empty, malformed or unsupported output is a broken processed resource. Invalidate
            // it through FAL and force exactly one synchronous processing attempt for this edge.
            $processedFile = $this->recoverProcessedFile(
                $file,
                $instructions,
                $processedFile,
                $lastDiagnostics,
                $lastException,
                true,
            );
            $recoveryAttempted = true;
            if (!$processedFile instanceof ProcessedFile) {
                return null;
            }
        }
    }

    /**
     * @param array<string, bool|int|string|null> $diagnostics
     * @param array<string, bool|int|string|null> $lastDiagnostics
     */
    private function readOriginalResult(
        File $file,
        array $diagnostics,
        array &$lastDiagnostics,
        ?\Throwable &$lastException,
        int &$lastErrorCode,
    ): ?AiImagePayload {
        try {
            $contents = $file->getContents();
            $inspection = $this->inspectPayload($contents);
            $lastDiagnostics = $diagnostics + [
                'processedCompleted' => true,
                'processedResourceAvailable' => $file->exists(),
                'processedResourceReadable' => true,
                'processedByteSize' => strlen($contents),
                'detectedMimeType' => $inspection['mimeType'],
                'detectedDimensions' => $inspection['width'] . 'x' . $inspection['height'],
            ];

            if ($inspection['valid']) {
                return $this->createPayload($contents, $inspection['mimeType']);
            }

            $lastErrorCode = in_array($inspection['reason'], ['too_large', 'pixel_limit', 'edge_limit'], true)
                ? 1771002204
                : 1771002205;
        } catch (\Throwable $exception) {
            $lastException = $exception;
            $lastErrorCode = 1771002203;
            $lastDiagnostics = $diagnostics + [
                'processedCompleted' => true,
                'processedResourceAvailable' => $this->safeFileExists($file),
                'processedResourceReadable' => false,
            ];
        }

        return null;
    }

    /**
     * Performs exactly one synchronous Core materialization attempt.
     *
     * A deferred or missing processed resource is materialized in place by its persisted UID.
     * TYPO3's ImageProcessingService is designed for this state and may legitimately return the
     * same numeric UID. A physically present but unreadable/corrupt result is invalidated through
     * FAL first, then its processing request is resolved again before materialization.
     *
     * @param array<string, string> $instructions
     * @param array<string, bool|int|string|null> $lastDiagnostics
     */
    private function recoverProcessedFile(
        File $file,
        array $instructions,
        ProcessedFile $processedFile,
        array &$lastDiagnostics,
        ?\Throwable &$lastException,
        bool $invalidateExistingResource = false,
    ): ?ProcessedFile {
        $staleProcessedUid = $this->safeProcessedUid($processedFile);
        $lastDiagnostics['processedUid'] = $staleProcessedUid;
        $lastDiagnostics['materializationInputUid'] = $staleProcessedUid;
        $lastDiagnostics['forcedProcessingAttempted'] = true;
        $lastDiagnostics['falInvalidationAttempted'] = $invalidateExistingResource;

        $processingCandidate = $processedFile;

        if ($invalidateExistingResource) {
            try {
                $lastDiagnostics['falDeletionReportedSuccess'] = !$processedFile->usesOriginalFile()
                    && $processedFile->delete(true);
            } catch (\Throwable $exception) {
                $lastException = $exception;
                return null;
            }

            try {
                $processingCandidate = $this->imageService->applyProcessingInstructions($file, $instructions);
            } catch (\Throwable $exception) {
                $lastException = $exception;
                return null;
            }

            $replacementUid = $this->safeProcessedUid($processingCandidate);
            $lastDiagnostics['replacementProcessedUid'] = $replacementUid;
            $lastDiagnostics['materializationInputUid'] = $replacementUid;

            if ($processingCandidate->usesOriginalFile()) {
                return $processingCandidate;
            }
        }

        $materializationUid = $this->safeProcessedUid($processingCandidate);
        if ($materializationUid <= 0) {
            // A non-persisted result cannot be materialized by the Core service. Resolve a
            // persisted processing request from the original FAL File once, without SQL/path use.
            try {
                $processingCandidate = $this->imageService->applyProcessingInstructions($file, $instructions);
            } catch (\Throwable $exception) {
                $lastException = $exception;
                return null;
            }
            $materializationUid = $this->safeProcessedUid($processingCandidate);
            $lastDiagnostics['replacementProcessedUid'] = $materializationUid;
            $lastDiagnostics['materializationInputUid'] = $materializationUid;
            if ($processingCandidate->usesOriginalFile()) {
                return $processingCandidate;
            }
        }

        if ($materializationUid <= 0) {
            return null;
        }

        try {
            // The Core service disables deferred processing, locks the persisted processing task
            // and materializes it synchronously. Missing resources are normally repaired in place,
            // therefore the returned UID may intentionally equal the stale/preprocessed UID.
            $materialized = $this->processedImageMaterializer->process($materializationUid);
            $lastDiagnostics['synchronousProcessCalls'] = 1;
            $lastDiagnostics['materializedProcessedUid'] = $this->safeProcessedUid($materialized);
            $lastDiagnostics['processedUidReused'] = $staleProcessedUid > 0
                && $staleProcessedUid === $this->safeProcessedUid($materialized);
            return $materialized;
        } catch (\Throwable $exception) {
            $lastException = $exception;
            return null;
        }
    }

    /** @return array{bool,bool} */
    private function processedState(ProcessedFile $processedFile, ?\Throwable &$lastException): array
    {
        $completed = false;
        $available = false;
        try {
            $completed = $processedFile->isProcessed();
            $available = $processedFile->exists();
        } catch (\Throwable $exception) {
            $lastException = $exception;
        }
        return [$completed, $available];
    }

    /**
     * @param array<string, bool|int|string|null> $baseDiagnostics
     * @return array<string, bool|int|string|null>
     */
    private function processedDiagnostics(
        ProcessedFile $processedFile,
        int $edge,
        int $attempt,
        bool $forcedProcessingAttempted,
        array $baseDiagnostics,
    ): array {
        $width = 0;
        $height = 0;
        $usesOriginalFile = false;
        try {
            $width = (int)$processedFile->getProperty('width');
            $height = (int)$processedFile->getProperty('height');
            $usesOriginalFile = $processedFile->usesOriginalFile();
        } catch (\Throwable) {
            // Diagnostics must not replace the original processing failure.
        }

        return $baseDiagnostics + [
            'processedUid' => $this->safeProcessedUid($processedFile),
            'usesOriginalFile' => $usesOriginalFile,
            'requestedDimensions' => $edge . 'x' . $edge,
            'reportedProcessedDimensions' => $width . 'x' . $height,
            'processingAttempt' => $attempt,
            'forcedProcessingAttempted' => $forcedProcessingAttempted,
        ];
    }

    /**
     * @param array<string, bool|int|string|null> $diagnostics
     * @return array<string, bool|int|string|null>
     */
    private function recoveryDiagnostics(array $diagnostics): array
    {
        return array_intersect_key($diagnostics, [
            'replacementProcessedUid' => true,
            'materializationInputUid' => true,
            'materializedProcessedUid' => true,
            'processedUidReused' => true,
            'falInvalidationAttempted' => true,
            'falDeletionReportedSuccess' => true,
            'synchronousProcessCalls' => true,
        ]);
    }

    private function safeProcessedUid(ProcessedFile $processedFile): int
    {
        try {
            return $processedFile->getUid();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function safeFileExists(File $file): bool
    {
        try {
            return $file->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array{valid:bool,mimeType:string,width:int,height:int,reason:string} $inspection */
    private function canUseOriginalPayload(array $inspection): bool
    {
        return $inspection['reason'] === 'edge_limit'
            && in_array($inspection['mimeType'], self::ALLOWED_MIME_TYPES, true)
            && $inspection['width'] > 0
            && $inspection['height'] > 0
            && ($inspection['width'] * $inspection['height']) <= self::MAX_PAYLOAD_PIXELS;
    }

    /**
     * @return array{valid:bool,mimeType:string,width:int,height:int,reason:string}
     */
    private function inspectPayload(string $contents): array
    {
        if ($contents === '') {
            return ['valid' => false, 'mimeType' => '', 'width' => 0, 'height' => 0, 'reason' => 'empty'];
        }
        if (strlen($contents) > self::MAX_PAYLOAD_BYTES) {
            return ['valid' => false, 'mimeType' => '', 'width' => 0, 'height' => 0, 'reason' => 'too_large'];
        }

        $size = @getimagesizefromstring($contents);
        if (!is_array($size)) {
            return ['valid' => false, 'mimeType' => '', 'width' => 0, 'height' => 0, 'reason' => 'invalid_image'];
        }

        $detectedMimeType = strtolower(trim((string)($size['mime'] ?? '')));
        $width = (int)($size[0] ?? 0);
        $height = (int)($size[1] ?? 0);
        if (!in_array($detectedMimeType, self::ALLOWED_MIME_TYPES, true)) {
            return ['valid' => false, 'mimeType' => $detectedMimeType, 'width' => $width, 'height' => $height, 'reason' => 'unsupported_mime'];
        }
        if ($width <= 0 || $height <= 0) {
            return ['valid' => false, 'mimeType' => $detectedMimeType, 'width' => $width, 'height' => $height, 'reason' => 'invalid_dimensions'];
        }
        if (($width * $height) > self::MAX_PAYLOAD_PIXELS) {
            return ['valid' => false, 'mimeType' => $detectedMimeType, 'width' => $width, 'height' => $height, 'reason' => 'pixel_limit'];
        }
        if (max($width, $height) > self::MAX_LONGEST_EDGE) {
            return ['valid' => false, 'mimeType' => $detectedMimeType, 'width' => $width, 'height' => $height, 'reason' => 'edge_limit'];
        }

        return [
            'valid' => true,
            'mimeType' => $detectedMimeType,
            'width' => $width,
            'height' => $height,
            'reason' => '',
        ];
    }

    private function createPayload(string $contents, string $detectedMimeType): AiImagePayload
    {
        return new AiImagePayload(
            dataUrl: 'data:' . $detectedMimeType . ';base64,' . base64_encode($contents),
            mimeType: $detectedMimeType,
        );
    }
}
