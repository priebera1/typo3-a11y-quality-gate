<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Ai\Contract\AiProcessedImageMaterializerInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Service\AiImagePayloadBuilder;
use Priebera\A11yQualityGate\Ai\Task\AiImageInput;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Extbase\Service\ImageService;

/**
 * Prepares the image of an already checked file (MediaAiFileGuard) for a provider request. Raster images go through
 * AQG's existing image payload builder (FAL original, size and pixel limits, scaled derivative). SVG is never sent as
 * SVG: TYPO3's image processing rasterises it to PNG first, and only an SVG without external references (other files,
 * URLs, entities, embedded documents) is rasterised at all; otherwise nothing is sent.
 */
final class MediaAiImageLoader
{
    private const SVG_EDGE = 1024;

    public function __construct(
        private readonly AiImagePayloadBuilder $payloadBuilder,
        private readonly ImageService $imageService,
        private readonly AiProcessedImageMaterializerInterface $processedImageMaterializer,
    ) {}

    public function load(MediaAiFile $mediaFile): AiImageInput
    {
        $file = $mediaFile->file;
        if (!$file instanceof File || !$mediaFile->isEligible()) {
            throw new EditorAiException('image_unavailable', 'The image could not be read.', 422);
        }

        try {
            if ($mediaFile->kind === MediaAiFile::KIND_SVG) {
                return AiImageInput::fromDataUrl($this->rasterisedSvg($file));
            }

            return AiImageInput::fromDataUrl($this->payloadBuilder->build(new ImageFindingContext(
                issue: [],
                fileReference: [],
                siteIdentifier: '',
                pageUid: 0,
                languageUid: 0,
                sourceTable: '',
                sourceUid: 0,
                sourceField: '',
                fileReferenceUid: 0,
                fileUid: $file->getUid(),
                fingerprint: '',
                issueTimestamp: 0,
                fileReferenceTimestamp: 0,
            ))->dataUrl);
        } catch (EditorAiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $code = in_array((int)$exception->getCode(), [1771002202, 1771002204], true) ? 'image_too_large' : 'image_unavailable';
            throw new EditorAiException($code, 'The image could not be prepared for AI.', 422, 0, $exception);
        }
    }

    /**
     * Whether an SVG refers to anything outside itself (other files, URLs, embedded documents, entities). Such an SVG is
     * never handed to the image processor: depending on the server's SVG delegate it could read local files or make
     * requests while rendering. Only same-document references (#id) are allowed.
     */
    public static function svgHasExternalReferences(string $svg): bool
    {
        if ($svg === '' || strlen($svg) > SvgTextExtractor::MAX_BYTES) {
            return true;
        }
        if (preg_match('/<!(DOCTYPE|ENTITY)|<\?xml-stylesheet|<\s*(foreignObject|script)\b|@import/i', $svg) === 1) {
            return true;
        }
        preg_match_all('/\b(?:xlink:)?href\s*=\s*(["\'])(.*?)\1|url\(\s*(["\']?)(.*?)\3\s*\)/is', $svg, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $reference = trim(($match[2] ?? '') !== '' ? $match[2] : ($match[4] ?? ''));
            if (!str_starts_with($reference, '#')) {
                return true;
            }
        }

        return false;
    }

    /** SVG labels read without AI; empty for raster images. @return list<string> */
    public function svgLabels(MediaAiFile $mediaFile, SvgTextExtractor $extractor): array
    {
        if ($mediaFile->kind !== MediaAiFile::KIND_SVG || !$mediaFile->file instanceof File) {
            return [];
        }
        try {
            return $extractor->labels($mediaFile->file->getContents());
        } catch (\Throwable) {
            return [];
        }
    }

    private function rasterisedSvg(File $file): string
    {
        if (self::svgHasExternalReferences($file->getContents())) {
            throw new EditorAiException('svg_external_references', 'This SVG refers to other files or addresses and is not converted.', 422);
        }
        $processed = $this->imageService->applyProcessingInstructions($file, [
            'width' => self::SVG_EDGE . 'm',
            'height' => self::SVG_EDGE . 'm',
            'fileExtension' => 'png',
        ]);
        if ($processed->usesOriginalFile()) {
            throw new EditorAiException('svg_not_rasterised', 'TYPO3 could not convert this SVG to PNG.', 422);
        }
        if (!$processed->isProcessed() || !$processed->exists()) {
            // TYPO3 defers image processing; materialise this one derivative synchronously (as for raster images).
            $processed = $this->processedImageMaterializer->process($processed->getUid());
        }
        $contents = $processed->getContents();
        $size = @getimagesizefromstring($contents);
        if (!is_array($size) || ($size['mime'] ?? '') !== 'image/png' || strlen($contents) > AiImagePayloadBuilder::MAX_PAYLOAD_BYTES) {
            throw new EditorAiException('svg_not_rasterised', 'TYPO3 could not convert this SVG to PNG.', 422);
        }

        return 'data:image/png;base64,' . base64_encode($contents);
    }
}
