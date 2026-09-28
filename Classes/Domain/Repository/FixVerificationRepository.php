<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class FixVerificationRepository extends AbstractRepository
{
    /**
     * @param array<string, int|string> $data
     */
    public function insert(array $data): int
    {
        $now = time();
        $connection = $this->getConnection(Tables::FIX_VERIFICATION);
        $connection->insert(Tables::FIX_VERIFICATION, $data + ['crdate' => $now, 'tstamp' => $now]);

        return (int)$connection->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::FIX_VERIFICATION);
        $row = $queryBuilder
            ->select('*')
            ->from(Tables::FIX_VERIFICATION)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The latest verification per rule on a URL of a site, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function findLatestForSiteAndUrl(string $siteIdentifier, string $url, int $limit = 50): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::FIX_VERIFICATION);

        return array_values($queryBuilder
            ->select('*')
            ->from(Tables::FIX_VERIFICATION)
            ->where(
                $queryBuilder->expr()->eq('site_identifier', $queryBuilder->createNamedParameter($siteIdentifier)),
                $queryBuilder->expr()->eq('url', $queryBuilder->createNamedParameter($url))
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative());
    }

    public function storeOutcome(int $uid, string $outcome, string $reason, int $verificationScanUid, int $remaining): void
    {
        $this->getConnection(Tables::FIX_VERIFICATION)->update(
            Tables::FIX_VERIFICATION,
            [
                'outcome' => $outcome,
                'outcome_reason' => $reason,
                'verification_scan' => $verificationScanUid,
                'remaining_occurrences' => $remaining,
                'evaluated_at' => time(),
                'tstamp' => time(),
            ],
            ['uid' => $uid]
        );
    }
}
