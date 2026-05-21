<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Doctrine\DBAL\Exception;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class ScanRepository extends AbstractRepository
{
    private const STATUS_RUNNING = 1;
    private const STATUS_COMPLETED = 2;
    private const STATUS_CANCELLED = 4;
    private const SCOPE_PAGE = 'page';
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

    public function cancelScanRun(
        int $scanUid,
        int $pagesScanned,
        int $recordsScanned,
        int $issuesNew,
        int $issuesResolved,
        int $issuesIgnored,
    ): void {
        $now = time();

        $this->getConnection(Tables::SCAN)->update(Tables::SCAN, [
            'status' => self::STATUS_CANCELLED,
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


    public function requestScanCancellation(int $scanUid): void
    {
        $now = time();

        $this->getConnection(Tables::SCAN)->update(Tables::SCAN, [
            'status' => self::STATUS_CANCELLED,
            'tstamp' => $now,
        ], [
            'uid' => $scanUid,
            'status' => self::STATUS_RUNNING,
        ]);
    }

    public function isScanCancellationRequested(int $scanUid): bool
    {
        if ($scanUid <= 0) {
            return false;
        }

        $qb = $this->getQueryBuilder(Tables::SCAN);
        $status = $qb
            ->select('status')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($scanUid, Connection::PARAM_INT))
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        return (int)$status === self::STATUS_CANCELLED;
    }

    /**
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLastCompletedScan(string $siteIdentifier, int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT)),
            );

        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('finished_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     * @throws Exception
     */
    public function findLastCompletedPageScan(string $siteIdentifier, int $pageUid, int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('root_pid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT)),
                $qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_PAGE)),
            );

        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
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
    public function findLastCompletedSubtreeScan(string $siteIdentifier, int $languageUid = -1): ?array
    {
        $qb = $this->getQueryBuilder(Tables::SCAN);

        $row = $qb
            ->select('*')
            ->from(Tables::SCAN)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('status', $qb->createNamedParameter(self::STATUS_COMPLETED, Connection::PARAM_INT)),
                $qb->expr()->eq('scope', $qb->createNamedParameter(self::SCOPE_SUBTREE)),
            );

        $this->addLanguageConstraint($qb, $languageUid);

        $row = $qb
            ->orderBy('finished_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }
    private function addLanguageConstraint(\TYPO3\CMS\Core\Database\Query\QueryBuilder $qb, int $languageUid): void
    {
        if ($languageUid < 0) {
            return;
        }

        $qb->andWhere(
            $qb->expr()->or(
                $qb->expr()->eq('language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('language_uid', $qb->createNamedParameter(-1, Connection::PARAM_INT))
            )
        );
    }
}
