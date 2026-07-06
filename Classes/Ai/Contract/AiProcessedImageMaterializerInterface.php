<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Contract;

use TYPO3\CMS\Core\Resource\ProcessedFile;

interface AiProcessedImageMaterializerInterface
{
    public function process(int $processedFileUid): ProcessedFile;
}
