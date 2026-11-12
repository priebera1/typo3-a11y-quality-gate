<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Hook;

use Priebera\A11yQualityGate\Remediation\Contract\FileReferenceSchemaServiceInterface;
use Priebera\A11yQualityGate\Service\EffectiveFieldConfigurationService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;

final class FileReferenceDecorativeHook
{
    public function __construct(
        private readonly FileReferenceSchemaServiceInterface $schemaService,
        private readonly ?EffectiveFieldConfigurationService $fieldConfigurationService = null,
    ) {}

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

        $stored = $this->storedReference($id);

        if ($hasNonEmptyAlternative) {
            // A reviewed manual alt always wins over a stale decorative checkbox payload — unless clearing the stored
            // decorative flag would change a field the user may not write: then the conflicting alt is not saved
            // (AQG never writes a read-only or hidden field, Sol QA-01 review).
            if ($stored !== null && (int)($stored['tx_a11y_is_decorative'] ?? 0) === 1
                && !$this->mayWrite($dataHandler, $stored, 'tx_a11y_is_decorative')) {
                unset($incomingFieldArray['alternative']);
                return;
            }
            $incomingFieldArray['tx_a11y_is_decorative'] = 0;
            return;
        }

        if (!array_key_exists('tx_a11y_is_decorative', $incomingFieldArray)) {
            return;
        }

        $isDecorative = (int)(bool)$incomingFieldArray['tx_a11y_is_decorative'];
        $incomingFieldArray['tx_a11y_is_decorative'] = $isDecorative;

        if ($isDecorative === 1) {
            if ($stored !== null && trim((string)($stored['alternative'] ?? '')) !== ''
                && !$this->mayWrite($dataHandler, $stored, 'alternative')) {
                // Marking decorative would blank a read-only alternative text: keep both as stored.
                unset($incomingFieldArray['tx_a11y_is_decorative']);
                return;
            }
            $incomingFieldArray['alternative'] = '';
        }
    }

    /** @return array<string, mixed>|null the stored reference for an existing record id */
    private function storedReference(string|int $id): ?array
    {
        if (!is_numeric($id) || (int)$id <= 0) {
            return null;
        }
        try {
            $record = BackendUtility::getRecord('sys_file_reference', (int)$id, '*');
        } catch (\Throwable) {
            // No database (unit context): DataHandler could not store the record either.
            return null;
        }

        return is_array($record) ? $record : null;
    }

    /** @param array<string, mixed> $record */
    private function mayWrite(DataHandler $dataHandler, array $record, string $field): bool
    {
        $backendUser = $dataHandler->BE_USER ?? null;
        if (!$backendUser instanceof BackendUserAuthentication) {
            return true;
        }

        return ($this->fieldConfigurationService ?? new EffectiveFieldConfigurationService())
            ->isFieldWritable($backendUser, 'sys_file_reference', $record, $field);
    }
}
