<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Which rule ids "Change owner" may store (route a11y_remediation_owner), so an assignment always names a real issue
 * type of the site, never an arbitrary string from the request:
 *
 * - a content rule that has a stored finding on that page (Page Detail owner menu), or
 * - on the site root page only: a frontend rule found by a stored paid frontend scan of the site (Overview "What to
 *   fix first", F-08). Free Remote Preview results never qualify.
 */
final class RemediationOwnerRuleValidator
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function isAssignable(string $siteIdentifier, int $pageUid, string $ruleId, int $siteRootPageUid): bool
    {
        if ($siteIdentifier === '' || $pageUid <= 0 || $ruleId === '' || strlen($ruleId) > 255) {
            return false;
        }
        if ($this->hasContentFinding($siteIdentifier, $pageUid, $ruleId)) {
            return true;
        }

        return $siteRootPageUid > 0 && $pageUid === $siteRootPageUid
            && $this->storedFrontendRuleIds($siteIdentifier, [$ruleId]) === [$ruleId];
    }

    /**
     * The given rule ids that a stored paid (not Free Remote Preview) frontend scan of the site found.
     *
     * @param list<string> $ruleIds
     * @return list<string>
     */
    public function storedFrontendRuleIds(string $siteIdentifier, array $ruleIds): array
    {
        $ruleIds = array_values(array_unique(array_filter(
            $ruleIds,
            static fn (mixed $ruleId): bool => is_string($ruleId) && $ruleId !== '' && strlen($ruleId) <= 255,
        )));
        if ($siteIdentifier === '' || $ruleIds === []) {
            return [];
        }
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::REMOTE_ISSUE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->selectLiteral('DISTINCT ' . $qb->quoteIdentifier('issue.rule_id') . ' AS rule_id')
            ->from(Tables::REMOTE_ISSUE, 'issue')
            ->innerJoin('issue', Tables::REMOTE_SCAN, 'scan', $qb->expr()->eq('scan.uid', $qb->quoteIdentifier('issue.remote_scan')))
            ->where(
                $qb->expr()->in('issue.rule_id', $qb->createNamedParameter($ruleIds, Connection::PARAM_STR_ARRAY)),
                $qb->expr()->eq('issue.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('scan.deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('scan.site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('scan.is_free_preview', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn();
        $found = array_map('strval', $rows);

        return array_values(array_filter($ruleIds, static fn (string $ruleId): bool => in_array($ruleId, $found, true)));
    }

    private function hasContentFinding(string $siteIdentifier, int $pageUid, string $ruleId): bool
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::ISSUE);
        $qb->getRestrictions()->removeAll();

        return (int)$qb->count('uid')
            ->from(Tables::ISSUE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('page_uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('rule_id', $qb->createNamedParameter($ruleId)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne() > 0;
    }
}
