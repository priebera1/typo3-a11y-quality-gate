<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\FieldScanType;
use Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ContentCollector
{
    private const FALLBACK_RTE_FIELDS = [
        'bodytext',
        'subheader',
    ];

    private const STRUCTURED_FIELDS = [
        'header' => 'text',
        'header_link' => 'link',
        'image_zoom' => 'bool',
        'table_header_position' => 'int',
        'table_caption' => 'text',
    ];

    private const FALLBACK_FILE_REFERENCE_FIELDS = [
        'image',
        'assets',
        'media',
    ];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FieldConfigRepository $fieldConfigRepository,
    ) {
    }

    /**
     * @return array<int, array{
     *   tableName:string,
     *   record:array<string, mixed>,
     *   rteFields:array<int, string>,
     *   fileReferenceFields:array<int, string>,
     *   structuredFields:array<int, string>,
     *   languageField:string,
     *   cTypeField:string
     * }>
     */
    public function collectForPage(int $pageUid, int $languageUid = -1): array
    {
        $fieldMap = $this->fieldConfigRepository->findEnabledFieldMap();
        $collected = [];

        foreach (array_keys($fieldMap) as $tableName) {
            $records = $this->collectTableRecordsForPage($tableName, $pageUid, $languageUid);

            foreach ($records as $recordEnvelope) {
                $collected[] = $recordEnvelope;
            }
        }

        return $collected;
    }

    /**
     * @return string[]
     */
    public function getRteFieldsForTable(string $tableName): array
    {
        $fallbackFields = $tableName === Tables::TT_CONTENT ? self::FALLBACK_RTE_FIELDS : [];

        return $this->resolveFields(
            $tableName,
            FieldScanType::Rte,
            $fallbackFields,
        );
    }

    /**
     * @return string[]
     */
    public function getFileReferenceFieldsForTable(string $tableName): array
    {
        $fallbackFields = $tableName === Tables::TT_CONTENT ? self::FALLBACK_FILE_REFERENCE_FIELDS : [];

        return $this->resolveFields(
            $tableName,
            FieldScanType::File,
            $fallbackFields,
        );
    }

    /**
     * @return string[]
     */
    public function getStructuredFieldsForTable(): array
    {
        return array_keys(self::STRUCTURED_FIELDS);
    }

    /**
     * @return array<int, array{
     *   tableName:string,
     *   record:array<string, mixed>,
     *   rteFields:array<int, string>,
     *   fileReferenceFields:array<int, string>,
     *   structuredFields:array<int, string>,
     *   languageField:string,
     *   cTypeField:string
     * }>
     */
    private function collectTableRecordsForPage(string $tableName, int $pageUid, int $languageUid): array
    {
        $tableConfiguration = $GLOBALS['TCA'][$tableName] ?? null;
        if (!is_array($tableConfiguration)) {
            return [];
        }

        $columns = $tableConfiguration['columns'] ?? [];
        if (!is_array($columns)) {
            return [];
        }

        $rteFields = $this->getRteFieldsForTable($tableName);
        $fileReferenceFields = $this->getFileReferenceFieldsForTable($tableName);
        $structuredFields = $tableName === Tables::TT_CONTENT
            ? $this->resolveStructuredFieldsForColumns($columns)
            : [];

        if ($rteFields === [] && $fileReferenceFields === [] && $structuredFields === []) {
            return [];
        }

        $ctrl = $tableConfiguration['ctrl'] ?? [];
        $ctrl = is_array($ctrl) ? $ctrl : [];

        $languageField = (string)($ctrl['languageField'] ?? '');
        $sortField = (string)($ctrl['sortby'] ?? 'uid');
        $cTypeField = $tableName === Tables::TT_CONTENT && isset($columns['CType']) ? 'CType' : '';

        $selectFields = [
            'uid',
            'pid',
        ];

        if ($languageField !== '') {
            $selectFields[] = $languageField;
        }

        if ($cTypeField !== '') {
            $selectFields[] = $cTypeField;
        }

        $selectFields = array_merge(
            $selectFields,
            $rteFields,
            $fileReferenceFields,
            $structuredFields
        );

        $selectFields = array_values(array_unique(array_filter(
            array_map('trim', $selectFields),
            static fn(string $field): bool => $field !== ''
        )));

        $qb = $this->connectionPool->getQueryBuilderForTable($tableName);
        $qb->getRestrictions()->removeAll();

        $qb->select(...$selectFields)
            ->from($tableName)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT))
            );

        $deleteField = (string)($ctrl['delete'] ?? 'deleted');
        if ($deleteField !== '') {
            $qb->andWhere(
                $qb->expr()->eq($deleteField, $qb->createNamedParameter(0, Connection::PARAM_INT))
            );
        }

        if ($languageUid >= 0 && $languageField !== '') {
            $qb->andWhere(
                $qb->expr()->eq($languageField, $qb->createNamedParameter($languageUid, Connection::PARAM_INT))
            );
        }

        $qb->orderBy($sortField !== '' ? $sortField : 'uid', 'ASC');

        $rows = $qb->executeQuery()->fetchAllAssociative();
        $result = [];

        foreach ($rows as $row) {
            $result[] = [
                'tableName' => $tableName,
                'record' => $row,
                'rteFields' => $rteFields,
                'fileReferenceFields' => $fileReferenceFields,
                'structuredFields' => $structuredFields,
                'languageField' => $languageField,
                'cTypeField' => $cTypeField,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $columns
     * @return string[]
     */
    private function resolveStructuredFieldsForColumns(array $columns): array
    {
        $fields = [];

        foreach (array_keys(self::STRUCTURED_FIELDS) as $fieldName) {
            if (isset($columns[$fieldName])) {
                $fields[] = $fieldName;
            }
        }

        return $fields;
    }

    /**
     * @param string[] $fallbackFields
     * @return string[]
     */
    private function resolveFields(
        string $tableName,
        FieldScanType $fieldType,
        array $fallbackFields,
    ): array {
        $enabledFields = $this->fieldConfigRepository->findEnabledFieldsByTableAndType(
            $tableName,
            $fieldType,
        );

        $disabledFields = $this->fieldConfigRepository->findDisabledFieldsByTableAndType(
            $tableName,
            $fieldType,
        );

        return $this->mergeFields(
            $enabledFields,
            array_diff($fallbackFields, $disabledFields),
        );
    }

    /**
     * @param string[] $primaryFields
     * @param string[] $fallbackFields
     * @return string[]
     */
    private function mergeFields(array $primaryFields, array $fallbackFields): array
    {
        $fields = array_merge($primaryFields, $fallbackFields);
        $fields = array_map('strval', $fields);
        $fields = array_map('trim', $fields);
        $fields = array_filter(
            $fields,
            static fn(string $field): bool => $field !== ''
        );
        $fields = array_values(array_unique($fields));

        sort($fields);

        return $fields;
    }
}