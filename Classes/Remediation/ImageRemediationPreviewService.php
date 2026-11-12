<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Service\ImageService;

final class ImageRemediationPreviewService
{
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly ImageService $imageService,
    ) {}

    /**
     * `fileName` is the original file's name, the one the editor knows (U-04: never the preview URL — TYPO3 14 answers
     * "typo3/image/process?token=…" for deferred processing, 13 a "_processed_/csm_…" path). `displayPath` is the
     * original's public path, for the tooltip only.
     *
     * @return array{available:bool,url:string,displayPath:string,fileName:string}
     */
    public function build(ImageFindingContext $context): array
    {
        $fallbackPath = '';
        $fileName = '';

        try {
            $fileReference = $this->resourceFactory->getFileReferenceObject($context->fileReferenceUid);
            $file = $fileReference->getOriginalFile();
            $fileName = trim($file->getName());
            $fallbackPath = $this->safePublicPath($this->imageService->getImageUri($file, false));

            if (!in_array(strtolower(trim($file->getMimeType())), self::ALLOWED_MIME_TYPES, true)) {
                return $this->fallback($fallbackPath, $fileName);
            }

            $processedFile = $this->imageService->applyProcessingInstructions($fileReference, [
                'width' => '160m',
                'height' => '120m',
            ]);
            $previewUri = trim($this->imageService->getImageUri($processedFile, false));
            if ($previewUri === '') {
                return $this->fallback($fallbackPath, $fileName);
            }

            return [
                'available' => true,
                'url' => $previewUri,
                'displayPath' => $fallbackPath,
                'fileName' => $fileName,
            ];
        } catch (\Throwable) {
            return $this->fallback($fallbackPath, $fileName);
        }
    }

    /** @return array{available:bool,url:string,displayPath:string,fileName:string} */
    private function fallback(string $displayPath, string $fileName = ''): array
    {
        return [
            'available' => false,
            'url' => '',
            'displayPath' => $displayPath,
            'fileName' => $fileName !== '' ? $fileName : basename($displayPath),
        ];
    }

    private function safePublicPath(string $uri): string
    {
        $uri = trim($uri);
        if ($uri === '') {
            return '';
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        return ltrim(rawurldecode($path), '/');
    }
}
