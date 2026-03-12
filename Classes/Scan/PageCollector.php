<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class PageCollector
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return int[]
     */
    public function collectPage(int $pageUid): array
    {
        return $this->pageExists($pageUid) ? [$pageUid] : [];
    }

    /**
     * @return int[]
     */
    public function collectSubtree(int $rootPid, int $depth = 99): array
    {
        if (!$this->pageExists($rootPid)) {
            return [];
        }

        $result = [$rootPid];
        $currentLevel = [$rootPid];
        $currentDepth = 0;

        while ($currentLevel !== [] && $currentDepth < $depth) {
            $children = $this->findChildren($currentLevel);

            if ($children === []) {
                break;
            }

            $result = [...$result, ...$children];
            $currentLevel = $children;
            $currentDepth++;
        }

        return array_values(array_unique($result));
    }

    private function pageExists(int $pageUid): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);

        $row = $qb
            ->select('uid')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false;
    }

    /**
     * @param int[] $parentUids
     * @return int[]
     */
    private function findChildren(array $parentUids): array
    {
        if ($parentUids === []) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);

        $rows = $qb
            ->select('uid')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->in(
                    'pid',
                    $qb->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)
                ),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('hidden', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('sorting', 'ASC')
            ->executeQuery()
            ->fetchFirstColumn();

        return array_map(static fn(mixed $uid): int => (int)$uid, $rows);
    }
}
