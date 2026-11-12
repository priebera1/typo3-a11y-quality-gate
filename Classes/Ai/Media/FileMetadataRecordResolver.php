<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Service\BackendUserService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Finds the one sys_file_metadata record a suggestion is about: the default-language record of a file, or its
 * translation in exactly one language (sys_language_uid + l10n_parent). It never creates a translation and never
 * falls back to another language's record, so a German value can only ever reach the German record. Values are read
 * as the current user sees them (workspace overlay included).
 *
 * It also summarises where the file is used, so the review can say how many uses inherit the file default and how
 * many set their own text. Uses on pages the user cannot read are counted, not named.
 */
final class FileMetadataRecordResolver
{
    private const MAX_LISTED_USES = 20;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly BackendUserService $backendUserService,
    ) {}

    /**
     * @return array{uid:int,languageUid:int,parentUid:int,tstamp:int,workspace:int,values:array<string,string>}|null
     */
    public function find(int $fileUid, int $languageUid): ?array
    {
        if ($fileUid <= 0 || $languageUid < 0) {
            return null;
        }
        $default = $this->liveRow($fileUid, 0, 0);
        if ($languageUid === 0) {
            return $default === null ? null : $this->describe($default);
        }
        if ($default === null) {
            return null;
        }
        $translation = $this->liveRow($fileUid, $languageUid, (int)$default['uid']);

        return $translation === null ? null : $this->describe($translation);
    }

    /** @return list<int> sys_language_uid values that have a metadata record for this file */
    public function languages(int $fileUid): array
    {
        $queryBuilder = $this->queryBuilder();
        $rows = $queryBuilder
            ->select('sys_language_uid')
            ->from(Tables::SYS_FILE_METADATA)
            ->where(
                $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('t3ver_wsid', $queryBuilder->createNamedParameter($this->visibleWorkspaces(), Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchFirstColumn();

        $languages = array_values(array_unique(array_map('intval', $rows)));
        sort($languages);

        return $languages;
    }

    /**
     * Uses of the file in content (sys_file_reference, live records, not deleted).
     *
     * "items" lists up to 20 readable uses for display; "inheritingItems" holds every readable use that inherits the
     * file default, which is what a verification has to check.
     *
     * @return array{total:int,inheriting:int,overriding:int,decorative:int,hidden:int,items:list<array{referenceUid:int,table:string,uid:int,field:string,pageUid:int,pageTitle:string,languageUid:int,mode:string}>,inheritingItems:list<array<string,mixed>>}
     */
    public function uses(int $fileUid, int $languageUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::SYS_FILE_REFERENCE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $rows = $queryBuilder
            ->select('uid', 'pid', 'uid_foreign', 'tablenames', 'fieldname', 'alternative', 'tx_a11y_is_decorative', 'sys_language_uid', 'hidden')
            ->from(Tables::SYS_FILE_REFERENCE)
            ->where(
                $queryBuilder->expr()->eq('uid_local', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'ASC')
            ->setMaxResults(500)
            ->executeQuery()
            ->fetchAllAssociative();

        $summary = ['total' => 0, 'inheriting' => 0, 'overriding' => 0, 'decorative' => 0, 'hidden' => 0, 'items' => [], 'inheritingItems' => []];
        foreach ($rows as $row) {
            // A use shows this language's metadata when it is in this language (or "all languages" for the default).
            $useLanguage = (int)($row['sys_language_uid'] ?? 0);
            if ($useLanguage !== $languageUid && !($languageUid === 0 && $useLanguage === -1)) {
                continue;
            }
            $summary['total']++;
            $mode = match (true) {
                (int)($row['tx_a11y_is_decorative'] ?? 0) === 1 => 'decorative',
                is_string($row['alternative'] ?? null) => 'own',
                default => 'inherits',
            };
            $summary[match ($mode) { 'decorative' => 'decorative', 'own' => 'overriding', default => 'inheriting' }]++;

            $pageUid = (int)($row['pid'] ?? 0);
            $page = $pageUid > 0 ? $this->readablePage($pageUid) : null;
            if ($page === null) {
                $summary['hidden']++;
                continue;
            }
            $item = [
                'referenceUid' => (int)$row['uid'],
                'table' => (string)$row['tablenames'],
                'uid' => (int)$row['uid_foreign'],
                'field' => (string)$row['fieldname'],
                'pageUid' => $pageUid,
                'pageTitle' => (string)($page['title'] ?? ''),
                'languageUid' => $useLanguage,
                'mode' => $mode,
            ];
            if (count($summary['items']) < self::MAX_LISTED_USES) {
                $summary['items'][] = $item;
            }
            if ($mode === 'inherits') {
                $summary['inheritingItems'][] = $item;
            }
        }

        return $summary;
    }

    /** @return array<string,mixed>|null */
    private function liveRow(int $fileUid, int $languageUid, int $parentUid): ?array
    {
        $queryBuilder = $this->queryBuilder();
        $constraints = [
            $queryBuilder->expr()->eq('file', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
            $languageUid === 0
                ? $queryBuilder->expr()->in('sys_language_uid', $queryBuilder->createNamedParameter([0, -1], Connection::PARAM_INT_ARRAY))
                : $queryBuilder->expr()->eq('sys_language_uid', $queryBuilder->createNamedParameter($languageUid, Connection::PARAM_INT)),
            $queryBuilder->expr()->eq('t3ver_oid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            // Live records and records new in the user's own workspace; never another workspace's records.
            $queryBuilder->expr()->in('t3ver_wsid', $queryBuilder->createNamedParameter($this->visibleWorkspaces(), Connection::PARAM_INT_ARRAY)),
        ];
        if ($languageUid > 0) {
            $constraints[] = $queryBuilder->expr()->eq('l10n_parent', $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT));
        }
        $rows = $queryBuilder
            ->select('*')
            ->from(Tables::SYS_FILE_METADATA)
            ->where(...$constraints)
            ->orderBy('uid', 'ASC')
            ->setMaxResults(2)
            ->executeQuery()
            ->fetchAllAssociative();
        if (count($rows) !== 1) {
            // None, or an ambiguous duplicate: never guess which record is meant.
            return null;
        }
        $row = $rows[0];
        $workspace = (int)($this->backendUserService->getBackendUser()?->workspace ?? 0);
        if ($workspace > 0) {
            BackendUtility::workspaceOL(Tables::SYS_FILE_METADATA, $row, $workspace);
            // Deleted in this workspace (delete placeholder, t3ver_state 2): there is no record to write.
            if (!is_array($row) || (int)($row['deleted'] ?? 0) === 1 || (int)($row['t3ver_state'] ?? 0) === 2) {
                return null;
            }
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{uid:int,languageUid:int,parentUid:int,tstamp:int,workspace:int,values:array<string,string>}
     */
    private function describe(array $row): array
    {
        $values = [];
        foreach (MediaAiFieldPolicy::METADATA_FIELDS as $field) {
            $values[$field] = is_scalar($row[$field] ?? null) ? (string)$row[$field] : '';
        }

        return [
            // The live uid: DataHandler maps it to the workspace version itself.
            'uid' => (int)($row['_ORIG_uid'] ?? 0) > 0 && (int)($row['t3ver_oid'] ?? 0) > 0 ? (int)$row['t3ver_oid'] : (int)$row['uid'],
            'languageUid' => max(0, (int)($row['sys_language_uid'] ?? 0)),
            'parentUid' => (int)($row['l10n_parent'] ?? 0),
            'tstamp' => (int)($row['tstamp'] ?? 0),
            'workspace' => (int)($row['t3ver_wsid'] ?? 0),
            'values' => $values,
        ];
    }

    /** @return list<int> */
    private function visibleWorkspaces(): array
    {
        $workspace = (int)($this->backendUserService->getBackendUser()?->workspace ?? 0);

        return $workspace > 0 ? [0, $workspace] : [0];
    }

    /** @return array<string,mixed>|null */
    private function readablePage(int $pageUid): ?array
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if ($backendUser === null) {
            return null;
        }
        $page = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(1));

        return is_array($page) && $page !== [] ? $page : null;
    }

    private function queryBuilder(): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::SYS_FILE_METADATA);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());

        return $queryBuilder;
    }
}
