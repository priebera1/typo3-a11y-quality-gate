<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class IssueRepository extends AbstractRepository
{
    /**
     * @return 'inserted'|'updated'|'protected'
     */
    public function upsert(RuleViolation $violation, CheckContext $ctx, int $scanUid): string
    {
        $fingerprint = $violation->fingerprint($ctx);
        $now = time();
        $existing = $this->findByFingerprint($fingerprint, $ctx->siteIdentifier);

        if ($existing === null) {
            $this->getConnection(Tables::ISSUE)->insert(Tables::ISSUE, [
                'site_identifier' => $ctx->siteIdentifier,
                'page_uid' => $ctx->pageUid,
                'source_lang_uid' => $ctx->sourceLangUid,
                'source_table' => $ctx->sourceTable,
                'source_uid' => $ctx->sourceUid,
                'source_field' => $ctx->sourceField,
                'rule_id' => $violation->ruleId,
                'severity' => $violation->severity->value,
                'message' => $violation->message,
                'hint' => $violation->hint,
                'context_snippet' => $violation->contextSnippet,
                'context_path' => $violation->contextPath,
                'fingerprint' => $fingerprint,
                'status' => IssueStatus::Open->value,
                'first_seen_scan_uid' => $scanUid,
                'last_seen_scan_uid' => $scanUid,
                'crdate' => $now,
                'tstamp' => $now,
            ]);

            return 'inserted';
        }

        $status = IssueStatus::fromInt((int)$existing['status']);

        if ($status->isProtected()) {
            return 'protected';
        }

        $update = [
            'last_seen_scan_uid' => $scanUid,
            'tstamp' => $now,
        ];

        if ($status === IssueStatus::Resolved) {
            $update['status'] = IssueStatus::Open->value;
        }

        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, $update, [
            'site_identifier' => $ctx->siteIdentifier,
            'fingerprint' => $fingerprint,
        ]);

        return 'updated';
    }

    /**
     * @param string[] $seenFingerprints
     */
    public function resolveUnseen(
        int $pageUid,
        string $siteIdentifier,
        int $sourceLangUid,
        array $seenFingerprints,
        int $scanUid,
    ): int {
        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Resolved->value)
            ->set('resolved_at', (string)$now)
            ->set('tstamp', (string)$now)
            ->where(
                $qb->expr()->eq(
                    'site_identifier',
                    $qb->createNamedParameter($siteIdentifier)
                ),
                $qb->expr()->eq(
                    'page_uid',
                    $qb->createNamedParameter($pageUid, Connection::PARAM_INT)
                ),
                $qb->expr()->or(
                    $qb->expr()->eq(
                        'status',
                        $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)
                    ),
                    $qb->expr()->eq(
                        'status',
                        $qb->createNamedParameter(IssueStatus::Resolved->value, Connection::PARAM_INT)
                    ),
                ),
            );

        if ($sourceLangUid >= 0) {
            $qb->andWhere(
                $qb->expr()->eq(
                    'source_lang_uid',
                    $qb->createNamedParameter($sourceLangUid, Connection::PARAM_INT)
                )
            );
        }

        if ($seenFingerprints !== []) {
            $qb->andWhere(
                $qb->expr()->notIn(
                    'fingerprint',
                    $qb->createNamedParameter($seenFingerprints, Connection::PARAM_STR_ARRAY)
                )
            );
        }

        return (int)$qb->executeStatement();
    }

    public function ignore(int $issueUid, string $reason, int $backendUserUid): void
    {
        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, [
            'status' => IssueStatus::Ignored->value,
            'ignored_reason' => $reason,
            'ignored_by' => $backendUserUid,
            'ignored_at' => time(),
            'tstamp' => time(),
        ], [
            'uid' => $issueUid,
        ]);
    }

    public function unignore(int $issueUid): void
    {
        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, [
            'status' => IssueStatus::Open->value,
            'ignored_reason' => '',
            'ignored_by' => 0,
            'ignored_at' => 0,
            'tstamp' => time(),
        ], [
            'uid' => $issueUid,
        ]);
    }

    public function resolve(int $issueUid, int $backendUserUid): void
    {
        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, [
            'status' => IssueStatus::Resolved->value,
            'resolved_by' => $backendUserUid,
            'resolved_at' => time(),
            'tstamp' => time(),
        ], [
            'uid' => $issueUid,
        ]);
    }

    /**
     * @return array{critical:int,warning:int,info:int}
     */
    public function countOpenBySeverity(int $pageUid, string $siteIdentifier): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $rows = $qb
            ->select('severity')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            )
            ->groupBy('severity')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
        ];

        foreach ($rows as $row) {
            $severity = Severity::fromInt((int)$row['severity']);
            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
            };
            $counts[$key] = (int)$row['cnt'];
        }

        return $counts;
    }

    /**
     * @return array{critical:int,warning:int,info:int,total:int}
     */
    public function countOpenTotalsForSite(string $siteIdentifier): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $rows = $qb
            ->select('i.severity')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->from(Tables::ISSUE, 'i')
            ->leftJoin(
                'i',
                Tables::PAGES,
                'p',
                $qb->expr()->eq('p.uid', 'i.page_uid')
            )
            ->where(
                $qb->expr()->eq('i.site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq(
                    'i.status',
                    $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'i.deleted',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'p.deleted',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->groupBy('i.severity')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $severity = Severity::fromInt((int)$row['severity']);
            $cnt = (int)$row['cnt'];

            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
            };

            $counts[$key] += $cnt;
            $counts['total'] += $cnt;
        }

        return $counts;
    }

    public function countOpenPageStatsForSite(string $siteIdentifier, string $search = ''): int
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $qb
            ->selectLiteral('COUNT(DISTINCT i.page_uid) AS cnt')
            ->from(Tables::ISSUE, 'i')
            ->leftJoin(
                'i',
                Tables::PAGES,
                'p',
                $qb->expr()->eq('p.uid', 'i.page_uid')
            )
            ->where(
                $qb->expr()->eq('i.site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq(
                    'i.status',
                    $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'i.deleted',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'p.deleted',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        $this->addPageStatsSearchConstraint($qb, $search);

        $row = $qb
            ->executeQuery()
            ->fetchAssociative();

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * @return array<int, array{pageUid:int,pageTitle:string,pageDoktype:int,critical:int,warning:int,info:int,total:int}>
     */
    public function findOpenPageStatsForSitePaginated(
        string $siteIdentifier,
        int $limit,
        int $offset,
        string $search = ''
    ): array {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $qb
            ->select(
                'i.page_uid',
                'p.title AS page_title',
                'p.doktype AS page_doktype'
            )
            ->addSelectLiteral(
                'SUM(CASE WHEN i.severity = ' . (int)Severity::Critical->value . ' THEN 1 ELSE 0 END) AS critical',
                'SUM(CASE WHEN i.severity = ' . (int)Severity::Warning->value . ' THEN 1 ELSE 0 END) AS warning',
                'SUM(CASE WHEN i.severity = ' . (int)Severity::Info->value . ' THEN 1 ELSE 0 END) AS info',
                'COUNT(*) AS total'
            )
            ->from(Tables::ISSUE, 'i')
            ->leftJoin(
                'i',
                Tables::PAGES,
                'p',
                $qb->expr()->eq('p.uid', 'i.page_uid')
            )
            ->where(
                $qb->expr()->eq('i.site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq(
                    'i.status',
                    $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'i.deleted',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'p.deleted',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        $this->addPageStatsSearchConstraint($qb, $search);

        $rows = $qb
            ->groupBy('i.page_uid', 'p.title', 'p.doktype')
            ->orderBy('critical', 'DESC')
            ->addOrderBy('warning', 'DESC')
            ->addOrderBy('info', 'DESC')
            ->addOrderBy('total', 'DESC')
            ->addOrderBy('i.page_uid', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(
            static function (array $row): array {
                $pageUid = (int)($row['page_uid'] ?? 0);
                $title = trim((string)($row['page_title'] ?? ''));

                return [
                    'pageUid' => $pageUid,
                    'pageTitle' => $title !== '' ? $title : ('Page ' . $pageUid),
                    'pageDoktype' => (int)($row['page_doktype'] ?? 0),
                    'critical' => (int)($row['critical'] ?? 0),
                    'warning' => (int)($row['warning'] ?? 0),
                    'info' => (int)($row['info'] ?? 0),
                    'total' => (int)($row['total'] ?? 0),
                ];
            },
            $rows
        );
    }

    /**
     * @return array<int, array{pageUid:int,pageTitle:string,pageDoktype:int,critical:int,warning:int,info:int,total:int}>
     */
    public function findOpenPageStatsForSite(string $siteIdentifier, string $search = ''): array
    {
        $total = $this->countOpenPageStatsForSite($siteIdentifier, $search);
        if ($total <= 0) {
            return [];
        }

        return $this->findOpenPageStatsForSitePaginated($siteIdentifier, $total, 0, $search);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOpenForPage(int $pageUid, string $siteIdentifier): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        return $qb
            ->select('*')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            )
            ->orderBy('severity', 'ASC')
            ->addOrderBy('rule_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllForPage(string $siteIdentifier, int $pageUid): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $qb->select('i.*')
            ->from(Tables::ISSUE, 'i')
            ->where(
                $qb->expr()->eq(
                    'i.page_uid',
                    $qb->createNamedParameter($pageUid, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'i.site_identifier',
                    $qb->createNamedParameter($siteIdentifier)
                ),
                $qb->expr()->eq(
                    'i.deleted',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        $this->addExistingPageJoinAndRestrictions($qb, 'i');

        return $qb
            ->orderBy('i.status', 'ASC')
            ->addOrderBy('i.severity', 'ASC')
            ->addOrderBy('i.tstamp', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOpenForRecord(
        string $sourceTable,
        int $sourceUid,
        string $sourceField,
    ): array {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        return $qb
            ->select('*')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('source_table', $qb->createNamedParameter($sourceTable)),
                $qb->expr()->eq('source_uid', $qb->createNamedParameter($sourceUid, Connection::PARAM_INT)),
                $qb->expr()->eq('source_field', $qb->createNamedParameter($sourceField)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            )
            ->orderBy('severity', 'ASC')
            ->addOrderBy('rule_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findForExport(
        string $siteIdentifier,
        ?int $pageUid = null,
        bool $onlyOpen = true,
    ): array {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $qb->select('i.*', 'p.title AS page_title')
            ->from(Tables::ISSUE, 'i')
            ->where(
                $qb->expr()->eq(
                    'i.site_identifier',
                    $qb->createNamedParameter($siteIdentifier)
                ),
                $qb->expr()->eq(
                    'i.deleted',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        $this->addExistingPageJoinAndRestrictions($qb, 'i');

        if ($pageUid !== null) {
            $qb->andWhere(
                $qb->expr()->eq(
                    'i.page_uid',
                    $qb->createNamedParameter($pageUid, Connection::PARAM_INT)
                )
            );
        }

        if ($onlyOpen) {
            $qb->andWhere(
                $qb->expr()->eq(
                    'i.status',
                    $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)
                )
            );
        }

        return $qb
            ->orderBy('i.page_uid', 'ASC')
            ->addOrderBy('i.severity', 'ASC')
            ->addOrderBy('i.rule_id', 'ASC')
            ->addOrderBy('i.tstamp', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByFingerprintPublic(string $fingerprint): ?array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $row = $qb
            ->select('uid', 'status', 'site_identifier')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('fingerprint', $qb->createNamedParameter($fingerprint)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByFingerprint(string $fingerprint, string $siteIdentifier): ?array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $row = $qb
            ->select('uid', 'status', 'first_seen_scan_uid')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('fingerprint', $qb->createNamedParameter($fingerprint)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    private function addExistingPageJoinAndRestrictions(QueryBuilder $qb, string $issueAlias = 'i'): void
    {
        $qb->leftJoin(
            $issueAlias,
            Tables::PAGES,
            'p',
            $qb->expr()->eq('p.uid', $issueAlias . '.page_uid')
        );

        $qb->andWhere(
            $qb->expr()->eq('p.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
        );
    }

    private function addPageStatsSearchConstraint(QueryBuilder $qb, string $search): void
    {
        $search = trim($search);
        if ($search === '') {
            return;
        }

        $titleLike = $qb->createNamedParameter('%' . mb_strtolower($search) . '%');
        $uidLike = $qb->createNamedParameter('%' . $search . '%');

        $qb->andWhere(
            '(LOWER(COALESCE(p.title, \'\')) LIKE ' . $titleLike
            . ' OR CONCAT(i.page_uid, \'\') LIKE ' . $uidLike . ')'
        );
    }
}