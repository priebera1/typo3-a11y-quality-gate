<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class RemoteIssueRepository extends AbstractRepository
{
    public function deleteByRemoteScan(int $remoteScanUid): void
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        $queryBuilder
            ->delete(Tables::REMOTE_ISSUE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    /**
     * @return array<int, int>
     */
    public function findUidsByRemoteScan(int $remoteScanUid): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        $rows = $queryBuilder
            ->select('uid')
            ->from(Tables::REMOTE_ISSUE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map('intval', $rows ?: []));
    }

    public function saveIssue(
        int $remoteScanUid,
        int $remoteScanPageUid,
        array $issue,
        int $pid = 0,
    ): int {
        $connection = $this->getConnection(Tables::REMOTE_ISSUE);
        $now = time();

        $connection->insert(Tables::REMOTE_ISSUE, [
            'pid' => $pid,
            'remote_scan' => $remoteScanUid,
            'remote_scan_page' => $remoteScanPageUid,
            'rule_id' => (string)($issue['ruleId'] ?? ''),
            'impact' => (string)($issue['impact'] ?? ''),
            'help' => (string)($issue['help'] ?? ''),
            'help_url' => (string)($issue['helpUrl'] ?? ''),
            'guidance_why_it_matters' => $this->extractGuidanceText($issue, 'whyItMatters', 'why_it_matters'),
            'guidance_how_to_fix' => $this->extractGuidanceText($issue, 'howToFix', 'how_to_fix'),
            'who_should_fix' => $this->extractGuidanceChoice($issue, 'whoShouldFix', 'who_should_fix'),
            'fix_type' => $this->extractGuidanceChoice($issue, 'fixType', 'fix_type'),
            'confidence' => $this->extractGuidanceChoice($issue, 'confidence', 'confidence'),
            'nodes_count' => is_array($issue['nodes'] ?? null) ? count($issue['nodes']) : 0,
            'fingerprint' => (string)($issue['fingerprint'] ?? ''),
            'status' => (string)($issue['status'] ?? 'open'),
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        return (int)$connection->lastInsertId();
    }




    /**
     * @param array<string, mixed> $issue
     */
    private function extractGuidanceText(array $issue, string $camelKey, string $snakeKey): string
    {
        $guidance = is_array($issue['guidance'] ?? null) ? $issue['guidance'] : [];
        $value = $issue[$camelKey] ?? $issue[$snakeKey] ?? $guidance[$camelKey] ?? $guidance[$snakeKey] ?? '';

        return trim((string)$value);
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function extractGuidanceChoice(array $issue, string $camelKey, string $snakeKey): string
    {
        $value = strtolower($this->extractGuidanceText($issue, $camelKey, $snakeKey));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
        $value = trim($value, '_-');

        return substr($value, 0, 50);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findIssueRowsForRemoteScan(int $remoteScanUid): array
    {
        if ($remoteScanUid <= 0) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        return $queryBuilder
            ->select('ri.*')
            ->addSelectLiteral('rsp.uid AS remote_scan_page_uid')
            ->from(Tables::REMOTE_ISSUE, 'ri')
            ->leftJoin(
                'ri',
                Tables::REMOTE_SCAN_PAGE,
                'rsp',
                $queryBuilder->expr()->eq('rsp.uid', 'ri.remote_scan_page')
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'ri.remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->neq(
                    'ri.status',
                    $queryBuilder->createNamedParameter('ignored')
                )
            )
            ->orderBy('ri.impact', 'ASC')
            ->addOrderBy('ri.rule_id', 'ASC')
            ->addOrderBy('ri.uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findByRemoteScanPage(int $remoteScanPageUid): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        return $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_ISSUE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan_page',
                    $queryBuilder->createNamedParameter($remoteScanPageUid, Connection::PARAM_INT)
                )
            )
            ->orderBy('impact', 'ASC')
            ->addOrderBy('rule_id', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findOneByUid(int $uid): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_ISSUE)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
