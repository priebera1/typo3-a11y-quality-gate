<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class FileReferenceRepository extends AbstractRepository
{
    private const SYS_FILE_TYPE_IMAGE = 2;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findVisibleImageReferencesWithMetadata(
        string $tableName,
        int $recordUid,
        string $fieldName,
    ): array {
        $referenceQueryBuilder = $this->getQueryBuilder(Tables::SYS_FILE_REFERENCE);

        $references = $referenceQueryBuilder
            ->select(
                'uid',
                'uid_local',
                'alternative',
                'title',
                'tx_a11y_is_decorative'
            )
            ->from(Tables::SYS_FILE_REFERENCE)
            ->where(
                $referenceQueryBuilder->expr()->eq(
                    'uid_foreign',
                    $referenceQueryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)
                ),
                $referenceQueryBuilder->expr()->eq(
                    'tablenames',
                    $referenceQueryBuilder->createNamedParameter($tableName)
                ),
                $referenceQueryBuilder->expr()->eq(
                    'fieldname',
                    $referenceQueryBuilder->createNamedParameter($fieldName)
                ),
                $referenceQueryBuilder->expr()->eq(
                    'hidden',
                    $referenceQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $referenceQueryBuilder->expr()->eq(
                    'deleted',
                    $referenceQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($references === []) {
            return [];
        }

        $fileUids = [];
        foreach ($references as $reference) {
            $uidLocal = (int)($reference['uid_local'] ?? 0);
            if ($uidLocal > 0) {
                $fileUids[] = $uidLocal;
            }
        }

        $fileUids = array_values(array_unique($fileUids));

        if ($fileUids === []) {
            return [];
        }

        $fileQueryBuilder = $this->getQueryBuilder(Tables::SYS_FILE);

        $fileRows = $fileQueryBuilder
            ->select('uid', 'identifier', 'type')
            ->from(Tables::SYS_FILE)
            ->where(
                $fileQueryBuilder->expr()->in(
                    'uid',
                    $fileQueryBuilder->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $filesByUid = [];
        foreach ($fileRows as $fileRow) {
            $filesByUid[(int)$fileRow['uid']] = [
                'identifier' => (string)($fileRow['identifier'] ?? ''),
                'type' => (int)($fileRow['type'] ?? 0),
            ];
        }

        $metadataQueryBuilder = $this->getQueryBuilder(Tables::SYS_FILE_METADATA);

        $metadataRows = $metadataQueryBuilder
            ->select('file', 'alternative', 'title')
            ->from(Tables::SYS_FILE_METADATA)
            ->where(
                $metadataQueryBuilder->expr()->in(
                    'file',
                    $metadataQueryBuilder->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $metadataByFileUid = [];
        foreach ($metadataRows as $metadataRow) {
            $metadataByFileUid[(int)$metadataRow['file']] = [
                'alternative' => $metadataRow['alternative'] ?? null,
                'title' => $metadataRow['title'] ?? null,
            ];
        }

        $imageReferences = [];
        foreach ($references as $reference) {
            $uidLocal = (int)($reference['uid_local'] ?? 0);
            $file = $filesByUid[$uidLocal] ?? null;

            if ($file === null || (int)$file['type'] !== self::SYS_FILE_TYPE_IMAGE) {
                continue;
            }

            $metadata = $metadataByFileUid[$uidLocal] ?? [
                'alternative' => null,
                'title' => null,
            ];

            $reference['identifier'] = $file['identifier'];
            $reference['metadata_alternative'] = $metadata['alternative'];
            $reference['metadata_title'] = $metadata['title'];

            $imageReferences[] = $reference;
        }

        return $imageReferences;
    }
}