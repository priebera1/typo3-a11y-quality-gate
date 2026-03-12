<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

abstract class AbstractRepository
{
    public function __construct(
        protected readonly ConnectionPool $connectionPool,
    ) {
    }

    protected function getConnection(string $table): Connection
    {
        return $this->connectionPool->getConnectionForTable($table);
    }

    protected function getQueryBuilder(string $table): QueryBuilder
    {
        return $this->connectionPool->getQueryBuilderForTable($table);
    }
}
