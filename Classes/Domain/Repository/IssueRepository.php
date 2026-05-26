<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class IssueRepository extends AbstractRepository
{
    public function __construct(
        ConnectionPool $connectionPool,
        private readonly RuleConfigurationService $ruleConfigurationService,
    ) {
        parent::__construct($connectionPool);
    }

    private function effectiveSourceType(RuleViolation $violation, CheckContext $ctx): string
    {
        $sourceType = trim($violation->sourceType !== '' ? $violation->sourceType : $ctx->sourceType);
        if ($sourceType !== '') {
            return $sourceType;
        }

        return str_starts_with($violation->ruleId, 'structured.') ? 'structured' : 'rte';
    }

    private function effectiveSourceTable(RuleViolation $violation, CheckContext $ctx): string
    {
        return $violation->sourceTable !== '' ? $violation->sourceTable : $ctx->sourceTable;
    }

    private function effectiveSourceUid(RuleViolation $violation, CheckContext $ctx): int
    {
        return $violation->sourceUid > 0 ? $violation->sourceUid : $ctx->sourceUid;
    }

    private function effectiveSourceField(RuleViolation $violation, CheckContext $ctx): string
    {
        return $violation->sourceField !== '' ? $violation->sourceField : $ctx->sourceField;
    }

    /**
     * @return 'inserted'|'updated'|'protected'
     */
    public function upsert(RuleViolation $violation, CheckContext $ctx, int $scanUid): string
    {
        $fingerprint = $violation->fingerprint($ctx);
        $now = time();
        $sourceType = $this->effectiveSourceType($violation, $ctx);
        $sourceTable = $this->effectiveSourceTable($violation, $ctx);
        $sourceUid = $this->effectiveSourceUid($violation, $ctx);
        $sourceField = $this->effectiveSourceField($violation, $ctx);
        $frontendUrl = $violation->frontendUrl !== '' ? $violation->frontendUrl : $ctx->frontendUrl;
        $cssSelector = $violation->cssSelector !== '' ? $violation->cssSelector : $ctx->cssSelector;
        $existing = $this->findByFingerprint($fingerprint, $ctx->siteIdentifier);

        if ($existing === null) {
            $this->getConnection(Tables::ISSUE)->insert(Tables::ISSUE, [
                'site_identifier' => $ctx->siteIdentifier,
                'page_uid' => $ctx->pageUid,
                'source_lang_uid' => $ctx->sourceLangUid,
                'source_table' => $sourceTable,
                'source_uid' => $sourceUid,
                'source_field' => $sourceField,
                'source_type' => $sourceType,
                'frontend_url' => $frontendUrl,
                'css_selector' => $cssSelector,
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
        $isExpiredIgnore = $status === IssueStatus::Ignored
            && (int)($existing['ignored_until'] ?? 0) > 0
            && (int)($existing['ignored_until'] ?? 0) <= $now;

        if ($status->isProtected() && !$isExpiredIgnore) {
            return 'protected';
        }

        $update = [
            'last_seen_scan_uid' => $scanUid,
            'tstamp' => $now,
        ];

        if ($status === IssueStatus::Resolved || $isExpiredIgnore) {
            $update['status'] = IssueStatus::Open->value;
        }

        if ($isExpiredIgnore) {
            $update['ignored_reopened_at'] = $now;
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
        int $backendUserUid = 0,
        string $backendUserName = '',
        string $backendUsername = '',
        array $excludeSourceTypes = [],
    ): int {
        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Resolved->value)
            ->set('resolved_at', (string)$now)
            ->set('tstamp', (string)$now);

        if ($backendUserUid > 0 || $backendUserName !== '' || $backendUsername !== '') {
            $qb->set('resolved_by', (string)$backendUserUid)
                ->set('resolved_by_name', $backendUserName)
                ->set('resolved_by_username', $backendUsername);
        }

        $qb->where(
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

        $excludeSourceTypes = array_values(array_unique(array_filter(array_map(
            static fn (mixed $sourceType): string => trim((string)$sourceType),
            $excludeSourceTypes
        ), static fn (string $sourceType): bool => $sourceType !== '')));
        if ($excludeSourceTypes !== []) {
            $qb->andWhere(
                $qb->expr()->notIn(
                    'source_type',
                    $qb->createNamedParameter($excludeSourceTypes, Connection::PARAM_STR_ARRAY)
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

    public function ignore(
        int $issueUid,
        string $reason,
        int $backendUserUid,
        string $backendUserName = '',
        string $backendUsername = '',
        int $ignoredUntil = 0,
    ): void {
        $now = time();

        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, [
            'status' => IssueStatus::Ignored->value,
            'ignored_reason' => $reason,
            'ignored_by' => $backendUserUid,
            'ignored_by_name' => $backendUserName,
            'ignored_by_username' => $backendUsername,
            'ignored_at' => $now,
            'ignored_until' => $ignoredUntil,
            'ignored_reopened_at' => 0,
            'tstamp' => $now,
        ], [
            'uid' => $issueUid,
        ]);
    }

    public function ignoreManyOpenOnPage(
        array $issueUids,
        string $siteIdentifier,
        int $pageUid,
        int $languageUid,
        string $reason,
        int $backendUserUid,
        string $backendUserName = '',
        string $backendUsername = '',
        int $ignoredUntil = 0,
    ): int {
        $issueUids = array_values(array_unique(array_filter(array_map('intval', $issueUids), static fn(int $uid): bool => $uid > 0)));
        if ($issueUids === [] || $siteIdentifier === '' || $pageUid <= 0 || trim($reason) === '') {
            return 0;
        }

        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Ignored->value)
            ->set('ignored_reason', $reason)
            ->set('ignored_by', (string)$backendUserUid)
            ->set('ignored_by_name', $backendUserName)
            ->set('ignored_by_username', $backendUsername)
            ->set('ignored_at', (string)$now)
            ->set('ignored_until', (string)$ignoredUntil)
            ->set('ignored_reopened_at', '0')
            ->set('tstamp', (string)$now)
            ->where(
                $qb->expr()->in('uid', $qb->createNamedParameter($issueUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            );

        $this->addLanguageConstraint($qb, $languageUid);

        return (int)$qb->executeStatement();
    }

    public function ignoreAllByRuleOnPage(
        string $siteIdentifier,
        int $pageUid,
        int $languageUid,
        string $ruleId,
        string $reason,
        int $backendUserUid,
        string $backendUserName = '',
        string $backendUsername = '',
        int $ignoredUntil = 0,
    ): int {
        if ($siteIdentifier === '' || $pageUid <= 0 || trim($ruleId) === '' || trim($reason) === '') {
            return 0;
        }

        $qb = $this->buildOpenRuleIgnoreQuery($siteIdentifier, $ruleId, $languageUid, $reason, $backendUserUid, $backendUserName, $backendUsername, $ignoredUntil);
        $qb->andWhere(
            $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT))
        );

        return (int)$qb->executeStatement();
    }

    public function ignoreAllByRuleOnSite(
        string $siteIdentifier,
        int $languageUid,
        string $ruleId,
        string $reason,
        int $backendUserUid,
        string $backendUserName = '',
        string $backendUsername = '',
        int $ignoredUntil = 0,
    ): int {
        if ($siteIdentifier === '' || trim($ruleId) === '' || trim($reason) === '') {
            return 0;
        }

        $qb = $this->buildOpenRuleIgnoreQuery($siteIdentifier, $ruleId, $languageUid, $reason, $backendUserUid, $backendUserName, $backendUsername, $ignoredUntil);

        return (int)$qb->executeStatement();
    }

    public function unignore(
        int $issueUid,
        int $backendUserUid = 0,
        string $backendUserName = '',
        string $backendUsername = '',
    ): void {
        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, [
            'status' => IssueStatus::Open->value,
            'ignored_reason' => '',
            'ignored_by' => 0,
            'ignored_by_name' => '',
            'ignored_by_username' => '',
            'ignored_at' => 0,
            'ignored_until' => 0,
            'ignored_reopened_at' => 0,
            'tstamp' => time(),
        ], [
            'uid' => $issueUid,
        ]);
    }

    public function resolve(
        int $issueUid,
        int $backendUserUid,
        string $backendUserName = '',
        string $backendUsername = '',
    ): void {
        $now = time();

        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, [
            'status' => IssueStatus::Resolved->value,
            'resolved_by' => $backendUserUid,
            'resolved_by_name' => $backendUserName,
            'resolved_by_username' => $backendUsername,
            'resolved_at' => $now,
            'tstamp' => $now,
        ], [
            'uid' => $issueUid,
        ]);
    }

    public function createIgnoredFromViolation(
        RuleViolation $violation,
        CheckContext $ctx,
        string $reason,
        int $backendUserUid,
        string $backendUserName,
        string $backendUsername,
    ): int {
        $fingerprint = $violation->fingerprint($ctx);
        $now = time();
        $sourceType = $this->effectiveSourceType($violation, $ctx);
        $sourceTable = $this->effectiveSourceTable($violation, $ctx);
        $sourceUid = $this->effectiveSourceUid($violation, $ctx);
        $sourceField = $this->effectiveSourceField($violation, $ctx);
        $frontendUrl = $violation->frontendUrl !== '' ? $violation->frontendUrl : $ctx->frontendUrl;
        $cssSelector = $violation->cssSelector !== '' ? $violation->cssSelector : $ctx->cssSelector;
        $existing = $this->findByFingerprint($fingerprint, $ctx->siteIdentifier);

        if ($existing !== null) {
            $issueUid = (int)$existing['uid'];
            $this->ignore($issueUid, $reason, $backendUserUid, $backendUserName, $backendUsername);
            return $issueUid;
        }

        $this->getConnection(Tables::ISSUE)->insert(Tables::ISSUE, [
            'site_identifier' => $ctx->siteIdentifier,
            'page_uid' => $ctx->pageUid,
            'source_lang_uid' => $ctx->sourceLangUid,
            'source_table' => $sourceTable,
            'source_uid' => $sourceUid,
            'source_field' => $sourceField,
            'source_type' => $sourceType,
            'frontend_url' => $frontendUrl,
            'css_selector' => $cssSelector,
            'rule_id' => $violation->ruleId,
            'severity' => $violation->severity->value,
            'message' => $violation->message,
            'hint' => $violation->hint,
            'context_snippet' => $violation->contextSnippet,
            'context_path' => $violation->contextPath,
            'fingerprint' => $fingerprint,
            'status' => IssueStatus::Ignored->value,
            'ignored_reason' => $reason,
            'ignored_by' => $backendUserUid,
            'ignored_by_name' => $backendUserName,
            'ignored_by_username' => $backendUsername,
            'ignored_at' => $now,
            'first_seen_scan_uid' => 0,
            'last_seen_scan_uid' => 0,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        $issueUid = (int)$this->getConnection(Tables::ISSUE)->lastInsertId();

        return $issueUid;
    }

    /**
     * @return array{critical:int,warning:int,info:int,needs_review:int}
     */
    public function countOpenBySeverity(int $pageUid, string $siteIdentifier, int $languageUid = -1): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $qb
            ->select('severity')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            );

        $this->addLanguageConstraint($qb, $languageUid);
        $this->addEnabledRuleConstraint($qb, $siteIdentifier);

        $rows = $qb
            ->groupBy('severity')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'needs_review' => 0,
        ];

        foreach ($rows as $row) {
            $severity = Severity::fromInt((int)$row['severity']);
            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
            };
            $counts[$key] = (int)$row['cnt'];
        }

        return $counts;
    }

    /**
     * @return array{critical:int,warning:int,info:int,needs_review:int,total:int}
     */
    public function countOpenTotalsForSite(string $siteIdentifier, int $languageUid = -1): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $qb
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
            );

        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addEnabledRuleConstraint($qb, $siteIdentifier, 'i');

        $rows = $qb
            ->groupBy('i.severity')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'needs_review' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $severity = Severity::fromInt((int)$row['severity']);
            $cnt = (int)$row['cnt'];

            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
            };

            $counts[$key] += $cnt;
            $counts['total'] += $cnt;
        }

        return $counts;
    }

    /**
     * @return array{critical:int,warning:int,info:int,needs_review:int,total:int}
     */
    public function countNewOpenTotalsForScan(string $siteIdentifier, int $scanUid, int $languageUid = -1): array
    {
        if ($scanUid <= 0) {
            return [
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
                'needs_review' => 0,
                'total' => 0,
            ];
        }

        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $qb
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
                    'i.first_seen_scan_uid',
                    $qb->createNamedParameter($scanUid, Connection::PARAM_INT)
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

        $this->addLanguageConstraint($qb, $languageUid, 'i');

        $rows = $qb
            ->groupBy('i.severity')
            ->executeQuery()
            ->fetchAllAssociative();

        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'needs_review' => 0,
            'total' => 0,
        ];

        foreach ($rows as $row) {
            $severity = Severity::fromInt((int)$row['severity']);
            $cnt = (int)$row['cnt'];

            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
            };

            $counts[$key] += $cnt;
            $counts['total'] += $cnt;
        }

        return $counts;
    }

    public function countOpenPageStatsForSite(string $siteIdentifier, string $search = '', int $languageUid = -1): int
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

        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addEnabledRuleConstraint($qb, $siteIdentifier, 'i');
        $this->addPageStatsSearchConstraint($qb, $search);

        $row = $qb
            ->executeQuery()
            ->fetchAssociative();

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * @return array<int, array{pageUid:int,pageTitle:string,pageDoktype:int,critical:int,warning:int,info:int,needs_review:int,total:int}>
     */
    public function findOpenPageStatsForSitePaginated(
        string $siteIdentifier,
        int $limit,
        int $offset,
        string $search = '',
        int $languageUid = -1
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
                'SUM(CASE WHEN i.severity = ' . (int)Severity::NeedsReview->value . ' THEN 1 ELSE 0 END) AS needs_review',
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

        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addEnabledRuleConstraint($qb, $siteIdentifier, 'i');
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
                    'needs_review' => (int)($row['needs_review'] ?? 0),
                    'total' => (int)($row['total'] ?? 0),
                ];
            },
            $rows
        );
    }

    /**
     * @return array<int, array{pageUid:int,pageTitle:string,pageDoktype:int,critical:int,warning:int,info:int,needs_review:int,total:int}>
     */
    public function findOpenPageStatsForSite(string $siteIdentifier, string $search = '', int $languageUid = -1): array
    {
        $total = $this->countOpenPageStatsForSite($siteIdentifier, $search, $languageUid);
        if ($total <= 0) {
            return [];
        }

        return $this->findOpenPageStatsForSitePaginated($siteIdentifier, $total, 0, $search, $languageUid);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOpenForPage(int $pageUid, string $siteIdentifier, int $languageUid = -1): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $qb
            ->select('*')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            );

        $this->addLanguageConstraint($qb, $languageUid);
        $this->addEnabledRuleConstraint($qb, $siteIdentifier);

        return $qb
            ->orderBy('severity', 'ASC')
            ->addOrderBy('rule_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllForPage(string $siteIdentifier, int $pageUid, int $languageUid = -1): array
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
        $this->addLanguageConstraint($qb, $languageUid, 'i');

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

    public function findAccessContextByUid(int $issueUid): ?array
    {
        if ($issueUid <= 0) {
            return null;
        }

        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $row = $qb
            ->select('uid', 'page_uid', 'source_table', 'source_uid', 'site_identifier')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($issueUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
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
    public function findByFingerprintPublic(string $fingerprint, ?string $siteIdentifier = null): ?array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $qb
            ->select('uid', 'status', 'site_identifier', 'source_table', 'source_uid', 'source_field')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('fingerprint', $qb->createNamedParameter($fingerprint)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            );

        if ($siteIdentifier !== null && $siteIdentifier !== '') {
            $qb->andWhere(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier))
            );
        }

        $row = $qb
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByFingerprintForSite(string $fingerprint, string $siteIdentifier): ?array
    {
        return $this->findByFingerprint($fingerprint, $siteIdentifier);
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

    public function countOpenByRuleOnPage(string $siteIdentifier, int $pageUid, int $languageUid = -1): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $qb->select('i.rule_id')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->from(Tables::ISSUE, 'i')
            ->where(
                $qb->expr()->eq('i.site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('i.page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('i.status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $qb->expr()->eq('i.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            );

        $this->addExistingPageJoinAndRestrictions($qb, 'i');
        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addEnabledRuleConstraint($qb, $siteIdentifier, 'i');

        $rows = $qb->groupBy('i.rule_id')->executeQuery()->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)($row['rule_id'] ?? '')] = (int)($row['cnt'] ?? 0);
        }

        return $counts;
    }

    public function countOpenByRuleOnSite(string $siteIdentifier, int $languageUid = -1): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $qb->select('i.rule_id')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->from(Tables::ISSUE, 'i')
            ->where(
                $qb->expr()->eq('i.site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('i.status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $qb->expr()->eq('i.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            );

        $this->addExistingPageJoinAndRestrictions($qb, 'i');
        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addEnabledRuleConstraint($qb, $siteIdentifier, 'i');

        $rows = $qb->groupBy('i.rule_id')->executeQuery()->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)($row['rule_id'] ?? '')] = (int)($row['cnt'] ?? 0);
        }

        return $counts;
    }

    private function buildOpenRuleIgnoreQuery(
        string $siteIdentifier,
        string $ruleId,
        int $languageUid,
        string $reason,
        int $backendUserUid,
        string $backendUserName,
        string $backendUsername,
        int $ignoredUntil,
    ): QueryBuilder {
        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Ignored->value)
            ->set('ignored_reason', $reason)
            ->set('ignored_by', (string)$backendUserUid)
            ->set('ignored_by_name', $backendUserName)
            ->set('ignored_by_username', $backendUsername)
            ->set('ignored_at', (string)$now)
            ->set('ignored_until', (string)$ignoredUntil)
            ->set('ignored_reopened_at', '0')
            ->set('tstamp', (string)$now)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT))
            );

        $this->addLanguageConstraint($qb, $languageUid);

        return $qb;
    }


    public function reopenExpiredIgnoredIssues(string $siteIdentifier = ''): int
    {
        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Open->value)
            ->set('ignored_reopened_at', (string)$now)
            ->set('tstamp', (string)$now)
            ->where(
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Ignored->value, Connection::PARAM_INT)),
                $qb->expr()->gt('ignored_until', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->lte('ignored_until', $qb->createNamedParameter($now, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            );

        if ($siteIdentifier !== '') {
            $qb->andWhere(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier))
            );
        }

        return (int)$qb->executeStatement();
    }

    private function addEnabledRuleConstraint(QueryBuilder $qb, string $siteIdentifier, string $issueAlias = ''): void
    {
        $disabledRuleIds = $this->ruleConfigurationService->getDisabledRuleIdsForSite($siteIdentifier);
        if ($disabledRuleIds === []) {
            return;
        }

        $field = $issueAlias !== '' ? $issueAlias . '.rule_id' : 'rule_id';
        $qb->andWhere(
            $qb->expr()->notIn(
                $field,
                $qb->createNamedParameter($disabledRuleIds, Connection::PARAM_STR_ARRAY)
            )
        );
    }

    private function addLanguageConstraint(QueryBuilder $qb, int $languageUid, string $issueAlias = ''): void
    {
        if ($languageUid < 0) {
            return;
        }

        $field = $issueAlias !== '' ? $issueAlias . '.source_lang_uid' : 'source_lang_uid';
        $qb->andWhere(
            $qb->expr()->eq(
                $field,
                $qb->createNamedParameter($languageUid, Connection::PARAM_INT)
            )
        );
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
