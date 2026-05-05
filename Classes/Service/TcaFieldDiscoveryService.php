<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Enum\FieldScanType;

final class TcaFieldDiscoveryService
{
    /**
     * @var string[]
     */
    private const EXCLUDED_TABLES = [
        'be_users',
        'be_groups',
        'fe_users',
        'fe_groups',
        'pages',
        'backend_layout',
    ];

    /**
     * @var string[]
     */
    private const EXCLUDED_PREFIXES = [
        'sys_',
        'cf_',
        'cache_',
        'tx_scheduler_',
        'tx_extensionmanager_',
        'tx_lowlevel_',
        'tx_install_',
        'tx_impexp_',
        'tx_belog_',
        'tx_adminpanel_',
    ];

    public function __construct(
        private readonly BackendLanguageService $backendLanguageService,
    ) {
    }

    /**
     * @return array<int, array{
     *   table_name:string,
     *   field_name:string,
     *   field_type:string,
     *   field_label:string,
     *   is_enabled:int,
     *   is_auto_detected:int
     * }>
     */
    public function discover(): array
    {
        $discovered = [];

        foreach ($GLOBALS['TCA'] ?? [] as $tableName => $tableConfiguration) {
            if (!is_string($tableName) || !is_array($tableConfiguration)) {
                continue;
            }

            if (!$this->shouldScanTable($tableName, $tableConfiguration)) {
                continue;
            }

            $columns = $tableConfiguration['columns'] ?? [];
            if (!is_array($columns) || $columns === []) {
                continue;
            }

            $types = $tableConfiguration['types'] ?? [];
            $types = is_array($types) ? $types : [];

            foreach ($columns as $fieldName => $fieldConfiguration) {
                if (!is_string($fieldName) || !is_array($fieldConfiguration)) {
                    continue;
                }

                $resolvedFieldType = $this->resolveFieldType(
                    $fieldName,
                    $fieldConfiguration,
                    $types,
                );

                if ($resolvedFieldType === null) {
                    continue;
                }

                $discovered[] = [
                    'table_name' => $tableName,
                    'field_name' => $fieldName,
                    'field_type' => $resolvedFieldType->value,
                    'field_label' => $this->resolveFieldLabel($fieldName, $fieldConfiguration),
                    'is_enabled' => 1,
                    'is_auto_detected' => 1,
                ];
            }
        }

        usort(
            $discovered,
            static fn(array $a, array $b): int => [$a['table_name'], $a['field_label'], $a['field_name']]
                <=> [$b['table_name'], $b['field_label'], $b['field_name']]
        );

        return $discovered;
    }

    /**
     * @param array<string, mixed> $tableConfiguration
     */
    private function shouldScanTable(string $tableName, array $tableConfiguration): bool
    {
        if ($this->isKnownSystemTable($tableName)) {
            return false;
        }

        $ctrl = $tableConfiguration['ctrl'] ?? [];
        $ctrl = is_array($ctrl) ? $ctrl : [];

        if ((bool)($ctrl['hideTable'] ?? false)) {
            return false;
        }

        if ((bool)($ctrl['adminOnly'] ?? false)) {
            return false;
        }

        if ((bool)($ctrl['is_static'] ?? false)) {
            return false;
        }

        $rootLevel = (int)($ctrl['rootLevel'] ?? 0);
        if ($rootLevel !== 0) {
            return false;
        }

        if (!$this->tableLooksPageBound($tableConfiguration)) {
            return false;
        }

        return $this->hasRelevantFields($tableConfiguration);
    }

    private function isKnownSystemTable(string $tableName): bool
    {
        if (in_array($tableName, self::EXCLUDED_TABLES, true)) {
            return true;
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if (str_starts_with($tableName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $tableConfiguration
     */
    private function tableLooksPageBound(array $tableConfiguration): bool
    {
        $ctrl = $tableConfiguration['ctrl'] ?? [];
        $ctrl = is_array($ctrl) ? $ctrl : [];

        if ((bool)($ctrl['ignoreWebMountPoints'] ?? false)) {
            return false;
        }

        if (!empty($ctrl['enablecolumns'])) {
            return true;
        }

        if (!empty($ctrl['sortby']) || !empty($ctrl['crdate']) || !empty($ctrl['tstamp'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $tableConfiguration
     */
    private function hasRelevantFields(array $tableConfiguration): bool
    {
        $columns = $tableConfiguration['columns'] ?? [];
        if (!is_array($columns) || $columns === []) {
            return false;
        }

        $types = $tableConfiguration['types'] ?? [];
        $types = is_array($types) ? $types : [];

        foreach ($columns as $fieldName => $fieldConfiguration) {
            if (!is_string($fieldName) || !is_array($fieldConfiguration)) {
                continue;
            }

            if ($this->resolveFieldType($fieldName, $fieldConfiguration, $types) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $fieldConfiguration
     * @param array<string|int, mixed> $types
     */
    private function resolveFieldType(
        string $fieldName,
        array $fieldConfiguration,
        array $types,
    ): ?FieldScanType {
        $baseConfig = $fieldConfiguration['config'] ?? [];
        $baseConfig = is_array($baseConfig) ? $baseConfig : [];

        $baseFieldType = $this->detectFieldType($baseConfig);
        if ($baseFieldType !== null) {
            return $baseFieldType;
        }

        foreach ($types as $typeConfiguration) {
            if (!is_array($typeConfiguration)) {
                continue;
            }

            $columnsOverrides = $typeConfiguration['columnsOverrides'] ?? [];
            if (
                !is_array($columnsOverrides)
                || !isset($columnsOverrides[$fieldName])
                || !is_array($columnsOverrides[$fieldName])
            ) {
                continue;
            }

            $overrideConfig = $columnsOverrides[$fieldName]['config'] ?? [];
            $overrideConfig = is_array($overrideConfig) ? $overrideConfig : [];

            $mergedConfig = array_replace($baseConfig, $overrideConfig);
            $overrideFieldType = $this->detectFieldType($mergedConfig);

            if ($overrideFieldType !== null) {
                return $overrideFieldType;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function detectFieldType(array $config): ?FieldScanType
    {
        $type = (string)($config['type'] ?? '');
        $renderType = (string)($config['renderType'] ?? '');
        $foreignTable = (string)($config['foreign_table'] ?? '');
        $internalType = (string)($config['internal_type'] ?? '');

        if ($type === 'file') {
            return FieldScanType::File;
        }

        if ($type === 'inline' && $foreignTable === 'sys_file_reference') {
            return FieldScanType::File;
        }

        if ($type === 'group' && $internalType === 'file') {
            return FieldScanType::File;
        }

        if ($type === 'text' && (bool)($config['enableRichtext'] ?? false)) {
            return FieldScanType::Rte;
        }

        if ($renderType === 'inputRichtext') {
            return FieldScanType::Rte;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $fieldConfiguration
     */
    private function resolveFieldLabel(string $fieldName, array $fieldConfiguration): string
    {
        $rawLabel = trim((string)($fieldConfiguration['label'] ?? ''));

        if ($rawLabel !== '') {
            $translated = trim($this->backendLanguageService->translateRawLabel($rawLabel));
            if ($translated !== '') {
                return $translated;
            }
        }

        return $fieldName;
    }
}