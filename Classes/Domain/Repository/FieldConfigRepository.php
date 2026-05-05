<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\FieldScanType;

final class FieldConfigRepository extends AbstractRepository
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllActive(): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::FIELD_CONFIG);

        return $queryBuilder
            ->select('*')
            ->from(Tables::FIELD_CONFIG)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0))
            )
            ->orderBy('table_name', 'ASC')
            ->addOrderBy('field_label', 'ASC')
            ->addOrderBy('field_name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, array{
     *   fields: array<int, array<string, mixed>>,
     *   enabledCount: int,
     *   totalCount: int
     * }>
     */
    public function findGroupedForSettings(): array
    {
        $rows = $this->findAllActive();
        $grouped = [];

        foreach ($rows as $row) {
            $tableName = (string)$row['table_name'];

            if (!isset($grouped[$tableName])) {
                $grouped[$tableName] = [
                    'fields' => [],
                    'enabledCount' => 0,
                    'totalCount' => 0,
                ];
            }

            $grouped[$tableName]['fields'][] = $row;
            $grouped[$tableName]['totalCount']++;

            if ((bool)$row['is_enabled']) {
                $grouped[$tableName]['enabledCount']++;
            }
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function findEnabledFieldMap(): array
    {
        $rows = $this->findAllActive();
        $fieldMap = [];

        foreach ($rows as $row) {
            if (!(bool)$row['is_enabled']) {
                continue;
            }

            $tableName = (string)$row['table_name'];
            $fieldName = (string)$row['field_name'];

            $fieldMap[$tableName] ??= [];
            $fieldMap[$tableName][] = $fieldName;
        }

        foreach ($fieldMap as &$fields) {
            $fields = array_values(array_unique($fields));
            sort($fields);
        }
        unset($fields);

        ksort($fieldMap);

        return $fieldMap;
    }

    /**
     * @return string[]
     */
    public function findEnabledFieldsByTableAndType(string $tableName, FieldScanType $fieldType): array
    {
        return $this->findFieldsByTableAndTypeAndEnabledState(
            $tableName,
            $fieldType,
            true,
        );
    }

    /**
     * @return string[]
     */
    public function findDisabledFieldsByTableAndType(string $tableName, FieldScanType $fieldType): array
    {
        return $this->findFieldsByTableAndTypeAndEnabledState(
            $tableName,
            $fieldType,
            false,
        );
    }

    /**
     * @param array<int, array{
     *   table_name:string,
     *   field_name:string,
     *   field_type:string,
     *   field_label:string,
     *   is_enabled:int,
     *   is_auto_detected:int
     * }> $discoveredFields
     */
    public function refreshFromDiscovery(array $discoveredFields): void
    {
        $connection = $this->getConnection(Tables::FIELD_CONFIG);
        $existingRows = $this->findAllIncludingHidden();
        $timestamp = $GLOBALS['EXEC_TIME'] ?? time();

        $existingByKey = [];
        foreach ($existingRows as $row) {
            $existingByKey[$this->buildKey((string)$row['table_name'], (string)$row['field_name'])] = $row;
        }

        $seenKeys = [];

        foreach ($discoveredFields as $field) {
            $key = $this->buildKey($field['table_name'], $field['field_name']);
            $seenKeys[$key] = true;

            if (isset($existingByKey[$key])) {
                $existing = $existingByKey[$key];

                $connection->update(
                    Tables::FIELD_CONFIG,
                    [
                        'field_type' => $field['field_type'],
                        'field_label' => $field['field_label'],
                        'is_auto_detected' => $field['is_auto_detected'],
                        'deleted' => 0,
                        'hidden' => 0,
                        'tstamp' => $timestamp,
                    ],
                    [
                        'uid' => (int)$existing['uid'],
                    ]
                );

                continue;
            }

            $connection->insert(
                Tables::FIELD_CONFIG,
                [
                    'pid' => 0,
                    'deleted' => 0,
                    'hidden' => 0,
                    'table_name' => $field['table_name'],
                    'field_name' => $field['field_name'],
                    'field_type' => $field['field_type'],
                    'field_label' => $field['field_label'],
                    'is_enabled' => $field['is_enabled'],
                    'is_auto_detected' => $field['is_auto_detected'],
                    'crdate' => $timestamp,
                    'tstamp' => $timestamp,
                ]
            );
        }

        foreach ($existingRows as $row) {
            $key = $this->buildKey((string)$row['table_name'], (string)$row['field_name']);

            if (isset($seenKeys[$key])) {
                continue;
            }

            $connection->update(
                Tables::FIELD_CONFIG,
                [
                    'hidden' => 1,
                    'tstamp' => $timestamp,
                ],
                [
                    'uid' => (int)$row['uid'],
                ]
            );
        }
    }

    /**
     * @param array<string, array<int, string>> $enabledFieldMap
     */
    public function saveEnabledState(array $enabledFieldMap): void
    {
        $connection = $this->getConnection(Tables::FIELD_CONFIG);
        $rows = $this->findAllIncludingHidden();
        $timestamp = $GLOBALS['EXEC_TIME'] ?? time();

        foreach ($rows as $row) {
            if ((int)$row['deleted'] === 1 || (int)$row['hidden'] === 1) {
                continue;
            }

            $tableName = (string)$row['table_name'];
            $fieldName = (string)$row['field_name'];

            $isEnabled = isset($enabledFieldMap[$tableName])
                && in_array($fieldName, $enabledFieldMap[$tableName], true);

            $connection->update(
                Tables::FIELD_CONFIG,
                [
                    'is_enabled' => $isEnabled ? 1 : 0,
                    'tstamp' => $timestamp,
                ],
                [
                    'uid' => (int)$row['uid'],
                ]
            );
        }
    }

    public function hasEnabledFields(): bool
    {
        $queryBuilder = $this->getQueryBuilder(Tables::FIELD_CONFIG);

        $count = $queryBuilder
            ->count('uid')
            ->from(Tables::FIELD_CONFIG)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0)),
                $queryBuilder->expr()->eq('is_enabled', $queryBuilder->createNamedParameter(1))
            )
            ->executeQuery()
            ->fetchOne();

        return (int)$count > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findAllIncludingHidden(): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::FIELD_CONFIG);

        return $queryBuilder
            ->select('*')
            ->from(Tables::FIELD_CONFIG)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0))
            )
            ->orderBy('table_name', 'ASC')
            ->addOrderBy('field_name', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return string[]
     */
    private function findFieldsByTableAndTypeAndEnabledState(
        string $tableName,
        FieldScanType $fieldType,
        bool $isEnabled,
    ): array {
        $queryBuilder = $this->getQueryBuilder(Tables::FIELD_CONFIG);

        $rows = $queryBuilder
            ->select('field_name')
            ->from(Tables::FIELD_CONFIG)
            ->where(
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0)),
                $queryBuilder->expr()->eq('hidden', $queryBuilder->createNamedParameter(0)),
                $queryBuilder->expr()->eq('is_enabled', $queryBuilder->createNamedParameter($isEnabled ? 1 : 0)),
                $queryBuilder->expr()->eq('table_name', $queryBuilder->createNamedParameter($tableName)),
                $queryBuilder->expr()->eq('field_type', $queryBuilder->createNamedParameter($fieldType->value))
            )
            ->orderBy('field_name', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        $rows = array_map(static fn(mixed $value): string => (string)$value, $rows);
        $rows = array_map('trim', $rows);
        $rows = array_filter(
            $rows,
            static fn(string $value): bool => $value !== ''
        );
        $rows = array_values(array_unique($rows));

        sort($rows);

        return $rows;
    }

    private function buildKey(string $tableName, string $fieldName): string
    {
        return $tableName . '::' . $fieldName;
    }
}
