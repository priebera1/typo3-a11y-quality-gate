<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Form\Utility\FormEngineUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\ArrayUtility;

/**
 * Decides whether TYPO3 would let a backend user change a field of a stored record through FormEngine.
 *
 * DataHandler does not enforce `readOnly`, so every AQG writer (image ALT, AI fixes queue, editor AI) must refuse
 * a field that FormEngine renders read-only or does not render at all. The effective configuration mirrors
 * FormEngine: TCA column → record type `columnsOverrides` → page TSconfig `TCEFORM.<table>.<field>` (incl.
 * `types.<type>.` and user TSconfig `page.TCEFORM` overrides, only the keys FormEngine accepts) → translation
 * `l10n_mode = exclude` / `l10n_display = defaultAsReadonly` → for file references the inline parent field
 * (its own effective read-only state and `overrideChildTca`). A field the record type's form layout does not show
 * (`showitem` incl. palettes; for file references with the parent's `overrideChildTca` types and palettes) is refused
 * too. Finally TYPO3's own FormEngine pipeline must keep the field in the compiled form (FormEngineFieldVisibilityService:
 * `displayCond` and the rest of FormEngine's evaluation). Unknown configuration fails closed.
 */
final class EffectiveFieldConfigurationService
{
    private readonly FormEngineFieldVisibilityService $formEngineVisibility;

    public function __construct(?FormEngineFieldVisibilityService $formEngineVisibility = null)
    {
        $this->formEngineVisibility = $formEngineVisibility ?? new FormEngineFieldVisibilityService();
    }

    /**
     * @param array<string, mixed> $record full stored row
     */
    public function isFieldWritable(
        BackendUserAuthentication $backendUser,
        string $table,
        array $record,
        string $field,
    ): bool {
        $columnConfiguration = $this->effectiveColumn($backendUser, $table, $record, $field);
        if ($columnConfiguration === null) {
            return false;
        }

        if ((bool)($columnConfiguration['config']['readOnly'] ?? false)) {
            return false;
        }

        if ($this->isTranslationReadOnly($table, $record, $columnConfiguration)) {
            return false;
        }

        $shown = $table === Tables::SYS_FILE_REFERENCE
            ? $this->isInlineParentWritable($backendUser, $record, $field)
            : $this->isShownInLayout($GLOBALS['TCA'][$table], $this->recordType($table, $record), $field);

        // Last and authoritative: TYPO3's own form pipeline must keep the field editable in the form — this covers
        // `displayCond` (also of the parent's file field for an image reference) and everything else FormEngine
        // evaluates; it fails closed when the record cannot be compiled.
        return $shown
            && $this->formEngineVisibility->isFieldEditable($backendUser, $table, (int)($record['uid'] ?? 0), $field);
    }

    /**
     * Whether the form layout of the record type lists the field, directly or in one of its palettes.
     *
     * @param array<string, mixed> $tca the table's TCA (or an overrideChildTca-merged copy)
     */
    private function isShownInLayout(array $tca, string $type, string $field): bool
    {
        $types = is_array($tca['types'] ?? null) ? $tca['types'] : [];
        // FormEngine falls back to type "1" (then the first type) for a record type without configuration.
        $typeConfiguration = $types[$type] ?? $types['1'] ?? ($types === [] ? null : $types[array_key_first($types)]);
        if (!is_array($typeConfiguration)) {
            return false;
        }
        $palettes = is_array($tca['palettes'] ?? null) ? $tca['palettes'] : [];
        $seen = [];

        return $this->showitemContains((string)($typeConfiguration['showitem'] ?? ''), $palettes, $field, $seen);
    }

    /**
     * @param array<string, mixed> $palettes
     * @param array<string, true> $seen palettes already expanded
     */
    private function showitemContains(string $showitem, array $palettes, string $field, array &$seen): bool
    {
        foreach (explode(',', $showitem) as $item) {
            $parts = array_map('trim', explode(';', $item));
            $name = $parts[0] ?? '';
            if ($name === $field) {
                return true;
            }
            if ($name === '--palette--') {
                $palette = $parts[2] ?? '';
                if ($palette === '' || isset($seen[$palette]) || !is_array($palettes[$palette] ?? null)) {
                    continue;
                }
                $seen[$palette] = true;
                if ($this->showitemContains((string)($palettes[$palette]['showitem'] ?? ''), $palettes, $field, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null null = field hidden from FormEngine or not configured
     */
    private function effectiveColumn(
        BackendUserAuthentication $backendUser,
        string $table,
        array $record,
        string $field,
    ): ?array {
        $column = $GLOBALS['TCA'][$table]['columns'][$field] ?? null;
        if (!is_array($column) || !is_array($column['config'] ?? null)) {
            return null;
        }

        $type = $this->recordType($table, $record);
        $override = $GLOBALS['TCA'][$table]['types'][$type]['columnsOverrides'][$field] ?? null;
        if (is_array($override)) {
            ArrayUtility::mergeRecursiveWithOverrule($column, $override);
        }

        $fieldTsConfig = $this->fieldTsConfig($backendUser, $table, $record, $field, $type);
        if ((bool)($fieldTsConfig['disabled'] ?? false)) {
            return null;
        }
        if ($fieldTsConfig !== []) {
            $column['config'] = FormEngineUtility::overrideFieldConf($column['config'], $fieldTsConfig);
        }

        return $column;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed> dotted TSconfig of the field, as SingleFieldContainer passes it to overrideFieldConf()
     */
    private function fieldTsConfig(
        BackendUserAuthentication $backendUser,
        string $table,
        array $record,
        string $field,
        string $type,
    ): array {
        $pageUid = $this->tsConfigPageUid($table, $record);
        $pageTsConfig = $pageUid > 0 ? BackendUtility::getPagesTSconfig($pageUid) : [];
        $userPageTsConfig = $backendUser->getTSConfig()['page.'] ?? [];
        if (is_array($userPageTsConfig) && $userPageTsConfig !== []) {
            ArrayUtility::mergeRecursiveWithOverrule($pageTsConfig, $userPageTsConfig);
        }

        $fieldConfiguration = $pageTsConfig['TCEFORM.'][$table . '.'][$field . '.'] ?? [];
        if (!is_array($fieldConfiguration)) {
            return [];
        }
        $typeConfiguration = $fieldConfiguration['types.'][$type . '.'] ?? null;
        unset($fieldConfiguration['types.']);
        if (is_array($typeConfiguration)) {
            ArrayUtility::mergeRecursiveWithOverrule($fieldConfiguration, $typeConfiguration);
        }

        return $fieldConfiguration;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $columnConfiguration
     */
    private function isTranslationReadOnly(string $table, array $record, array $columnConfiguration): bool
    {
        $languageField = (string)($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');
        $parentField = (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');
        if ($languageField === '' || $parentField === ''
            || (int)($record[$languageField] ?? 0) <= 0
            || (int)($record[$parentField] ?? 0) <= 0) {
            return false;
        }

        if (($columnConfiguration['l10n_mode'] ?? '') === 'exclude') {
            return true;
        }

        return str_contains((string)($columnConfiguration['l10n_display'] ?? ''), 'defaultAsReadonly');
    }

    /** @param array<string, mixed> $reference */
    private function isInlineParentWritable(
        BackendUserAuthentication $backendUser,
        array $reference,
        string $field,
    ): bool {
        $parentTable = (string)($reference['tablenames'] ?? '');
        $parentField = (string)($reference['fieldname'] ?? '');
        $parentUid = (int)($reference['uid_foreign'] ?? 0);
        if ($parentTable === '' || $parentField === '' || $parentUid <= 0 || !isset($GLOBALS['TCA'][$parentTable])) {
            return false;
        }

        $parent = BackendUtility::getRecord($parentTable, $parentUid, '*');
        if (!is_array($parent) || (int)($parent['deleted'] ?? 0) === 1) {
            return false;
        }

        $parentColumn = $this->effectiveColumn($backendUser, $parentTable, $parent, $parentField);
        if ($parentColumn === null || (bool)($parentColumn['config']['readOnly'] ?? false)) {
            return false;
        }

        $overrideChildTca = $parentColumn['config']['overrideChildTca'] ?? [];
        if (!is_array($overrideChildTca)) {
            $overrideChildTca = [];
        }
        // The reference form is laid out by the parent field's overrideChildTca (types, palettes) over the table's TCA.
        $childTca = $GLOBALS['TCA'][Tables::SYS_FILE_REFERENCE];
        foreach (['types', 'palettes'] as $section) {
            if (is_array($overrideChildTca[$section] ?? null)) {
                $childTca[$section] = is_array($childTca[$section] ?? null) ? $childTca[$section] : [];
                ArrayUtility::mergeRecursiveWithOverrule($childTca[$section], $overrideChildTca[$section]);
            }
        }
        if (!$this->isShownInLayout($childTca, $this->recordType(Tables::SYS_FILE_REFERENCE, $reference), $field)) {
            return false;
        }

        $childColumn = $overrideChildTca['columns'][$field] ?? [];
        $childType = $this->recordType(Tables::SYS_FILE_REFERENCE, $reference);
        $typeOverride = $overrideChildTca['types'][$childType]['columnsOverrides'][$field] ?? null;
        if (is_array($typeOverride)) {
            $childColumn = is_array($childColumn) ? $childColumn : [];
            ArrayUtility::mergeRecursiveWithOverrule($childColumn, $typeOverride);
        }

        return !(is_array($childColumn) && (bool)($childColumn['config']['readOnly'] ?? false));
    }

    /** @param array<string, mixed> $record */
    private function recordType(string $table, array $record): string
    {
        try {
            return (string)BackendUtility::getTCAtypeValue($table, $record);
        } catch (\Throwable) {
            return '';
        }
    }

    /** @param array<string, mixed> $record */
    private function tsConfigPageUid(string $table, array $record): int
    {
        if ($table === Tables::PAGES) {
            $parent = (int)($record['l10n_parent'] ?? 0);
            return $parent > 0 ? $parent : (int)($record['uid'] ?? 0);
        }

        $pid = (int)($record['pid'] ?? 0);
        if ($pid > 0) {
            return $pid;
        }

        $originalUid = (int)($record['t3ver_oid'] ?? 0);
        if ($originalUid > 0) {
            $original = BackendUtility::getRecord($table, $originalUid, 'pid');
            return (int)($original['pid'] ?? 0);
        }

        return 0;
    }
}
