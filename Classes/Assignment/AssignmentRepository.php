<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Assignment;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Repository\AbstractRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;

/**
 * Team assignments (My issues): one row per issue type on a page in one language (tx_a11y_assignment), and the live
 * finding aggregates their state is read from. Rows never reference finding uids or fingerprints.
 */
class AssignmentRepository extends AbstractRepository
{
    /** Rendered findings stored against the page itself: template output (TemplateScope::isTemplateFinding()). */
    private const TEMPLATE_SQL = "(source_table = 'pages' AND rule_id LIKE 'rendered.%')";
    private const NOT_TEMPLATE_SQL = "NOT (source_table = 'pages' AND rule_id LIKE 'rendered.%')";

    /**
     * Open and resolved template findings (N-01), one row per finding, for grouping into shared site tasks by
     * TemplateScope::key(). Pages that are deleted or are page translations are left out, as for content tasks.
     *
     * @param list<string> $siteIdentifiers empty = every site
     * @return list<array{site_identifier:string,page_uid:int,source_lang_uid:int,rule_id:string,context_snippet:string,status:int,severity:int,tstamp:int,resolved_at:int,message:string}>
     */
    public function findTemplateFindings(array $siteIdentifiers = [], ?int $limit = null): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();
        $qb->select('site_identifier', 'page_uid', 'source_lang_uid', 'rule_id', 'context_snippet', 'status', 'severity', 'tstamp', 'resolved_at', 'message')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('page_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                'page_uid IN (SELECT uid FROM pages WHERE sys_language_uid = 0 AND deleted = 0)',
                $qb->expr()->in('status', $qb->createNamedParameter([IssueStatus::Open->value, IssueStatus::Resolved->value], Connection::PARAM_INT_ARRAY)),
                self::TEMPLATE_SQL,
            )
            ->orderBy('page_uid', 'ASC')
            ->setMaxResults($limit);
        if ($siteIdentifiers !== []) {
            $qb->andWhere($qb->expr()->in('site_identifier', $qb->createNamedParameter($siteIdentifiers, Connection::PARAM_STR_ARRAY)));
        }

        return array_map(static fn (array $row): array => [
            'site_identifier' => (string)$row['site_identifier'],
            'page_uid' => (int)$row['page_uid'],
            'source_lang_uid' => (int)$row['source_lang_uid'],
            'rule_id' => (string)$row['rule_id'],
            'context_snippet' => (string)$row['context_snippet'],
            'status' => (int)$row['status'],
            'severity' => (int)$row['severity'],
            'tstamp' => (int)$row['tstamp'],
            'resolved_at' => (int)$row['resolved_at'],
            'message' => (string)$row['message'],
        ], $qb->executeQuery()->fetchAllAssociative());
    }
    /** @return array<string, mixed>|null */
    public function find(string $siteIdentifier, int $pageUid, int $languageUid, string $issueKey): ?array
    {
        $qb = $this->getQueryBuilder(Tables::ASSIGNMENT);
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('*')->from(Tables::ASSIGNMENT)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('issue_key', $qb->createNamedParameter($issueKey)),
            )
            ->setMaxResults(1)->executeQuery()->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * Creates or replaces the assignment of one issue type on a page. $assignee 0 unassigns (the row stays, so the
     * history of who unassigned it remains readable).
     *
     * With $expectedAssignee (the assignee the caller saw, 0 = nobody) the change is a compare-and-set: it returns false
     * when someone else changed the assignment meanwhile (N-07) instead of silently overwriting that change.
     */
    public function save(string $siteIdentifier, int $pageUid, int $languageUid, string $issueKey, int $assignee, int $assignedBy, string $note, ?int $expectedAssignee = null): bool
    {
        $now = time();
        $values = [
            'tstamp' => $now,
            'assignee' => max(0, $assignee),
            'assigned_by' => max(0, $assignedBy),
            'assigned_at' => $now,
            'note' => mb_substr(trim($note), 0, 280),
        ];
        $existing = $this->find($siteIdentifier, $pageUid, $languageUid, $issueKey);
        $connection = $this->getConnection(Tables::ASSIGNMENT);
        if ($existing !== null) {
            // NEW-08: a request without expectedAssignee (API clients; the UI always sends it) is still a compare-and-set
            // against the assignee this request read, so a concurrent change by another manager is a conflict, never a
            // silent overwrite.
            $expectedAssignee ??= (int)$existing['assignee'];
            $updated = $connection->update(Tables::ASSIGNMENT, $values, ['uid' => (int)$existing['uid'], 'assignee' => max(0, $expectedAssignee)]);
            if ($updated > 0) {
                return true;
            }
            // MariaDB/MySQL report changed rows only: an identical write within the same second is not a conflict.
            $current = $this->find($siteIdentifier, $pageUid, $languageUid, $issueKey);

            return $current !== null && (int)$current['assignee'] === max(0, $expectedAssignee) && (int)$current['assignee'] === max(0, $assignee);
        }
        if ($expectedAssignee !== null && $expectedAssignee > 0) {
            return false;
        }
        try {
            $connection->insert(Tables::ASSIGNMENT, $values + [
                'crdate' => $now,
                'site_identifier' => $siteIdentifier,
                'page_uid' => $pageUid,
                'language_uid' => $languageUid,
                'issue_key' => $issueKey,
            ]);
        } catch (UniqueConstraintViolationException) {
            // NEW-08: another manager assigned this work unit first (UNIQUE issue_on_page). A conflict for the caller,
            // never an uncaught HTTP 500 and never an overwrite of the other manager's decision.
            return false;
        }

        return true;
    }

    /**
     * NEW-04: parent page of many pages in one query (deleted pages left out).
     *
     * @param list<int> $pageUids
     * @return array<int, int> uid => pid
     */
    public function findPageParents(array $pageUids): array
    {
        $parents = [];
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $pageUids)))), 1000) as $chunk) {
            $qb = $this->getQueryBuilder(Tables::PAGES);
            $qb->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class));
            foreach ($qb->select('uid', 'pid')->from(Tables::PAGES)->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative() as $row) {
                $parents[(int)$row['uid']] = (int)$row['pid'];
            }
        }

        return $parents;
    }

    /**
     * NEW-04: page titles for many pages in one query (deleted pages left out, as BackendUtility::getRecord()).
     *
     * @param list<int> $pageUids
     * @return array<int, string>
     */
    public function findPageTitles(array $pageUids): array
    {
        $titles = [];
        foreach (array_chunk(array_values(array_unique(array_filter(array_map('intval', $pageUids)))), 1000) as $chunk) {
            $qb = $this->getQueryBuilder(Tables::PAGES);
            $qb->getRestrictions()->removeAll()->add(\TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction::class));
            foreach ($qb->select('uid', 'title')->from(Tables::PAGES)->where($qb->expr()->in('uid', $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative() as $row) {
                $titles[(int)$row['uid']] = (string)$row['title'];
            }
        }

        return $titles;
    }

    /**
     * Assignments with an assignee, optionally of one person.
     *
     * @return list<array<string, mixed>>
     */
    public function findAssigned(?int $assignee = null, ?int $limit = null, ?array $siteIdentifiers = null): array
    {
        $qb = $this->getQueryBuilder(Tables::ASSIGNMENT);
        $qb->getRestrictions()->removeAll();
        $qb->select('*')->from(Tables::ASSIGNMENT)
            ->where($qb->expr()->gt('assignee', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->orderBy('assigned_at', 'DESC')
            ->setMaxResults($limit);
        if ($assignee !== null) {
            $qb->andWhere($qb->expr()->eq('assignee', $qb->createNamedParameter($assignee, Connection::PARAM_INT)));
        }
        // Large-installation fix: only the sites the list can show (null = every site).
        if ($siteIdentifiers !== null) {
            if ($siteIdentifiers === []) {
                return [];
            }
            $qb->andWhere($qb->expr()->in('site_identifier', $qb->createNamedParameter(array_values($siteIdentifiers), Connection::PARAM_STR_ARRAY)));
        }

        return array_values($qb->executeQuery()->fetchAllAssociative());
    }

    /**
     * Assignments keyed "site|page|language|issueKey" for a set of pages (Page Detail chips).
     *
     * @return array<string, array<string, mixed>>
     */
    public function findForPage(string $siteIdentifier, int $pageUid, int $languageUid): array
    {
        $qb = $this->getQueryBuilder(Tables::ASSIGNMENT);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('*')->from(Tables::ASSIGNMENT)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
            )
            ->executeQuery()->fetchAllAssociative();
        $byKey = [];
        foreach ($rows as $row) {
            $byKey[(string)$row['issue_key']] = $row;
        }

        return $byKey;
    }

    /** @return list<string> every site identifier live findings are stored for */
    private function siteIdentifiersWithFindings(): array
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        return array_values(array_map('strval', $qb->selectLiteral('DISTINCT site_identifier')
            ->from(Tables::ISSUE)
            ->where($qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)))
            ->executeQuery()->fetchFirstColumn()));
    }

    /**
     * Live findings of the given issue types, aggregated per issue type on a page and language: open occurrences, most
     * severe open severity, latest finding change and latest resolution. Rule ids are the canonical keys' members
     * (RenderedRichTextDuplicates::ruleIdsFor()); the caller folds them back into their key.
     *
     * G-09 (AQG 3.0.0 security gate): `$sourceConstraint` limits the rows to sources the viewer may read
     * (LocalFindingSourceAccessInterface::constraint(), per site: '' = unrestricted, '1=0' = nothing), so counts, task
     * existence and the stored message never come from findings of records the viewer lacks access to.
     *
     * @param list<string> $siteIdentifiers empty = every site
     * @param (\Closure(QueryBuilder, string): string)|null $sourceConstraint
     * @return list<array{site_identifier:string,page_uid:int,source_lang_uid:int,rule_id:string,open:int,top_severity:int,last_change:int,last_resolved:int,message:string}>
     */
    public function aggregateFindings(array $siteIdentifiers = [], ?array $pageUids = null, bool $openOnly = false, ?int $limit = null, ?\Closure $sourceConstraint = null): array
    {
        $rows = [];
        foreach ($this->iterateAggregatedFindings($siteIdentifiers, $pageUids, $openOnly, $limit, $sourceConstraint, false) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * aggregateFindings() as a stream (large-installation fix: My issues never holds every finding row of every site in
     * memory). With $ordered the rows come per site, page and language (then rule), so a caller can fold one page at a
     * time.
     *
     * @param list<string> $siteIdentifiers empty = every site
     * @param (\Closure(QueryBuilder, string): string)|null $sourceConstraint
     * @return \Generator<int, array{site_identifier:string,page_uid:int,source_lang_uid:int,rule_id:string,open:int,top_severity:int,last_change:int,last_resolved:int,message:string}>
     */
    public function iterateAggregatedFindings(array $siteIdentifiers = [], ?array $pageUids = null, bool $openOnly = false, ?int $limit = null, ?\Closure $sourceConstraint = null, bool $ordered = true, bool $withMessage = true): \Generator
    {
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();
        $qb->select('site_identifier', 'page_uid', 'source_lang_uid', 'rule_id')
            ->addSelectLiteral(
                'SUM(CASE WHEN status = ' . IssueStatus::Open->value . ' THEN 1 ELSE 0 END) AS open_count',
                'MIN(CASE WHEN status = ' . IssueStatus::Open->value . ' THEN severity ELSE 99 END) AS top_severity',
                'MAX(tstamp) AS last_change',
                'MAX(resolved_at) AS last_resolved',
                // The stored message is TEXT: aggregating it costs a third of the query on large sites. Without
                // $withMessage the rows carry '' (My issues loads the message for its page window only).
                $withMessage ? 'MIN(message) AS message' : "'' AS message",
            )
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->gt('page_uid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                // A page translation row is a language of its default page, not a task of its own (D-02 legacy rows).
                'page_uid IN (SELECT uid FROM pages WHERE sys_language_uid = 0 AND deleted = 0)',
                $qb->expr()->in('status', $qb->createNamedParameter([IssueStatus::Open->value, IssueStatus::Resolved->value], Connection::PARAM_INT_ARRAY)),
                // N-01: template output (rendered, outside every content element) is shared work of the site, never part
                // of an editor's content task (aggregateTemplateFindings()).
                self::NOT_TEMPLATE_SQL,
            )
            ->groupBy('site_identifier', 'page_uid', 'source_lang_uid', 'rule_id')
            ->setMaxResults($limit);
        if ($siteIdentifiers !== []) {
            $qb->andWhere($qb->expr()->in('site_identifier', $qb->createNamedParameter($siteIdentifiers, Connection::PARAM_STR_ARRAY)));
        }
        if ($pageUids !== null) {
            if ($pageUids === []) {
                return;
            }
            $qb->andWhere($qb->expr()->in('page_uid', $qb->createNamedParameter(array_values(array_unique($pageUids)), Connection::PARAM_INT_ARRAY)));
        }
        if ($openOnly) {
            $qb->having('open_count > 0');
        }
        if ($sourceConstraint !== null) {
            $perSite = [];
            $restricted = false;
            $allSites = $siteIdentifiers === [];
            foreach ($siteIdentifiers !== [] ? $siteIdentifiers : $this->siteIdentifiersWithFindings() as $site) {
                $condition = $sourceConstraint($qb, $site);
                if ($condition === '1=0') {
                    $restricted = true;
                    continue;
                }
                $siteMatch = $qb->expr()->eq('site_identifier', $qb->createNamedParameter($site));
                $restricted = $restricted || $condition !== '';
                $perSite[] = $condition === '' ? $siteMatch : (string)$qb->expr()->and($siteMatch, $condition);
            }
            if ($perSite === []) {
                return;
            }
            // Every site with findings unrestricted (administrators): the OR of all their identifiers matches every row,
            // so it is left out instead of sent as one condition per site.
            if (!($allSites && !$restricted)) {
                $qb->andWhere($qb->expr()->or(...$perSite));
            }
        }
        if ($ordered) {
            $qb->orderBy('site_identifier')->addOrderBy('page_uid')->addOrderBy('source_lang_uid')->addOrderBy('rule_id');
        }

        foreach ($qb->executeQuery()->iterateAssociative() as $row) {
            yield [
                'site_identifier' => (string)$row['site_identifier'],
                'page_uid' => (int)$row['page_uid'],
                'source_lang_uid' => (int)$row['source_lang_uid'],
                'rule_id' => (string)$row['rule_id'],
                'open' => (int)$row['open_count'],
                'top_severity' => (int)$row['top_severity'],
                'last_change' => (int)$row['last_change'],
                'last_resolved' => (int)$row['last_resolved'],
                'message' => (string)($row['message'] ?? ''),
            ];
        }
    }

    /**
     * Whether a live content record behind an open finding of the issue type changed after the finding was last
     * written: the result needs a rescan before it can be trusted as current.
     *
     * @param list<string> $ruleIds
     */
    public function hasChangedSinceLastScan(string $siteIdentifier, int $pageUid, int $languageUid, array $ruleIds): bool
    {
        if ($ruleIds === []) {
            return false;
        }
        $qb = $this->getQueryBuilder(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();
        $count = $qb->count('i.uid')
            ->from(Tables::ISSUE, 'i')
            ->join('i', Tables::TT_CONTENT, 'c', $qb->expr()->eq('c.uid', $qb->quoteIdentifier('i.source_uid')))
            ->where(
                $qb->expr()->eq('i.source_table', $qb->createNamedParameter(Tables::TT_CONTENT)),
                $qb->expr()->eq('i.site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('i.page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('i.source_lang_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $qb->expr()->in('i.rule_id', $qb->createNamedParameter($ruleIds, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->eq('i.status', $qb->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $qb->expr()->gt('c.tstamp', $qb->quoteIdentifier('i.tstamp')),
            )
            ->executeQuery()->fetchOne();

        return (int)$count > 0;
    }

    /**
     * Active backend users (not disabled, not deleted, not CLI users) as assignee candidates.
     *
     * @return list<array{uid:int,username:string,realName:string,admin:bool}>
     */
    public function findActiveBackendUsers(int $limit = 500): array
    {
        $qb = $this->getQueryBuilder('be_users');
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', 'username', 'realName', 'admin')->from('be_users')
            ->where(
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('disable', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->notLike('username', $qb->createNamedParameter('\_%')),
            )
            ->orderBy('realName', 'ASC')->addOrderBy('username', 'ASC')
            ->setMaxResults($limit)->executeQuery()->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'uid' => (int)$row['uid'],
            'username' => (string)$row['username'],
            'realName' => (string)$row['realName'],
            'admin' => (int)$row['admin'] === 1,
        ], $rows);
    }

    /** @return array{uid:int,username:string,realName:string,disable:int,deleted:int}|null */
    public function findBackendUser(int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        $qb = $this->getQueryBuilder('be_users');
        $qb->getRestrictions()->removeAll();
        $row = $qb->select('uid', 'username', 'realName', 'disable', 'deleted')->from('be_users')
            ->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid, Connection::PARAM_INT)))
            ->setMaxResults(1)->executeQuery()->fetchAssociative();

        return is_array($row) ? [
            'uid' => (int)$row['uid'],
            'username' => (string)$row['username'],
            'realName' => (string)$row['realName'],
            'disable' => (int)$row['disable'],
            'deleted' => (int)$row['deleted'],
        ] : null;
    }
}
