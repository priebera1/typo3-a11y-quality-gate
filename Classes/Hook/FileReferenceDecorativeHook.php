<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Hook;

use Priebera\A11yQualityGate\Remediation\Contract\FileReferenceSchemaServiceInterface;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class FileReferenceDecorativeHook
{
    public function __construct(private readonly FileReferenceSchemaServiceInterface $schemaService) {}

    /**
     * Keep the canonical decorative flag and TYPO3's alternative field consistent
     * for every DataHandler write, including FormEngine edits outside AQG.
     *
     * @param array<string,mixed> $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(
        array &$incomingFieldArray,
        string $table,
        string|int $id,
        DataHandler $dataHandler,
    ): void {
        if ($table !== 'sys_file_reference' || !$this->schemaService->hasDecorativeColumn()) {
            return;
        }

        $hasNonEmptyAlternative = array_key_exists('alternative', $incomingFieldArray)
            && trim((string)$incomingFieldArray['alternative']) !== '';

        if ($hasNonEmptyAlternative) {
            // A reviewed manual alt always wins over a stale decorative checkbox payload.
            $incomingFieldArray['tx_a11y_is_decorative'] = 0;
            return;
        }

        if (!array_key_exists('tx_a11y_is_decorative', $incomingFieldArray)) {
            return;
        }

        $isDecorative = (int)(bool)$incomingFieldArray['tx_a11y_is_decorative'];
        $incomingFieldArray['tx_a11y_is_decorative'] = $isDecorative;

        if ($isDecorative === 1) {
            $incomingFieldArray['alternative'] = '';
        }
    }
}
