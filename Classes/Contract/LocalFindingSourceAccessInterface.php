<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Contract;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Current read access to the source record of stored local findings (see LocalFindingSourceAccessService).
 */
interface LocalFindingSourceAccessInterface
{
    public function constraint(QueryBuilder $qb, string $issueAlias, string $siteIdentifier): string;

    /** @param array<string, mixed> $issue */
    public function canReadFinding(array $issue): bool;

    /**
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    public function filter(array $issues): array;

    public function canReadRecord(string $table, int $uid): bool;
}
