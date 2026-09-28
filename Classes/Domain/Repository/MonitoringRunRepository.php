<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class MonitoringRunRepository extends AbstractRepository
{
    public function insertSubmitted(string $siteIdentifier, int $languageUid, string $jobId): int
    {
        $now = time();
        $connection = $this->getConnection(Tables::MONITORING_RUN);
        $connection->insert(Tables::MONITORING_RUN, [
            'site_identifier' => $siteIdentifier,
            'language_uid' => $languageUid,
            'job_id' => $jobId,
            'status' => 'submitted',
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPending(string $siteIdentifier, int $languageUid): ?array
    {
        return $this->findLatest($siteIdentifier, $languageUid, 'submitted');
    }

    /**
     * The latest evaluated run before the given one, whose regression state a new run is compared with.
     *
     * @return array<string, mixed>|null
     */
    public function findPreviousEvaluated(string $siteIdentifier, int $languageUid, int $beforeUid): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::MONITORING_RUN);
        $row = $queryBuilder
            ->select('*')
            ->from(Tables::MONITORING_RUN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('evaluated')),
                $queryBuilder->expr()->lt('uid', $queryBuilder->createNamedParameter($beforeUid, Connection::PARAM_INT))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The latest evaluated run before the given one whose scan covered everything its baseline had checked:
     * the next run's baseline. A run with missing coverage never becomes one.
     *
     * @return array<string, mixed>|null
     */
    public function findLatestTrustedRun(string $siteIdentifier, int $languageUid, int $beforeUid): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::MONITORING_RUN);
        $row = $queryBuilder
            ->select('*')
            ->from(Tables::MONITORING_RUN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('evaluated')),
                $queryBuilder->expr()->eq('coverage_complete', $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('uid', $queryBuilder->createNamedParameter($beforeUid, Connection::PARAM_INT))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Whether a scan was a monitoring scan evaluated with missing coverage, which must not serve as a baseline.
     */
    public function isUntrustedRunJob(string $jobId): bool
    {
        if ($jobId === '') {
            return false;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::MONITORING_RUN);

        return (int)$queryBuilder
            ->count('uid')
            ->from(Tables::MONITORING_RUN)
            ->where(
                $queryBuilder->expr()->eq('job_id', $queryBuilder->createNamedParameter($jobId)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter('evaluated')),
                $queryBuilder->expr()->eq('coverage_complete', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne() > 0;
    }

    /**
     * @param array<string, int|string> $data
     */
    public function update(int $uid, array $data): void
    {
        $this->getConnection(Tables::MONITORING_RUN)->update(
            Tables::MONITORING_RUN,
            $data + ['tstamp' => time()],
            ['uid' => $uid]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLatest(string $siteIdentifier, int $languageUid, string $status): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::MONITORING_RUN);
        $row = $queryBuilder
            ->select('*')
            ->from(Tables::MONITORING_RUN)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter($status))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
