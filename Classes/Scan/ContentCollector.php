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
     * @return array<array<string, mixed>>
     */
    public function collectForPage(int $pageUid, int $languageUid = -1): array
    {
        $rteFields = $this->getRteFields();

        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::TT_CONTENT);

        $selectFields = array_values(array_unique([
            'uid',
            'pid',
            'CType',
            'sys_language_uid',
            ...$rteFields,
            ...array_keys(self::STRUCTURED_FIELDS),
        ]));

        $qb
            ->select(...$selectFields)
            ->from(Tables::TT_CONTENT)
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC');

        if ($languageUid >= 0) {
            $qb->andWhere(
                $qb->expr()->eq(
                    'sys_language_uid',
                    $qb->createNamedParameter($languageUid, Connection::PARAM_INT)
                )
            );
        }

        return $qb->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return string[]
     */
    public function getRteFields(): array
    {
        return $this->resolveFields(
            Tables::TT_CONTENT,
            FieldScanType::Rte,
            self::FALLBACK_RTE_FIELDS,
        );
    }

    /**
     * @return string[]
     */
    public function getStructuredFields(): array
    {
        return array_keys(self::STRUCTURED_FIELDS);
    }

    /**
     * @return string[]
     */
    public function getFileReferenceFields(): array
    {
        return $this->resolveFields(
            Tables::TT_CONTENT,
            FieldScanType::File,
            self::FALLBACK_FILE_REFERENCE_FIELDS,
        );
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
