<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\FieldScanType;

final class TcaFieldDiscoveryService
{
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
        $tableConfiguration = $GLOBALS['TCA'][Tables::TT_CONTENT] ?? null;

        if (!is_array($tableConfiguration)) {
            return [];
        }

        $columns = $tableConfiguration['columns'] ?? [];
        if (!is_array($columns) || $columns === []) {
            return [];
        }

        $types = $tableConfiguration['types'] ?? [];
        $types = is_array($types) ? $types : [];

        foreach ($columns as $fieldName => $fieldConfiguration) {
            if (!is_array($fieldConfiguration)) {
                continue;
            }

            $resolvedFieldType = $this->resolveFieldType(
                (string)$fieldName,
                $fieldConfiguration,
                $types,
            );

            if ($resolvedFieldType === null) {
                continue;
            }

            $discovered[] = [
                'table_name' => Tables::TT_CONTENT,
                'field_name' => (string)$fieldName,
                'field_type' => $resolvedFieldType->value,
                'field_label' => $this->resolveFieldLabel((string)$fieldName, $fieldConfiguration),
                'is_enabled' => 1,
                'is_auto_detected' => 1,
            ];
        }

        usort(
            $discovered,
            static fn(array $a, array $b): int => [$a['field_label'], $a['field_name']]
                <=> [$b['field_label'], $b['field_name']]
        );

        return $discovered;
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
        if ($baseFieldType === FieldScanType::File) {
            return FieldScanType::File;
        }

        if ($baseFieldType === FieldScanType::Rte) {
            return FieldScanType::Rte;
        }

        foreach ($types as $typeConfiguration) {
            if (!is_array($typeConfiguration)) {
                continue;
            }

            $columnsOverrides = $typeConfiguration['columnsOverrides'] ?? [];
            if (!is_array($columnsOverrides) || !isset($columnsOverrides[$fieldName]) || !is_array($columnsOverrides[$fieldName])) {
                continue;
            }

            $overrideConfig = $columnsOverrides[$fieldName]['config'] ?? [];
            $overrideConfig = is_array($overrideConfig) ? $overrideConfig : [];

            $mergedConfig = array_replace($baseConfig, $overrideConfig);
            $overrideFieldType = $this->detectFieldType($mergedConfig);

            if ($overrideFieldType === FieldScanType::File) {
                return FieldScanType::File;
            }

            if ($overrideFieldType === FieldScanType::Rte) {
                return FieldScanType::Rte;
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

        if ($type === 'text' && (bool)($config['enableRichtext'] ?? false)) {
            return FieldScanType::Rte;
        }

        if ($type === 'file') {
            return FieldScanType::File;
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
