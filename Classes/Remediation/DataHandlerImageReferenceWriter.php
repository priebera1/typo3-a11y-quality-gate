<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

use Priebera\A11yQualityGate\Remediation\Contract\ImageReferenceWriterInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class DataHandlerImageReferenceWriter implements ImageReferenceWriterInterface
{
    public function write(ImageFindingContext $context, array $values): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['sys_file_reference' => [$context->fileReferenceUid => $values]], []);
        $dataHandler->process_datamap();
        if ($dataHandler->errorLog !== []) {
            throw new ImageRemediationWriteException('image_update_failed');
        }
    }
}
