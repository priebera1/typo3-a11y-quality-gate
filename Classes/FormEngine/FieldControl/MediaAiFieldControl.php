<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FormEngine\FieldControl;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Media\FileMetadataRecordResolver;
use Priebera\A11yQualityGate\Ai\Media\MediaAiAvailability;
use Priebera\A11yQualityGate\Ai\Media\MediaAiFieldPolicy;
use Priebera\A11yQualityGate\Ai\Media\MediaAiLabels;
use Priebera\A11yQualityGate\Ai\Media\MediaAiSiteResolver;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The AQG wand beside an image text field in FormEngine (UX Atlas screens 04 A and 05 A): the alternative text, title
 * and description of file metadata (the file default), and the alternative text of one image use
 * (sys_file_reference). The wand only opens a suggestion; "Apply to field" fills the form field, and TYPO3 Save
 * stores it with the normal permissions. No wand when AI is not available, the field is read-only or the record
 * is not an image.
 */
final class MediaAiFieldControl extends AbstractNode
{
    public function render(): array
    {
        $table = (string)($this->data['tableName'] ?? '');
        $field = (string)($this->data['fieldName'] ?? '');
        $parameterArray = is_array($this->data['parameterArray'] ?? null) ? $this->data['parameterArray'] : [];
        $record = is_array($this->data['databaseRow'] ?? null) ? $this->data['databaseRow'] : [];
        if ((bool)($parameterArray['fieldConf']['config']['readOnly'] ?? false)) {
            return [];
        }

        try {
            $configuration = match ($table) {
                Tables::SYS_FILE_REFERENCE => $field === MediaAiFieldPolicy::FIELD_ALTERNATIVE ? $this->referenceConfiguration($record) : null,
                Tables::SYS_FILE_METADATA => MediaAiFieldPolicy::isMetadataField($field) ? $this->metadataConfiguration($record) : null,
                default => null,
            };
        } catch (\Throwable) {
            $configuration = null;
        }
        if ($configuration === null) {
            return [];
        }

        $label = MediaAiLabels::label($table === Tables::SYS_FILE_REFERENCE ? 'wandReference' : 'wand_' . $field);
        $result = $this->initializeResultArray();
        $result['iconIdentifier'] = 'a11y-quality-gate-ai-wand';
        $result['title'] = $label;
        $result['linkAttributes'] = [
            'class' => 'aqg-media-wand t3js-aqg-media-wand',
            'role' => 'button',
            'aria-label' => $label,
            'aria-haspopup' => 'dialog',
            'aria-expanded' => 'false',
            'data-aqg-media-field' => $field,
            'data-aqg-media-table' => $table,
            'data-aqg-media-input' => (string)($parameterArray['itemFormElName'] ?? ''),
            'data-aqg-media' => (string)json_encode($configuration, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'data-aqg-media-labels' => (string)json_encode(MediaAiLabels::translated(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        $result['javaScriptModules'][] = JavaScriptModuleInstruction::create('@priebera/a11y-quality-gate/media-ai/media-ai-field.js');
        $result['stylesheetFiles'][] = 'EXT:a11y_quality_gate/Resources/Public/Css/media-ai.css';

        return $result;
    }

    /** @param array<string,mixed> $record @return array<string,mixed>|null */
    private function referenceConfiguration(array $record): ?array
    {
        $parentTable = (string)($this->data['inlineParentTableName'] ?? '');
        $parentField = (string)($this->data['inlineParentFieldName'] ?? '');
        $parentUid = $this->data['inlineParentUid'] ?? 0;
        if ($parentTable !== Tables::TT_CONTENT || $parentField === '') {
            return null;
        }
        $fileUid = $this->fileUid($record['uid_local'] ?? 0);
        $pageUid = (int)($this->data['effectivePid'] ?? 0);
        $ai = GeneralUtility::makeInstance(MediaAiAvailability::class)->forPage($pageUid);
        if (($ai['state'] ?? '') !== EditorAiAccessService::STATE_READY || $fileUid <= 0) {
            return null;
        }
        $referenceUid = $record['uid'] ?? 0;
        $languageUid = (int)$this->scalar($record['sys_language_uid'] ?? 0);
        $resolver = GeneralUtility::makeInstance(FileMetadataRecordResolver::class);
        $metadata = $resolver->find($fileUid, max(0, $languageUid)) ?? ($languageUid > 0 ? $resolver->find($fileUid, 0) : null);

        return [
            'mode' => 'reference',
            'hasFileDefault' => trim((string)($metadata['values']['alternative'] ?? '')) !== '',
            // FileReferenceAltRule: a missing text is Critical, a Warning while a title (own or file default) exists.
            'hasFileDefaultTitle' => trim((string)($metadata['values']['title'] ?? '')) !== '',
            'parentTable' => $parentTable,
            'parentUid' => is_numeric($parentUid) ? (int)$parentUid : 0,
            'parentField' => $parentField,
            'referenceUid' => is_numeric($referenceUid) ? (int)$referenceUid : 0,
            'fileUid' => $fileUid,
            'languageUid' => $languageUid,
            'translation' => $this->isTranslation($record, $languageUid),
            'decorativeField' => isset($GLOBALS['TCA'][Tables::SYS_FILE_REFERENCE]['columns']['tx_a11y_is_decorative']),
            'ai' => $this->aiView($ai),
        ];
    }

    /** @param array<string,mixed> $record @return array<string,mixed>|null */
    private function metadataConfiguration(array $record): ?array
    {
        $fileUid = $this->fileUid($record['file'] ?? 0);
        $languageUid = (int)$this->scalar($record['sys_language_uid'] ?? 0);
        if ($fileUid <= 0) {
            return null;
        }
        $availability = GeneralUtility::makeInstance(MediaAiAvailability::class);
        if (!$availability->forFilelist()) {
            return null;
        }
        $uses = GeneralUtility::makeInstance(FileMetadataRecordResolver::class)->uses($fileUid, max(0, $languageUid));
        $sites = GeneralUtility::makeInstance(MediaAiSiteResolver::class)->candidates(array_column($uses['items'], 'pageUid'));
        $ready = array_values(array_filter($sites, static fn (array $site): bool => $site['state'] === EditorAiAccessService::STATE_READY));
        if ($ready === []) {
            return null;
        }
        $recordUid = $record['uid'] ?? 0;

        return [
            'mode' => 'metadata',
            'metadataUid' => is_numeric($recordUid) ? (int)$recordUid : 0,
            'fileUid' => $fileUid,
            'languageUid' => max(0, $languageUid),
            'translation' => $this->isTranslation($record, $languageUid),
            'uses' => ['total' => $uses['total'], 'inheriting' => $uses['inheriting'], 'overriding' => $uses['overriding'], 'decorative' => $uses['decorative'], 'hidden' => $uses['hidden']],
            'sites' => array_map(fn (array $site): array => ['identifier' => $site['identifier'], 'label' => $site['label']] + $this->aiView($site), $ready),
        ];
    }

    /** @param array<string,mixed> $ai @return array<string,mixed> */
    private function aiView(array $ai): array
    {
        return [
            'site' => (string)($ai['site'] ?? $ai['identifier'] ?? ''),
            'providerLabel' => (string)($ai['providerLabel'] ?? ''),
            'modelLabel' => (string)($ai['modelLabel'] ?? ''),
            'allowImages' => (bool)($ai['allowImages'] ?? false),
        ];
    }

    /** sys_file uid from a FormEngine group/select value (int, "sys_file_12", list or item array). */
    private function fileUid(mixed $value): int
    {
        if (is_array($value)) {
            $first = reset($value);
            if (is_array($first)) {
                return (int)($first['uid'] ?? 0);
            }
            return $this->fileUid($first === false ? 0 : $first);
        }
        $value = (string)$value;
        if (preg_match('/(\d+)/', $value, $match) === 1) {
            return (int)$match[1];
        }

        return 0;
    }

    /**
     * Whether the record translates a default-language record. FormEngine turns l10n_parent into a relation structure
     * whose first element is not the uid (the "Translate from the default language" action never showed); it sets
     * defaultLanguageRow exactly for such a translation, so that is the signal, the raw pointer only a fallback.
     *
     * @param array<string,mixed> $record
     */
    private function isTranslation(array $record, int $languageUid): bool
    {
        if ($languageUid <= 0) {
            return false;
        }
        if (is_array($this->data['defaultLanguageRow'] ?? null) && $this->data['defaultLanguageRow'] !== []) {
            return true;
        }
        $pointer = $record['l10n_parent'] ?? 0;

        return is_numeric($pointer) && (int)$pointer > 0;
    }

    private function scalar(mixed $value): mixed
    {
        while (is_array($value)) {
            $value = reset($value);
        }

        return $value === false ? 0 : $value;
    }
}
