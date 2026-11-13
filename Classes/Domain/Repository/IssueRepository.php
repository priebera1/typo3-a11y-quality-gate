<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Scan\LegacySnippetIdentity;
use Priebera\A11yQualityGate\Contract\LocalFindingSourceAccessInterface;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

final class IssueRepository extends AbstractRepository implements IssueRemediationRepositoryInterface
{
    public function __construct(
        ConnectionPool $connectionPool,
        private readonly RuleConfigurationService $ruleConfigurationService,
        private readonly ?LocalFindingSourceAccessInterface $sourceAccessService = null,
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

    private function effectiveMessage(RuleViolation $violation): string
    {
        if ($violation->ruleId === 'rte.link_new_window_no_warning') {
            return 'Link opens in a new window or tab without warning the user.';
        }

        return $violation->message;
    }

    private function effectiveHint(RuleViolation $violation): string
    {
        if ($violation->ruleId === 'rte.link_new_window_no_warning') {
            return 'Add a visible or accessible hint such as "opens in a new window or tab" so users know what to expect.';
        }

        return $violation->hint;
    }

    /**
     * @return 'inserted'|'updated'|'protected'
     */
    /**
     * Audit C6: open findings of the rendered check that a scan identified as duplicates of a rich-text finding of the
     * same element are retired, not resolved: nothing was fixed, the element is counted once under its rich-text rule.
     * The rows stay in the table (deleted = 1); ignored ones are left as they are.
     *
     * @param list<string> $fingerprints
     */
    public function retireOpenDuplicates(string $siteIdentifier, int $pageUid, array $fingerprints): int
    {
        $fingerprints = array_values(array_unique(array_filter($fingerprints, static fn (string $value): bool => $value !== '')));
        if ($siteIdentifier === '' || $pageUid <= 0 || $fingerprints === []) {
            return 0;
        }

        $qb = $this->getQueryBuilder(Tables::ISSUE);

        return (int)$qb->update(Tables::ISSUE)
            ->set('deleted', 1)
            ->set('tstamp', time())
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('source_type', $qb->createNamedParameter('rendered')),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->in('fingerprint', $qb->createNamedParameter($fingerprints, Connection::PARAM_STR_ARRAY))
            )
            ->executeStatement();
    }

    /**
     * Moves a stored finding to a new identity (fingerprint + source record) when the new identity is not stored yet.
     * Used when the record a rendered finding belongs to is corrected (N-02); status and history stay on the row.
     */
    public function rekeyFinding(string $siteIdentifier, string $oldFingerprint, string $newFingerprint, int $newSourceUid): int
    {
        if ($oldFingerprint === $newFingerprint || $this->findByFingerprint($newFingerprint, $siteIdentifier) !== null) {
            return 0;
        }

        return (int)$this->getConnection(Tables::ISSUE)->update(
            Tables::ISSUE,
            ['fingerprint' => $newFingerprint, 'source_uid' => $newSourceUid, 'tstamp' => time()],
            ['site_identifier' => $siteIdentifier, 'fingerprint' => $oldFingerprint],
        );
    }

    private function insertNewFinding(RuleViolation $violation, CheckContext $ctx, int $scanUid, string $fingerprint, int $now, string $sourceTable, int $sourceUid, string $sourceField, string $sourceType, string $frontendUrl, string $cssSelector, string $message, string $hint): void
    {
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
            'message' => $message,
            'hint' => $hint,
            'context_snippet' => $violation->contextSnippet,
            'context_path' => $violation->contextPath,
            'fingerprint' => $fingerprint,
            'status' => IssueStatus::Open->value,
            'first_seen_scan_uid' => $scanUid,
            'last_seen_scan_uid' => $scanUid,
            'crdate' => $now,
            'tstamp' => $now,
        ]);
    }

    public function upsert(RuleViolation $violation, CheckContext $ctx, int $scanUid, bool $retryAfterConflict = true): string
    {
        $fingerprint = $violation->fingerprint($ctx);
        $now = time();
        $sourceType = $this->effectiveSourceType($violation, $ctx);
        $sourceTable = $this->effectiveSourceTable($violation, $ctx);
        $sourceUid = $this->effectiveSourceUid($violation, $ctx);
        $sourceField = $this->effectiveSourceField($violation, $ctx);
        $frontendUrl = $violation->frontendUrl !== '' ? $violation->frontendUrl : $ctx->frontendUrl;
        $cssSelector = $violation->cssSelector !== '' ? $violation->cssSelector : $ctx->cssSelector;
        $message = $this->effectiveMessage($violation);
        $hint = $this->effectiveHint($violation);
        $existing = $this->findByFingerprint($fingerprint, $ctx->siteIdentifier);
        $recoveredBySourceAndRule = false;

        if ($existing === null) {
            $existing = $this->findLegacyIdentity($violation, $ctx, $fingerprint, $scanUid, [
                'source_table' => $sourceTable,
                'source_uid' => $sourceUid,
                'source_field' => $sourceField,
                'source_type' => $sourceType,
            ]);
            $recoveredBySourceAndRule = $existing !== null;
            if ($existing !== null && IssueStatus::fromInt((int)$existing['status'])->isProtected()) {
                // An ignored or muted finding keeps its decision; only its identity follows the 1.9.9 snippet.
                $this->getConnection(Tables::ISSUE)->update(
                    Tables::ISSUE,
                    ['fingerprint' => $fingerprint, 'context_snippet' => $violation->contextSnippet],
                    ['uid' => (int)$existing['uid']]
                );
            }
        }

        if ($existing === null) {
            $existing = $this->findResolvedBySourceAndRule(
                siteIdentifier: $ctx->siteIdentifier,
                pageUid: $ctx->pageUid,
                sourceLangUid: $ctx->sourceLangUid,
                sourceTable: $sourceTable,
                sourceUid: $sourceUid,
                sourceField: $sourceField,
                sourceType: $sourceType,
                ruleId: $violation->ruleId,
            );
            $recoveredBySourceAndRule = $existing !== null;
        }

        if ($existing === null) {
            try {
                $this->insertNewFinding($violation, $ctx, $scanUid, $fingerprint, $now, $sourceTable, $sourceUid, $sourceField, $sourceType, $frontendUrl, $cssSelector, $message, $hint);
            } catch (UniqueConstraintViolationException $conflict) {
                // NEW-06: another scan of the same page (a second editor, the publish gate, the CLI) stored this finding
                // between our lookup and our insert. The finding exists now: continue as an update of that row.
                if (!$retryAfterConflict) {
                    throw $conflict;
                }

                return $this->upsert($violation, $ctx, $scanUid, false);
            }

            return 'inserted';
        }

        $status = IssueStatus::fromInt((int)$existing['status']);
        $isExpiredIgnore = $status === IssueStatus::Ignored
            && (int)($existing['ignored_until'] ?? 0) > 0
            && (int)($existing['ignored_until'] ?? 0) <= $now;

        if ($status->isProtected() && !$isExpiredIgnore) {
            $this->touchProtectedSeenIssue(
                issueUid: (int)$existing['uid'],
                violation: $violation,
                message: $message,
                hint: $hint,
                frontendUrl: $frontendUrl,
                cssSelector: $cssSelector,
                scanUid: $scanUid,
                now: $now,
            );

            return 'protected';
        }

        $update = [
            'severity' => $violation->severity->value,
            'message' => $message,
            'hint' => $hint,
            'context_snippet' => $violation->contextSnippet,
            'context_path' => $violation->contextPath,
            'frontend_url' => $frontendUrl,
            'css_selector' => $cssSelector,
            'last_seen_scan_uid' => $scanUid,
            'tstamp' => $now,
        ];

        if ($status === IssueStatus::Resolved || $isExpiredIgnore) {
            $update['status'] = IssueStatus::Open->value;
        }

        if ($recoveredBySourceAndRule) {
            $update['fingerprint'] = $fingerprint;
        }

        if ($isExpiredIgnore) {
            $update['ignored_reopened_at'] = $now;
        }

        if ((int)($existing['deleted'] ?? 0) === 1) {
            // A retired duplicate reported on its own again is an open finding again (same row, same history).
            $update['deleted'] = 0;
            $update['status'] = IssueStatus::Open->value;
        }

        $where = $recoveredBySourceAndRule
            ? ['uid' => (int)$existing['uid']]
            : [
                'site_identifier' => $ctx->siteIdentifier,
                'fingerprint' => $fingerprint,
            ];

        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, $update, $where);

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
        ?array $includeRuleIds = null,
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
            // Only open issues: an issue resolved by an earlier scan stays resolved with its original time and
            // author, and is not counted as resolved again by every unchanged scan.
            $qb->expr()->eq(
                'status',
                $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)
            ),
            // UPDATE queries carry no deleted restriction: a retired duplicate (audit C6) is not a resolved issue.
            $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
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

        if ($includeRuleIds !== null) {
            $includeRuleIds = array_values(array_unique(array_filter(array_map(
                static fn (mixed $ruleId): string => trim((string)$ruleId),
                $includeRuleIds
            ), static fn (string $ruleId): bool => $ruleId !== '')));

            if ($includeRuleIds === []) {
                return 0;
            }

            $qb->andWhere(
                $qb->expr()->in(
                    'rule_id',
                    $qb->createNamedParameter($includeRuleIds, Connection::PARAM_STR_ARRAY)
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


    /**
     * Resolves old rendered issues that were clearly created from technical error HTML.
     *
     * This is intentionally narrower than a generic rendered fetch failure cleanup: only
     * snippets containing known technical error patterns are resolved, so legitimate
     * rendered findings stay open when a later fetch fails or returns an error page.
     */
    public function resolveOpenRuleForPage(
        int $pageUid,
        string $siteIdentifier,
        int $sourceLangUid,
        string $ruleId,
        int $scanUid,
    ): int {
        if ($pageUid <= 0 || trim($siteIdentifier) === '' || trim($ruleId) === '') {
            return 0;
        }

        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Resolved->value)
            ->set('resolved_at', (string)$now)
            ->set('last_seen_scan_uid', (string)$scanUid)
            ->set('tstamp', (string)$now)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            );

        if ($sourceLangUid >= 0) {
            $qb->andWhere(
                $qb->expr()->eq('source_lang_uid', $qb->createNamedParameter($sourceLangUid, Connection::PARAM_INT))
            );
        }

        return (int)$qb->executeStatement();
    }

    /**
     * D-03: open findings of a page stored under a site identifier that is no longer configured (a renamed or removed
     * site). The page was just scanned under its current site, so they are superseded: resolved, kept as history, never
     * moved to another site.
     *
     * @param list<string> $configuredSiteIdentifiers
     */
    public function resolveOpenOfUnconfiguredSitesForPage(int $pageUid, array $configuredSiteIdentifiers, int $scanUid): int
    {
        $configuredSiteIdentifiers = array_values(array_filter(array_map('strval', $configuredSiteIdentifiers), static fn (string $id): bool => $id !== ''));
        if ($pageUid <= 0 || $configuredSiteIdentifiers === []) {
            return 0;
        }

        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Resolved->value)
            ->set('resolved_at', (string)$now)
            ->set('last_seen_scan_uid', (string)$scanUid)
            ->set('tstamp', (string)$now)
            ->where(
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->notIn('site_identifier', $qb->createNamedParameter($configuredSiteIdentifiers, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            );

        return (int)$qb->executeStatement();
    }

    /**
     * U-09: open findings of the given sites whose page, or whose source record, is deleted or no longer exists. Their content is
     * gone, so they are resolved as history (never deleted). Only tables with TCA and a delete field are checked; rows of
     * unknown tables are left alone.
     *
     * @param list<string> $siteIdentifiers the scanned site and the identifiers no longer configured
     */
    public function resolveOpenOfDeletedSources(array $siteIdentifiers, int $scanUid): int
    {
        $siteIdentifiers = array_values(array_unique(array_filter(array_map('strval', $siteIdentifiers), static fn (string $id): bool => trim($id) !== '')));
        if ($siteIdentifiers === []) {
            return 0;
        }
        $resolved = $this->resolveOpenWhere($siteIdentifiers, $scanUid, 'page_uid > 0 AND page_uid NOT IN (SELECT uid FROM pages WHERE deleted = 0)');

        return $resolved + $this->resolveOpenOfDeletedRecords($siteIdentifiers, $scanUid, '');
    }

    /**
     * ML-02: open findings of deleted records on one page, in one language (-1: every language). A page scan that finds
     * no record left in a language evaluates no rule there, so these findings were only retired by a full site scan,
     * and the page's Quality Gate kept counting content that no longer exists. Findings of other pages, languages and
     * sites are left to their own scans.
     */
    public function resolveOpenOfDeletedRecordsOnPage(string $siteIdentifier, int $pageUid, int $languageUid, int $scanUid): int
    {
        if (trim($siteIdentifier) === '' || $pageUid <= 0) {
            return 0;
        }
        $scope = sprintf('page_uid = %d', $pageUid);
        if ($languageUid >= 0) {
            $scope .= sprintf(' AND source_lang_uid = %d', $languageUid);
        }

        return $this->resolveOpenOfDeletedRecords([$siteIdentifier], $scanUid, $scope);
    }

    /** @param list<string> $siteIdentifiers */
    private function resolveOpenOfDeletedRecords(array $siteIdentifiers, int $scanUid, string $scope): int
    {
        $resolved = 0;
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();
        $qb->selectLiteral('DISTINCT source_table')->from(Tables::ISSUE)
            ->where(
                $qb->expr()->in('site_identifier', $qb->createNamedParameter($siteIdentifiers, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $qb->expr()->gt('source_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            );
        if ($scope !== '') {
            $qb->andWhere($scope);
        }
        $tables = $qb->executeQuery()->fetchFirstColumn();
        foreach ($tables as $table) {
            $table = (string)$table;
            $deleteField = (string)($GLOBALS['TCA'][$table]['ctrl']['delete'] ?? '');
            if (preg_match('/^[a-z0-9_]+$/', $table) !== 1 || preg_match('/^[a-z0-9_]+$/', $deleteField) !== 1) {
                continue;
            }
            $connection = $this->getConnection(Tables::ISSUE);
            // Correlated lookup by primary key: cheap per finding, also when a page scan runs it for every page.
            $resolved += $this->resolveOpenWhere($siteIdentifiers, $scanUid, sprintf(
                'source_table = %1$s AND source_uid > 0 AND NOT EXISTS (SELECT 1 FROM %2$s live_source WHERE live_source.uid = %3$s.source_uid AND live_source.%4$s = 0)',
                $connection->quote($table),
                $connection->quoteIdentifier($table),
                $connection->quoteIdentifier(Tables::ISSUE),
                $connection->quoteIdentifier($deleteField)
            ) . ($scope !== '' ? ' AND ' . $scope : ''));
        }

        return $resolved;
    }

    /**
     * Site identifiers stored on findings that are not in $configured (renamed or removed sites, D-03/U-09).
     *
     * @param list<string> $configured
     * @return list<string>
     */
    public function findSiteIdentifiersNotIn(array $configured): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();
        $qb->selectLiteral('DISTINCT site_identifier')->from(Tables::ISSUE)
            ->where($qb->expr()->neq('site_identifier', $qb->createNamedParameter('')));
        if ($configured !== []) {
            $qb->andWhere($qb->expr()->notIn('site_identifier', $qb->createNamedParameter(array_values($configured), Connection::PARAM_STR_ARRAY)));
        }

        return array_values(array_map('strval', $qb->executeQuery()->fetchFirstColumn()));
    }

    /** @param list<string> $siteIdentifiers */
    private function resolveOpenWhere(array $siteIdentifiers, int $scanUid, string $condition): int
    {
        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Resolved->value)
            ->set('resolved_at', (string)$now)
            ->set('last_seen_scan_uid', (string)$scanUid)
            ->set('tstamp', (string)$now)
            ->where(
                $qb->expr()->in('site_identifier', $qb->createNamedParameter($siteIdentifiers, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $condition,
            );

        return (int)$qb->executeStatement();
    }

    /**
     * D-02: open rendered findings stored on page translation rows (older releases scanned a translation as a page of
     * its own, language 0). The translation was just rendered in its own language under its default page, so these rows
     * are superseded evidence: resolved, not deleted.
     *
     * @param list<int> $pageUids translation page uids
     */
    public function resolveRenderedOnTranslationRows(array $pageUids, string $siteIdentifier, int $scanUid): int
    {
        $pageUids = array_values(array_filter(array_map('intval', $pageUids), static fn (int $uid): bool => $uid > 0));
        if ($pageUids === [] || trim($siteIdentifier) === '') {
            return 0;
        }

        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Resolved->value)
            ->set('resolved_at', (string)$now)
            ->set('last_seen_scan_uid', (string)$scanUid)
            ->set('tstamp', (string)$now)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->in('page_uid', $qb->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('source_type', $qb->createNamedParameter('rendered')),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            );

        return (int)$qb->executeStatement();
    }

    public function resolveRenderedTechnicalErrorSnippetIssues(
        int $pageUid,
        string $siteIdentifier,
        int $sourceLangUid,
        int $scanUid,
    ): int {
        if ($pageUid <= 0 || trim($siteIdentifier) === '') {
            return 0;
        }

        $now = time();
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->update(Tables::ISSUE)
            ->set('status', (string)IssueStatus::Resolved->value)
            ->set('resolved_at', (string)$now)
            ->set('last_seen_scan_uid', (string)$scanUid)
            ->set('tstamp', (string)$now)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('source_type', $qb->createNamedParameter('rendered')),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            );

        if ($sourceLangUid >= 0) {
            $qb->andWhere(
                $qb->expr()->eq('source_lang_uid', $qb->createNamedParameter($sourceLangUid, Connection::PARAM_INT))
            );
        }

        $or = [];
        foreach ([
            '%Application exception%',
            '%Technical error%',
            '%TYPO3 Exception%',
            '%Uncaught TYPO3 Exception%',
            '%Core: Exception handler%',
            '%Oops, an error occurred!%',
            '%Symfony debug%',
            '%sf-reset%',
            '%sf-body%',
            '%PHP Fatal error%',
            '%Fatal error:%',
            '%Stack trace:%',
            '%cURL error%',
            '%Client error:%',
            '%upstream timed out%',
            '%Connection refused%',
            '%Name or service not known%',
        ] as $pattern) {
            $or[] = $qb->expr()->like('context_snippet', $qb->createNamedParameter($pattern));
        }

        foreach ([
            ['%Exception%', '%Server error%'],
            ['%Exception%', '%connect fail%'],
            ['%Exception%', '%connection fail%'],
            ['%Exception%', '%GET http%'],
            ['%Server error:%', '%GET http%'],
        ] as [$firstPattern, $secondPattern]) {
            $or[] = $qb->expr()->and(
                $qb->expr()->like('context_snippet', $qb->createNamedParameter($firstPattern)),
                $qb->expr()->like('context_snippet', $qb->createNamedParameter($secondPattern)),
            );
        }

        $qb->andWhere($qb->expr()->or(...$or));

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
        string $editablePagesClause = '',
    ): int {
        if ($siteIdentifier === '' || trim($ruleId) === '' || trim($reason) === '') {
            return 0;
        }

        $qb = $this->buildOpenRuleIgnoreQuery($siteIdentifier, $ruleId, $languageUid, $reason, $backendUserUid, $backendUserName, $backendUsername, $ignoredUntil);
        if ($editablePagesClause !== '') {
            // Only findings on pages whose content the user may edit (ScopeAccessService::contentEditablePagesClause()).
            $qb->andWhere($qb->expr()->in('page_uid', 'SELECT pages.uid FROM ' . Tables::PAGES . ' pages WHERE ' . $editablePagesClause));
        }

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
        $message = $this->effectiveMessage($violation);
        $hint = $this->effectiveHint($violation);
        $existing = $this->findByFingerprint($fingerprint, $ctx->siteIdentifier);

        if ($existing !== null) {
            $issueUid = (int)$existing['uid'];
            if ((int)($existing['deleted'] ?? 0) === 1) {
                $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, ['deleted' => 0, 'tstamp' => $now], ['uid' => $issueUid]);
            }
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
            'message' => $message,
            'hint' => $hint,
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
    /** @param string $sourceType only findings of this source type ('rendered', 'rte', …); '' counts all */
    public function countOpenBySeverity(int $pageUid, string $siteIdentifier, int $languageUid = -1, string $sourceType = '', bool $readableSourcesOnly = false): array
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
        if ($sourceType !== '') {
            $qb->andWhere($qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType)));
        }

        $this->addLanguageConstraint($qb, $languageUid);
        $this->addEnabledRuleConstraint($qb, $siteIdentifier);
        if ($readableSourcesOnly) {
            $this->addReadableSourceConstraint($qb, $siteIdentifier, '');
        }

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
    /**
     * The fingerprints among $fingerprints whose stored finding of the site is ignored or muted, as a scan would keep
     * them out of the open findings (upsert()): an ignore that has run out does not protect.
     *
     * @param list<string> $fingerprints
     * @return list<string>
     */
    public function findProtectedFingerprints(string $siteIdentifier, array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }
        $protected = [];
        $now = time();
        foreach (array_chunk(array_values(array_unique($fingerprints)), 500) as $chunk) {
            $qb = $this->getQueryBuilder(Tables::ISSUE);
            $qb->getRestrictions()->removeAll();
            $rows = $qb->select('fingerprint', 'status', 'ignored_until')
                ->from(Tables::ISSUE)
                ->where(
                    $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                    $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                    $qb->expr()->in('fingerprint', $qb->createNamedParameter($chunk, Connection::PARAM_STR_ARRAY)),
                )
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $status = IssueStatus::fromInt((int)$row['status']);
                $expiredIgnore = $status === IssueStatus::Ignored && (int)$row['ignored_until'] > 0 && (int)$row['ignored_until'] <= $now;
                if ($status->isProtected() && !$expiredIgnore) {
                    $protected[] = (string)$row['fingerprint'];
                }
            }
        }

        return $protected;
    }

    public function countOpenTotalsForSite(string $siteIdentifier, int $languageUid = -1, string $readablePagesClause = ''): array
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
                ),
                $qb->expr()->eq(
                    'p.t3ver_wsid',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addReadablePagesConstraint($qb, $readablePagesClause, $siteIdentifier);
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
    public function countNewOpenTotalsForScan(string $siteIdentifier, int $scanUid, int $languageUid = -1, string $readablePagesClause = ''): array
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
                ),
                $qb->expr()->eq(
                    'p.t3ver_wsid',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addReadablePagesConstraint($qb, $readablePagesClause, $siteIdentifier);
        // Like countOpenTotalsForSite(): an issue of a disabled rule is not open, so "new" never exceeds "open".
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

    public function countOpenPageStatsForSite(string $siteIdentifier, string $search = '', int $languageUid = -1, string $readablePagesClause = ''): int
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
                ),
                $qb->expr()->eq(
                    'p.t3ver_wsid',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addReadablePagesConstraint($qb, $readablePagesClause, $siteIdentifier);
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
        int $languageUid = -1,
        string $readablePagesClause = ''
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
                ),
                $qb->expr()->eq(
                    'p.t3ver_wsid',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addReadablePagesConstraint($qb, $readablePagesClause, $siteIdentifier);
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
    public function findOpenPageStatsForSite(string $siteIdentifier, string $search = '', int $languageUid = -1, string $readablePagesClause = ''): array
    {
        $total = $this->countOpenPageStatsForSite($siteIdentifier, $search, $languageUid, $readablePagesClause);
        if ($total <= 0) {
            return [];
        }

        return $this->findOpenPageStatsForSitePaginated($siteIdentifier, $total, 0, $search, $languageUid, $readablePagesClause);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOpenForPage(int $pageUid, string $siteIdentifier, int $languageUid = -1, bool $readableSourcesOnly = false): array
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
        if ($readableSourcesOnly) {
            $this->addReadableSourceConstraint($qb, $siteIdentifier, '');
        }

        return $qb
            ->orderBy('severity', 'ASC')
            ->addOrderBy('rule_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAllForPage(string $siteIdentifier, int $pageUid, int $languageUid = -1, bool $readableSourcesOnly = false): array
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
        if ($readableSourcesOnly) {
            $this->addReadableSourceConstraint($qb, $siteIdentifier, 'i');
        }

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
        ?string $siteIdentifier = null,
    ): array {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $qb
            ->select('*')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('source_table', $qb->createNamedParameter($sourceTable)),
                $qb->expr()->eq('source_uid', $qb->createNamedParameter($sourceUid, Connection::PARAM_INT)),
                $qb->expr()->eq('source_field', $qb->createNamedParameter($sourceField)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
            );
        if ($siteIdentifier !== null && $siteIdentifier !== '') {
            // D-03: a finding stored under another (e.g. renamed) site identifier is no finding of the record's site.
            $qb->andWhere($qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)));
        }

        return $qb
            ->orderBy('severity', 'ASC')
            ->addOrderBy('rule_id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    /**
     * Pages with open rich-text findings of $ruleIds on $siteIdentifiers, most severe first: the AI fixes empty state
     * links to the first one the person may edit ("Prepare your first AI fix"). Most severe first (Critical = 1), per
     * page and language, at most $limit.
     *
     * @param list<string> $siteIdentifiers
     * @param list<string> $ruleIds
     * @return list<array{page_uid:int,site_identifier:string,language_uid:int,findings:int}>
     */
    public function findPagesWithOpenRichTextFindings(array $siteIdentifiers, array $ruleIds, int $limit = 200, bool $readableSourcesOnly = false): array
    {
        if ($siteIdentifiers === [] || $ruleIds === []) {
            return [];
        }
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->select('page_uid', 'site_identifier', 'source_lang_uid')
            ->addSelectLiteral('COUNT(*) AS findings', 'MIN(severity) AS top_severity')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->in('site_identifier', $qb->createNamedParameter($siteIdentifiers, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->in('rule_id', $qb->createNamedParameter($ruleIds, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->eq('source_type', $qb->createNamedParameter('rte')),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('page_uid', $qb->createNamedParameter(0, Connection::PARAM_INT))
            );
        if ($readableSourcesOnly) {
            $this->addReadableSourceConstraint($qb, '', '');
        }
        $rows = $qb
            ->groupBy('page_uid', 'site_identifier', 'source_lang_uid')
            ->orderBy('top_severity', 'ASC')
            ->addOrderBy('findings', 'DESC')
            ->addOrderBy('page_uid', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'page_uid' => (int)$row['page_uid'],
            'site_identifier' => (string)$row['site_identifier'],
            'language_uid' => (int)$row['source_lang_uid'],
            'findings' => (int)$row['findings'],
        ], $rows);
    }

    /**
     * Agent access (MCP aqg_list_content_findings): one page of the findings of a site or a page that the current
     * backend user may read, with the same restrictions as the module's lists — readable source (always, also for
     * administrators the predicate is applied; it is unrestricted for them), enabled rules, live existing pages.
     * Ordered by status, severity, rule and uid, so pages are stable.
     *
     * Statuses are effective ones: an ignore whose `ignored_until` has passed counts as open, as the module shows it
     * after reopenExpiredIgnoredIssues() — without writing (agent reads never change a finding).
     *
     * @param string $statusKey open|resolved|ignored|all
     * @param list<int> $severities Severity values; [] = every severity
     * @return list<array<string, mixed>>
     */
    public function findForAgent(
        string $siteIdentifier,
        ?int $pageUid,
        int $languageUid,
        string $statusKey,
        array $severities,
        string $sourceType,
        ?string $ruleId,
        int $offset,
        int $limit,
    ): array {
        $qb = $this->agentFindingsQuery($siteIdentifier, $pageUid, $languageUid, $statusKey, $severities, $sourceType, $ruleId);
        $qb->select('i.*')
            ->orderBy('i.status', 'ASC')
            ->addOrderBy('i.severity', 'ASC')
            ->addOrderBy('i.rule_id', 'ASC')
            ->addOrderBy('i.uid', 'ASC')
            ->setFirstResult(max(0, $offset))
            ->setMaxResults(max(1, min(50, $limit)));

        return array_values($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * The total and per-severity counts of the same filtered set as {@see findForAgent()}.
     *
     * @param list<int> $severities
     * @return array{total: int, critical: int, warning: int, info: int, needs_review: int}
     */
    public function countForAgent(
        string $siteIdentifier,
        ?int $pageUid,
        int $languageUid,
        string $statusKey,
        array $severities,
        string $sourceType,
        ?string $ruleId,
    ): array {
        $qb = $this->agentFindingsQuery($siteIdentifier, $pageUid, $languageUid, $statusKey, $severities, $sourceType, $ruleId);
        $qb->select('i.severity')->addSelectLiteral('COUNT(*) AS cnt')->groupBy('i.severity');
        $counts = ['total' => 0, 'critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0];
        foreach ($qb->executeQuery()->fetchAllAssociative() as $row) {
            $key = Severity::fromInt((int)$row['severity'])->key();
            $counts[$key] += (int)$row['cnt'];
            $counts['total'] += (int)$row['cnt'];
        }

        return $counts;
    }

    /**
     * One finding for an agent: the row only when it belongs to the site, its rule is enabled, its page is live and the
     * current backend user may read its source — otherwise null (unknown and unreadable look the same).
     *
     * @return array<string, mixed>|null
     */
    public function findReadableForAgent(string $siteIdentifier, int $issueUid): ?array
    {
        if ($issueUid <= 0 || $siteIdentifier === '') {
            return null;
        }
        $qb = $this->agentFindingsQuery($siteIdentifier, null, -1, 'all', [], 'all', null);
        $qb->select('i.*')
            ->andWhere($qb->expr()->eq('i.uid', $qb->createNamedParameter($issueUid, Connection::PARAM_INT)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @param list<int> $severities
     */
    private function agentFindingsQuery(
        string $siteIdentifier,
        ?int $pageUid,
        int $languageUid,
        string $statusKey,
        array $severities,
        string $sourceType,
        ?string $ruleId,
    ): QueryBuilder {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();
        $qb->from(Tables::ISSUE, 'i')
            ->where(
                $qb->expr()->eq('i.site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('i.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            );
        if ($pageUid !== null) {
            $qb->andWhere($qb->expr()->eq('i.page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)));
        }
        $now = time();
        $expiredIgnore = $qb->expr()->and(
            $qb->expr()->eq('i.status', $qb->createNamedParameter(IssueStatus::Ignored->value, Connection::PARAM_INT)),
            $qb->expr()->gt('i.ignored_until', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->lte('i.ignored_until', $qb->createNamedParameter($now, Connection::PARAM_INT)),
        );
        match ($statusKey) {
            'open' => $qb->andWhere($qb->expr()->or(
                $qb->expr()->eq('i.status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $expiredIgnore,
            )),
            'resolved' => $qb->andWhere($qb->expr()->eq('i.status', $qb->createNamedParameter(IssueStatus::Resolved->value, Connection::PARAM_INT))),
            'ignored' => $qb->andWhere(
                $qb->expr()->in('i.status', $qb->createNamedParameter([IssueStatus::Ignored->value, IssueStatus::Muted->value], Connection::PARAM_INT_ARRAY)),
                'NOT (' . $expiredIgnore . ')',
            ),
            default => null,
        };
        if ($severities !== []) {
            $qb->andWhere($qb->expr()->in('i.severity', $qb->createNamedParameter(array_map('intval', $severities), Connection::PARAM_INT_ARRAY)));
        }
        if ($sourceType !== 'all' && $sourceType !== '') {
            // Older rows without a source type are classified like the module does (PageDetailController).
            $qb->andWhere(match ($sourceType) {
                'rendered' => $qb->expr()->or(
                    $qb->expr()->eq('i.source_type', $qb->createNamedParameter('rendered')),
                    $qb->expr()->and($qb->expr()->eq('i.source_type', $qb->createNamedParameter('')), $qb->expr()->like('i.rule_id', $qb->createNamedParameter('rendered.%'))),
                ),
                'structured' => $qb->expr()->or(
                    $qb->expr()->eq('i.source_type', $qb->createNamedParameter('structured')),
                    $qb->expr()->and($qb->expr()->eq('i.source_type', $qb->createNamedParameter('')), $qb->expr()->like('i.rule_id', $qb->createNamedParameter('structured.%'))),
                ),
                default => $qb->expr()->and(
                    $qb->expr()->neq('i.source_type', $qb->createNamedParameter('rendered')),
                    $qb->expr()->neq('i.source_type', $qb->createNamedParameter('structured')),
                    $qb->expr()->notLike('i.rule_id', $qb->createNamedParameter('rendered.%')),
                    $qb->expr()->notLike('i.rule_id', $qb->createNamedParameter('structured.%')),
                ),
            });
        }
        if ($ruleId !== null && $ruleId !== '') {
            $qb->andWhere($qb->expr()->eq('i.rule_id', $qb->createNamedParameter($ruleId)));
        }
        $this->addExistingPageJoinAndRestrictions($qb, 'i');
        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addEnabledRuleConstraint($qb, $siteIdentifier, 'i');
        // Always: the predicate of every native reader; unrestricted (no condition) only for administrators.
        $this->addReadableSourceConstraint($qb, $siteIdentifier, 'i');

        return $qb;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $issueUid): ?array
    {
        if ($issueUid <= 0) {
            return null;
        }

        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $row = $qb
            ->select('*')
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

    public function markOpenAfterRemediation(int $issueUid): void
    {
        if ($issueUid <= 0) {
            return;
        }

        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, [
            'status' => IssueStatus::Open->value,
            'resolved_at' => 0,
            'tstamp' => time(),
        ], [
            'uid' => $issueUid,
            'deleted' => 0,
        ]);
    }

    public function markResolvedAfterRemediation(
        int $issueUid,
        int $resolvedBy = 0,
        string $resolvedByName = '',
        string $resolvedByUsername = '',
    ): void
    {
        if ($issueUid <= 0) {
            return;
        }

        $now = time();
        $this->getConnection(Tables::ISSUE)->update(Tables::ISSUE, [
            'status' => IssueStatus::Resolved->value,
            'resolved_at' => $now,
            'resolved_by' => $resolvedBy,
            'resolved_by_name' => $resolvedByName,
            'resolved_by_username' => $resolvedByUsername,
            'tstamp' => $now,
        ], [
            'uid' => $issueUid,
            'deleted' => 0,
        ]);
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
        int $languageUid = -1,
        string $readablePagesClause = '',
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

        $this->addLanguageConstraint($qb, $languageUid, 'i');
        $this->addReadablePagesConstraint($qb, $readablePagesClause, $siteIdentifier);

        return $qb
            ->orderBy('i.page_uid', 'ASC')
            ->addOrderBy('i.severity', 'ASC')
            ->addOrderBy('i.rule_id', 'ASC')
            ->addOrderBy('i.tstamp', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    private function touchProtectedSeenIssue(
        int $issueUid,
        RuleViolation $violation,
        string $message,
        string $hint,
        string $frontendUrl,
        string $cssSelector,
        int $scanUid,
        int $now,
    ): void {
        // Intentionally no-op. Ignored and muted issues are protected user
        // decisions and must not be overwritten by later scans, even when the
        // same finding is detected again. In particular, last_seen_scan_uid must
        // keep the original value so protected issues do not look like active
        // findings from the latest scan. Expired ignores are handled before this
        // method and may be reopened explicitly.
        if ($issueUid <= 0) {
            return;
        }
    }

    /**
     * The stored finding of this element when it was recorded with a snippet written before 1.9.9 (marker attributes,
     * byte entities; see LegacySnippetIdentity): same page, language, source, rule and context path, and the stored
     * snippet rewritten the 1.9.9 way gives this violation's fingerprint. Whatever its status, the finding keeps its
     * identity instead of being resolved and recorded again.
     *
     * @param array{source_table:string,source_uid:int,source_field:string,source_type:string} $source
     * @return array<string, mixed>|null
     */
    private function findLegacyIdentity(RuleViolation $violation, CheckContext $ctx, string $fingerprint, int $scanUid, array $source): ?array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $rows = $qb->select('uid', 'status', 'first_seen_scan_uid', 'ignored_until', 'context_snippet', 'context_path', 'css_selector')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($ctx->siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($ctx->pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('source_lang_uid', $qb->createNamedParameter($ctx->sourceLangUid, Connection::PARAM_INT)),
                $qb->expr()->eq('source_table', $qb->createNamedParameter($source['source_table'])),
                $qb->expr()->eq('source_uid', $qb->createNamedParameter($source['source_uid'], Connection::PARAM_INT)),
                $qb->expr()->eq('source_field', $qb->createNamedParameter($source['source_field'])),
                $qb->expr()->eq('source_type', $qb->createNamedParameter($source['source_type'])),
                $qb->expr()->eq('rule_id', $qb->createNamedParameter($violation->ruleId)),
                $qb->expr()->neq('fingerprint', $qb->createNamedParameter($fingerprint)),
                // A finding this scan already matched is another element.
                $qb->expr()->neq('last_seen_scan_uid', $qb->createNamedParameter($scanUid, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        foreach ($rows as $row) {
            $upgradedSnippet = LegacySnippetIdentity::upgrade((string)($row['context_snippet'] ?? ''));
            if ($upgradedSnippet === null) {
                continue;
            }

            $storedForm = new RuleViolation(
                ruleId: $violation->ruleId,
                severity: $violation->severity,
                message: $violation->message,
                hint: $violation->hint,
                contextSnippet: $upgradedSnippet,
                contextPath: (string)($row['context_path'] ?? ''),
                sourceType: $source['source_type'],
                frontendUrl: $violation->frontendUrl,
                cssSelector: (string)($row['css_selector'] ?? ''),
                sourceTable: $source['source_table'],
                sourceUid: $source['source_uid'],
                sourceField: $source['source_field'],
            );
            if (hash_equals($fingerprint, $storedForm->fingerprint($ctx))) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Finds an old resolved issue for the same source/rule when the scanner still
     * detects the finding but the generated fingerprint changed because snippet
     * or context-path normalization changed between releases. Ignored and muted
     * issues remain protected and are intentionally not reopened by this fallback.
     *
     * @return array<string, mixed>|null
     */
    private function findResolvedBySourceAndRule(
        string $siteIdentifier,
        int $pageUid,
        int $sourceLangUid,
        string $sourceTable,
        int $sourceUid,
        string $sourceField,
        string $sourceType,
        string $ruleId,
    ): ?array {
        $qb = $this->getQueryBuilder(Tables::ISSUE);

        $qb->select('uid', 'status', 'first_seen_scan_uid', 'ignored_until')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('source_lang_uid', $qb->createNamedParameter($sourceLangUid, Connection::PARAM_INT)),
                $qb->expr()->eq('source_table', $qb->createNamedParameter($sourceTable)),
                $qb->expr()->eq('source_uid', $qb->createNamedParameter($sourceUid, Connection::PARAM_INT)),
                $qb->expr()->eq('source_field', $qb->createNamedParameter($sourceField)),
                $qb->expr()->eq('source_type', $qb->createNamedParameter($sourceType)),
                $qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId)),
                $qb->expr()->eq('status', $qb->createNamedParameter(IssueStatus::Resolved->value, Connection::PARAM_INT)),
            )
            ->orderBy('tstamp', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->setMaxResults(1);

        $row = $qb->executeQuery()->fetchAssociative();

        return $row ?: null;
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
        // N-06: the unique key (site, fingerprint) also holds retired rows (deleted = 1, RenderedRichTextDuplicates), so
        // the lookup must see them; otherwise the next scan inserts the finding again and fails on the key.
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        $row = $qb
            ->select('uid', 'status', 'first_seen_scan_uid', 'ignored_until', 'deleted')
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

    public function countOpenByRuleOnPage(string $siteIdentifier, int $pageUid, int $languageUid = -1, bool $readableSourcesOnly = false): array
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
        if ($readableSourcesOnly) {
            $this->addReadableSourceConstraint($qb, $siteIdentifier, 'i');
        }

        $rows = $qb->groupBy('i.rule_id')->executeQuery()->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)($row['rule_id'] ?? '')] = (int)($row['cnt'] ?? 0);
        }

        return $counts;
    }

    public function countOpenByRuleOnSite(string $siteIdentifier, int $languageUid = -1, bool $readableSourcesOnly = false): array
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
        if ($readableSourcesOnly) {
            $this->addReadableSourceConstraint($qb, $siteIdentifier, 'i');
        }

        $rows = $qb->groupBy('i.rule_id')->executeQuery()->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)($row['rule_id'] ?? '')] = (int)($row['cnt'] ?? 0);
        }

        return $counts;
    }

    /**
     * Open findings of a site grouped by rule, restricted to the pages the user may show (empty clause = administrator,
     * no restriction): occurrences, affected pages, the most severe level and the page with the most occurrences. Rules
     * disabled for the site and findings on deleted or workspace pages are left out, like the Overview totals.
     *
     * @return list<array{rule_id: string, occurrences: int, pages: int, severity: int, top_page_uid: int, top_page_count: int, top_page_title: string}>
     */
    public function summarizeOpenByRuleForSite(string $siteIdentifier, int $languageUid, string $readablePagesClause, int $limit = 15): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();
        $qb->select('i.rule_id', 'i.page_uid')
            ->addSelectLiteral('COUNT(*) AS cnt', 'MIN(i.severity) AS sev', 'MAX(p.title) AS page_title')
            ->from(Tables::ISSUE, 'i')
            ->where(
                $qb->expr()->eq('i.site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('i.status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $qb->expr()->eq('i.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT))
            );
        $this->addExistingPageJoinAndRestrictions($qb, 'i');
        $this->addLanguageConstraint($qb, $languageUid, 'i');
        // Readable pages and, for restricted users, the current source-record rights (Sol QA-02), like every site aggregate.
        $this->addReadablePagesConstraint($qb, $readablePagesClause, $siteIdentifier);
        $this->addEnabledRuleConstraint($qb, $siteIdentifier, 'i');
        $rows = $qb->groupBy('i.rule_id', 'i.page_uid')->executeQuery()->fetchAllAssociative();

        $rules = [];
        foreach ($rows as $row) {
            $ruleId = (string)($row['rule_id'] ?? '');
            if ($ruleId === '') {
                continue;
            }
            $count = (int)($row['cnt'] ?? 0);
            $pageUid = (int)($row['page_uid'] ?? 0);
            $rules[$ruleId] ??= ['rule_id' => $ruleId, 'occurrences' => 0, 'pages' => 0, 'severity' => PHP_INT_MAX, 'top_page_uid' => 0, 'top_page_count' => 0, 'top_page_title' => ''];
            $rules[$ruleId]['occurrences'] += $count;
            $rules[$ruleId]['pages']++;
            $rules[$ruleId]['severity'] = min($rules[$ruleId]['severity'], (int)($row['sev'] ?? PHP_INT_MAX));
            if ($count > $rules[$ruleId]['top_page_count'] || ($count === $rules[$ruleId]['top_page_count'] && $pageUid < $rules[$ruleId]['top_page_uid'])) {
                $rules[$ruleId]['top_page_uid'] = $pageUid;
                $rules[$ruleId]['top_page_count'] = $count;
                $rules[$ruleId]['top_page_title'] = (string)($row['page_title'] ?? '');
            }
        }
        $rules = array_values($rules);
        usort($rules, static fn (array $a, array $b): int => [$a['severity'], -$a['occurrences'], $a['rule_id']] <=> [$b['severity'], -$b['occurrences'], $b['rule_id']]);

        return array_slice($rules, 0, max(1, $limit));
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

        // Live pages only: findings stored on a page version or a page created in a workspace (content scans before
        // 3.0 read those) belong to no page of the live site and are never scanned again.
        $qb->andWhere(
            $qb->expr()->eq('p.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            $qb->expr()->eq('p.t3ver_wsid', $qb->createNamedParameter(0, Connection::PARAM_INT))
        );
    }

    /**
     * Restricts a site-wide aggregate to pages the backend user may show. The clause is TYPO3's own page permission
     * condition on the unaliased `pages` table (ScopeAccessService::readablePagesClause()); empty = no restriction.
     */
    private function addReadablePagesConstraint(QueryBuilder $qb, string $readablePagesClause, string $siteIdentifier): void
    {
        if ($readablePagesClause === '') {
            return;
        }

        // in() adds the parentheses: "IN (SELECT …)" is a subquery on every DBMS, "IN ((SELECT …))" a scalar one on PostgreSQL.
        $qb->andWhere($qb->expr()->in('i.page_uid', 'SELECT pages.uid FROM ' . Tables::PAGES . ' pages WHERE ' . $readablePagesClause));
        // A restricted (non-administrator) aggregate also needs current read access to every finding's source record.
        $this->addReadableSourceConstraint($qb, $siteIdentifier, 'i');
    }

    /**
     * Restricts findings to those whose source record the current backend user may read today (table, record,
     * page, language, live version). The stored page attribution is no authority over the source; unknown sources
     * are withheld. Administrators are unrestricted. Without the policy service the query fails closed.
     */
    private function addReadableSourceConstraint(QueryBuilder $qb, string $siteIdentifier, string $issueAlias): void
    {
        if (!$this->sourceAccessService instanceof LocalFindingSourceAccessInterface) {
            $qb->andWhere('1=0');
            return;
        }

        $constraint = $this->sourceAccessService->constraint($qb, $issueAlias, $siteIdentifier);
        if ($constraint !== '') {
            $qb->andWhere($constraint);
        }
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
