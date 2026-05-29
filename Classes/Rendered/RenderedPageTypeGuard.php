<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Priebera\A11yQualityGate\Database\PageDoktypes;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RenderedPageTypeGuard
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function supportsPageUid(int $pageUid): bool
    {
        return $this->supportsDoktype($this->getDoktypeForPage($pageUid));
    }

    public function supportsDoktype(?int $doktype): bool
    {
        if ($doktype === null) {
            return false;
        }

        return PageDoktypes::supportsRenderedCheck($doktype);
    }

    public function getDoktypeForPage(int $pageUid): ?int
    {
        if ($pageUid <= 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $qb->getRestrictions()->removeAll();

        $row = $qb
            ->select('doktype')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return (int)($row['doktype'] ?? 0);
    }
}
