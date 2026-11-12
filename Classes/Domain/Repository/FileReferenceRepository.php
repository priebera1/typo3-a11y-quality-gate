<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Domain\Repository\Contract\FileReferenceRepositoryInterface;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Workspace\PublishPreviewContext;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

final class FileReferenceRepository extends AbstractRepository implements FileReferenceRepositoryInterface
{
    private const SYS_FILE_TYPE_IMAGE = 2;

    public function __construct(
        ConnectionPool $connectionPool,
        private readonly ?PublishPreviewContext $publishPreview = null,
    ) {
        parent::__construct($connectionPool);
    }

    public function findByUid(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::SYS_FILE_REFERENCE);
        $row = $queryBuilder
            ->select('*')
            ->from(Tables::SYS_FILE_REFERENCE)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findVisibleImageReferencesWithMetadata(
        string $tableName,
        int $recordUid,
        string $fieldName,
    ): array {
        // The content scan reads live records only (ContentCollector): references and metadata of a workspace draft
        // are no evidence about the live page. Before a workspace publish, the Quality Gate reads them as that publish
        // leaves them (PublishPreviewContext).
        $publishSet = $this->publishPreview?->active();
        $referenceQueryBuilder = $this->getQueryBuilder(Tables::SYS_FILE_REFERENCE);
        $selectFields = ['uid', 'uid_local', 'alternative', 'title', 'tx_a11y_is_decorative', 'sys_language_uid'];
        $constraints = [
            $referenceQueryBuilder->expr()->eq('uid_foreign', $referenceQueryBuilder->createNamedParameter($recordUid, Connection::PARAM_INT)),
            $referenceQueryBuilder->expr()->eq('tablenames', $referenceQueryBuilder->createNamedParameter($tableName)),
            $referenceQueryBuilder->expr()->eq('fieldname', $referenceQueryBuilder->createNamedParameter($fieldName)),
            $referenceQueryBuilder->expr()->eq('deleted', $referenceQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
        ];
        if ($publishSet !== null && $this->publishPreview->restrictForPreview($referenceQueryBuilder)) {
            $now = time();
            $references = $publishSet->overlay(
                Tables::SYS_FILE_REFERENCE,
                $referenceQueryBuilder
                    ->select(...$selectFields, ...['uid_foreign', 'tablenames', 'fieldname', 'hidden', 'starttime', 'endtime', 't3ver_oid', 't3ver_wsid', 't3ver_state'])
                    ->from(Tables::SYS_FILE_REFERENCE)
                    ->where(...$constraints)
                    ->orderBy('uid', 'ASC')
                    ->executeQuery()
                    ->fetchAllAssociative(),
                static fn (array $row): bool => (int)$row['uid_foreign'] === $recordUid && (string)$row['tablenames'] === $tableName
                    && (string)$row['fieldname'] === $fieldName && PublishPreviewContext::isEnabled($row, $now),
            );
        } else {
            $referenceQueryBuilder->getRestrictions()->add(new WorkspaceRestriction(0));
            $references = $referenceQueryBuilder
                ->select(...$selectFields)
                ->from(Tables::SYS_FILE_REFERENCE)
                ->where(...$constraints)
                ->andWhere($referenceQueryBuilder->expr()->eq('hidden', $referenceQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)))
                ->orderBy('uid', 'ASC')
                ->executeQuery()
                ->fetchAllAssociative();
        }

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
        $metadataPreview = $publishSet !== null && $this->publishPreview->restrictForPreview($metadataQueryBuilder);
        if (!$metadataPreview) {
            $metadataQueryBuilder->getRestrictions()->add(new WorkspaceRestriction(0));
        }

        $metadataRows = $metadataQueryBuilder
            ->select('uid', 'file', 'sys_language_uid', 'alternative', 'title', ...($metadataPreview ? ['t3ver_oid', 't3ver_wsid', 't3ver_state'] : []))
            ->from(Tables::SYS_FILE_METADATA)
            ->where(
                $metadataQueryBuilder->expr()->in(
                    'file',
                    $metadataQueryBuilder->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
        if ($metadataPreview) {
            $metadataRows = $publishSet->overlay(Tables::SYS_FILE_METADATA, $metadataRows, static fn (array $row): bool => in_array((int)$row['file'], $fileUids, true));
        }

        // Metadata per file and language: a reference falls back to the metadata of its own language, else the
        // default language, as TYPO3 renders it. Another language's alternative text never counts.
        $metadataByFileAndLanguage = [];
        foreach ($metadataRows as $metadataRow) {
            $metadataByFileAndLanguage[(int)$metadataRow['file']][(int)($metadataRow['sys_language_uid'] ?? 0)] ??= [
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

            $referenceLanguageUid = max(0, (int)($reference['sys_language_uid'] ?? 0));
            $metadata = $metadataByFileAndLanguage[$uidLocal][$referenceLanguageUid]
                ?? $metadataByFileAndLanguage[$uidLocal][0]
                ?? ['alternative' => null, 'title' => null];

            $reference['identifier'] = $file['identifier'];
            $reference['metadata_alternative'] = $metadata['alternative'];
            $reference['metadata_title'] = $metadata['title'];

            $imageReferences[] = $reference;
        }

        return $imageReferences;
    }
}
