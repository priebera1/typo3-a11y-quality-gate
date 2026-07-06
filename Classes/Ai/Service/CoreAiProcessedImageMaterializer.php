<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiProcessedImageMaterializerInterface;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\Service\ImageProcessingService;

final class CoreAiProcessedImageMaterializer implements AiProcessedImageMaterializerInterface
{
    public function __construct(private readonly ImageProcessingService $imageProcessingService) {}

    public function process(int $processedFileUid): ProcessedFile
    {
        return $this->imageProcessingService->process($processedFileUid);
    }
}
