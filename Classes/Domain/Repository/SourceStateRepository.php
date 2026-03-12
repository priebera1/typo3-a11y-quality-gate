<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class SourceStateRepository extends AbstractRepository
{
    public function isUnchanged(
        string $siteIdentifier,
        string $sourceTable,
        int $sourceUid,
        string $sourceField,
        int $sourceLangUid,
        string $currentHash,
    ): bool {
        $storedHash = $this->findHash(
            $siteIdentifier,
            $sourceTable,
            $sourceUid,
            $sourceField,
            $sourceLangUid
        );

        return $storedHash === $currentHash;
    }

    public function upsertHash(
        string $siteIdentifier,
        int $pageUid,
        string $sourceTable,
        int $sourceUid,
        string $sourceField,
        int $sourceLangUid,
        string $hash,
        int $scanUid,
    ): void {
        $existing = $this->findRow(
            $siteIdentifier,
            $sourceTable,
            $sourceUid,
            $sourceField,
            $sourceLangUid
        );
        $now = time();

        if ($existing === null) {
            try {
                $this->getConnection(Tables::SOURCE_STATE)->insert(Tables::SOURCE_STATE, [
                    'site_identifier' => $siteIdentifier,
                    'page_uid' => $pageUid,
                    'source_lang_uid' => $sourceLangUid,
                    'source_table' => $sourceTable,
                    'source_uid' => $sourceUid,
                    'source_field' => $sourceField,
                    'content_hash' => $hash,
                    'last_scan_uid' => $scanUid,
                    'crdate' => $now,
                    'tstamp' => $now,
                ]);

                return;
            } catch (UniqueConstraintViolationException) {
                $existing = $this->findRow(
                    $siteIdentifier,
                    $sourceTable,
                    $sourceUid,
                    $sourceField,
                    $sourceLangUid
                );
            }
        }

        if ($existing !== null) {
            $this->getConnection(Tables::SOURCE_STATE)->update(Tables::SOURCE_STATE, [
                'page_uid' => $pageUid,
                'content_hash' => $hash,
                'last_scan_uid' => $scanUid,
                'tstamp' => $now,
            ], [
                'uid' => (int)$existing['uid'],
            ]);
        }
    }

    public function deleteForPage(int $pageUid, string $siteIdentifier): int
    {
        $qb = $this->getQueryBuilder(Tables::SOURCE_STATE);

        return (int)$qb
            ->delete(Tables::SOURCE_STATE)
            ->where(
                $qb->expr()->eq(
                    'site_identifier',
                    $qb->createNamedParameter($siteIdentifier)
                ),
                $qb->expr()->eq(
                    'page_uid',
                    $qb->createNamedParameter($pageUid, Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    private function findHash(
        string $siteIdentifier,
        string $sourceTable,
        int $sourceUid,
        string $sourceField,
        int $sourceLangUid,
    ): ?string {
        $row = $this->findRow(
            $siteIdentifier,
            $sourceTable,
            $sourceUid,
            $sourceField,
            $sourceLangUid
        );

        return $row !== null ? (string)$row['content_hash'] : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(
        string $siteIdentifier,
        string $sourceTable,
        int $sourceUid,
        string $sourceField,
        int $sourceLangUid,
    ): ?array {
        $qb = $this->getQueryBuilder(Tables::SOURCE_STATE);

        $row = $qb
            ->select('uid', 'content_hash')
            ->from(Tables::SOURCE_STATE)
            ->where(
                $qb->expr()->eq(
                    'site_identifier',
                    $qb->createNamedParameter($siteIdentifier)
                ),
                $qb->expr()->eq(
                    'source_table',
                    $qb->createNamedParameter($sourceTable)
                ),
                $qb->expr()->eq(
                    'source_uid',
                    $qb->createNamedParameter($sourceUid, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'source_field',
                    $qb->createNamedParameter($sourceField)
                ),
                $qb->expr()->eq(
                    'source_lang_uid',
                    $qb->createNamedParameter($sourceLangUid, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }
}
