<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Agent access audit trail (atlas 13 · F): who (agent client + TYPO3 user), where (site), what (tool or action with
 * target ids), the result and the reason that stopped a call. Stores identifiers and outcomes, never prompts, page
 * content, finding text or tokens.
 *
 * Retention: 90 days (owner decision 2026-10-29), changeable as `mcpAuditRetentionDays` in the extension configuration
 * (1–3650 days; empty means the approved 90). An invalid value deletes nothing and the Settings tab reports it — a typo
 * never shortens the trail. This is the agent access trail only; scan history has its own retention. Pruning runs when
 * the connector starts, every hour while it runs, when the Settings tab shows the trail, now and then while entries
 * are written (also after the connector stopped), and as `a11y:mcp:audit-prune` (operators, system cron).
 */
final class McpAuditRepository
{
    public const MAX_RETENTION_DAYS = 3650;
    public const DEFAULT_RETENTION_DAYS = 90;
    /** Entries per page of the Settings trail, and the most any caller may ask for. */
    public const PAGE_SIZE = 25;
    public const MAX_PAGE_SIZE = 100;
    /** Results the "Refused" filter shows. */
    public const REFUSED_RESULTS = ['denied', 'refused', 'quota'];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ExtensionConfiguration $extensionConfiguration,
    ) {
    }

    /** The retention in days: the configured value, the approved 90 when none is set, null when the value is invalid. */
    public function retentionDays(): ?int
    {
        $value = $this->configuredValue();
        if ($value === '') {
            return self::DEFAULT_RETENTION_DAYS;
        }

        return self::isValidRetention($value) ? (int)$value : null;
    }

    /** Set but not a whole number of days between 1 and 3650: reported, never guessed or capped, and nothing is deleted. */
    public function retentionMisconfigured(): bool
    {
        $value = $this->configuredValue();

        return $value !== '' && !self::isValidRetention($value);
    }

    private function configuredValue(): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get('a11y_quality_gate', 'mcpAuditRetentionDays'));
        } catch (\Throwable) {
            return '';
        }
    }

    private static function isValidRetention(string $value): bool
    {
        return preg_match('/^[1-9][0-9]{0,3}$/', $value) === 1 && (int)$value <= self::MAX_RETENTION_DAYS;
    }

    /**
     * @param array{beUserUid?: int, beUserName?: string, clientName?: string, tokenSuffix?: string, siteIdentifier?: string, target?: string, reason?: string} $context
     */
    public function record(string $action, string $result, string $source, array $context = []): void
    {
        $this->connectionPool->getConnectionForTable(Tables::MCP_AUDIT)->insert(Tables::MCP_AUDIT, [
            'crdate' => time(),
            'be_user_uid' => (int)($context['beUserUid'] ?? 0),
            'be_user_name' => mb_substr((string)($context['beUserName'] ?? ''), 0, 255),
            'client_name' => mb_substr((string)($context['clientName'] ?? ''), 0, 255),
            'token_suffix' => mb_substr((string)($context['tokenSuffix'] ?? ''), 0, 10),
            'site_identifier' => mb_substr((string)($context['siteIdentifier'] ?? ''), 0, 255),
            'action' => mb_substr($action, 0, 64),
            'target' => mb_substr((string)($context['target'] ?? ''), 0, 255),
            'result' => mb_substr($result, 0, 32),
            'reason' => mb_substr((string)($context['reason'] ?? ''), 0, 64),
            'source' => mb_substr($source, 0, 32),
        ]);
        // Retention must not depend on a running connector: every 100th entry on average also prunes.
        if (random_int(1, 100) === 1) {
            $this->prune();
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findRecent(int $limit = 50, string $resultFilter = ''): array
    {
        $queryBuilder = $this->windowQuery($resultFilter, time());
        $queryBuilder
            ->select('*')
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(max(1, min(200, $limit)));

        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * One page of the trail, newest first, paginated in the database: a count and one LIMIT/OFFSET query over the same
     * window (retention cut-off computed once, so both see the same set). `uid` breaks ties of entries written in the
     * same second, which keeps the order — and so the page boundaries — stable. A page past the end shows the last one.
     *
     * Deferred join (Fable observation 5): the OFFSET walks only `uid` along the `crdate` index (which holds the primary
     * key), and full rows are read for the page shown — a deep page no longer reads every skipped entry.
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, perPage: int}
     */
    public function findPage(int $page, int $perPage = self::PAGE_SIZE, string $resultFilter = '', ?int $now = null): array
    {
        $now ??= time();
        $perPage = max(1, min(self::MAX_PAGE_SIZE, $perPage));
        $count = $this->windowQuery($resultFilter, $now);
        $total = (int)$count->count('uid')->executeQuery()->fetchOne();
        $pages = max(1, (int)ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        if ($total === 0) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'perPage' => $perPage];
        }
        $uids = array_map('intval', $this->windowQuery($resultFilter, $now)
            ->select('uid')
            ->orderBy('crdate', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->executeQuery()
            ->fetchFirstColumn());
        $rows = [];
        if ($uids !== []) {
            $query = $this->connectionPool->getQueryBuilderForTable(Tables::MCP_AUDIT);
            $rows = $query
                ->select('*')
                ->from(Tables::MCP_AUDIT)
                ->where($query->expr()->in('uid', $query->createNamedParameter($uids, Connection::PARAM_INT_ARRAY)))
                ->orderBy('crdate', 'DESC')
                ->addOrderBy('uid', 'DESC')
                ->executeQuery()
                ->fetchAllAssociative();
        }

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages, 'perPage' => $perPage];
    }

    /** The entries the trail shows: within the retention period (all, while it is misconfigured), optionally refusals only. */
    private function windowQuery(string $resultFilter, int $now): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::MCP_AUDIT);
        $queryBuilder->from(Tables::MCP_AUDIT);
        $retentionDays = $this->retentionDays();
        if ($retentionDays !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->gte('crdate', $queryBuilder->createNamedParameter($now - $retentionDays * 86400, Connection::PARAM_INT)));
        }
        if ($resultFilter === 'refused') {
            $queryBuilder->andWhere($queryBuilder->expr()->in(
                'result',
                $queryBuilder->createNamedParameter(self::REFUSED_RESULTS, Connection::PARAM_STR_ARRAY)
            ));
        }

        return $queryBuilder;
    }

    /** Deletes entries older than the retention period; returns null (nothing deleted) while the configured value is invalid. */
    public function prune(): ?int
    {
        $retentionDays = $this->retentionDays();
        if ($retentionDays === null) {
            return null;
        }

        return (int)$this->connectionPool->getConnectionForTable(Tables::MCP_AUDIT)->executeStatement(
            'DELETE FROM ' . Tables::MCP_AUDIT . ' WHERE crdate < ?',
            [time() - $retentionDays * 86400],
            [Connection::PARAM_INT],
        );
    }
}
