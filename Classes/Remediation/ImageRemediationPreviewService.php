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

    /** @return array{available:bool,url:string,displayPath:string} */
    public function build(ImageFindingContext $context): array
    {
        $fallbackPath = '';

        try {
            $fileReference = $this->resourceFactory->getFileReferenceObject($context->fileReferenceUid);
            $file = $fileReference->getOriginalFile();
            $fallbackPath = $this->safePublicPath($this->imageService->getImageUri($file, false));

            if (!in_array(strtolower(trim($file->getMimeType())), self::ALLOWED_MIME_TYPES, true)) {
                return $this->fallback($fallbackPath);
            }

            $processedFile = $this->imageService->applyProcessingInstructions($fileReference, [
                'width' => '160m',
                'height' => '120m',
            ]);
            $previewUri = trim($this->imageService->getImageUri($processedFile, false));
            if ($previewUri === '') {
                return $this->fallback($fallbackPath);
            }

            $displayPath = $this->safePublicPath($previewUri);

            return [
                'available' => true,
                'url' => $previewUri,
                'displayPath' => $displayPath !== '' ? $displayPath : $fallbackPath,
            ];
        } catch (\Throwable) {
            return $this->fallback($fallbackPath);
        }
    }

    /** @return array{available:bool,url:string,displayPath:string} */
    private function fallback(string $displayPath): array
    {
        return [
            'available' => false,
            'url' => '',
            'displayPath' => $displayPath,
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
