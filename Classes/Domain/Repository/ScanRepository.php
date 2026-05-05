<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Doctrine\DBAL\Exception;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class ScanRepository extends AbstractRepository
{
    private const STATUS_COMPLETED = 2;
    private const SCOPE_SUBTREE = 'subtree';

    public function createScanRun(
        string $siteIdentifier,
        int $rootPid,
        int $languageUid,
        string $scope,
    ): int {
        $now = time();
        $connection = $this->getConnection(Tables::SCAN);

        $connection->insert(Tables::SCAN, [
            'site_identifier' => $siteIdentifier,
            'root_pid' => $rootPid,
            'language_uid' => $languageUid,
            'scope' => $scope,
            'status' => 1,
            'started_at' => $now,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    public function finishScanRun(
        int $scanUid,
        int $pagesScanned,
        int $recordsScanned,
        int $issuesNew,
        int $issuesResolved,
        int $issuesIgnored,
    ): void {
        $now = time();

        $this->getConnection(Tables::SCAN)->update(Tables::SCAN, [
            'status' => self::STATUS_COMPLETED,
            'finished_at' => $now,
            'tstamp' => $now,
            'pages_scanned' => $pagesScanned,
            'records_scanned' => $recordsScanned,
            'issues_new' => $issuesNew,
            'issues_resolved' => $issuesResolved,
            'issues_ignored' => $issuesIgnored,
        ], [
            'uid' => $scanUid,
        ]);
    }

    public function failScanRun(int $scanUid): void
    {
        $now = time();

        $this->getConnection(Tables::SCAN)->update(Tables::SCAN, [
            'status' => 3,
            'finished_at' => $now,
            'tstamp' => $now,
        ], [
            'uid' => $scanUid,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLastCompletedScan(string $siteIdentifier): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT)),
            )
            ->orderBy('finished_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * Used by Overview to show only the last site-level scan,
     * not page-level scans triggered by content changes.
     *
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLastCompletedSubtreeScan(string $siteIdentifier): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT)),
                $qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_SUBTREE)),
            )
            ->orderBy('finished_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }
}