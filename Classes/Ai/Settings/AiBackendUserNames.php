<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Settings;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Display names of the administrators who confirmed an acknowledgement or entered a price, and of the people in the
 * admin-only usage report. AQG stores only the backend user uid; a deleted user reads as "#<uid>" in name(), and
 * describe() says whether the account is active, disabled, deleted or gone.
 */
class AiBackendUserNames
{
    /** @var array<int,string> */
    private array $cache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /** @var array<int,array{name:string,username:string,state:string}> */
    private array $descriptions = [];

    /**
     * The account as the usage report shows it: current display name (real name, else username), username, and state
     * "active" | "disabled" | "deleted" (soft-deleted record) | "missing" (no record). Restrictions are removed on
     * purpose, so historical usage of a removed or disabled account keeps a name instead of a bare id.
     *
     * @return array{name:string,username:string,state:string}
     */
    public function describe(int $uid): array
    {
        if ($uid <= 0) {
            return ['name' => '', 'username' => '', 'state' => 'missing'];
        }
        if (!isset($this->descriptions[$uid])) {
            try {
                $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
                $qb->getRestrictions()->removeAll();
                $row = $qb->select('username', 'realName', 'deleted', 'disable')
                    ->from('be_users')
                    ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();
            } catch (\Throwable) {
                $row = false;
            }
            if (!is_array($row)) {
                $this->descriptions[$uid] = ['name' => '#' . $uid, 'username' => '', 'state' => 'missing'];
            } else {
                $username = trim((string)($row['username'] ?? ''));
                $name = trim((string)($row['realName'] ?? ''));
                $this->descriptions[$uid] = [
                    'name' => $name !== '' ? $name : ($username !== '' ? $username : '#' . $uid),
                    'username' => $username,
                    'state' => (int)($row['deleted'] ?? 0) === 1 ? 'deleted' : ((int)($row['disable'] ?? 0) === 1 ? 'disabled' : 'active'),
                ];
            }
        }

        return $this->descriptions[$uid];
    }

    public function name(int $uid): string
    {
        if ($uid <= 0) {
            return '';
        }
        if (!isset($this->cache[$uid])) {
            try {
                $qb = $this->connectionPool->getQueryBuilderForTable('be_users');
                $row = $qb->select('username', 'realName')
                    ->from('be_users')
                    ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
                    ->setMaxResults(1)
                    ->executeQuery()
                    ->fetchAssociative();
            } catch (\Throwable) {
                $row = false;
            }
            $name = is_array($row) ? trim((string)($row['realName'] ?? '')) : '';
            if ($name === '' && is_array($row)) {
                $name = trim((string)($row['username'] ?? ''));
            }
            $this->cache[$uid] = $name !== '' ? $name : '#' . $uid;
        }

        return $this->cache[$uid];
    }
}
