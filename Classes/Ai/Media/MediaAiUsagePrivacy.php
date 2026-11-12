<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;

/**
 * Whether the image of a file may be sent for its file default (Filelist, the metadata form, bulk and the upload
 * follow-up) — owner decision D-08. The file default is shown wherever the file is used, so the image input switch of
 * every site that uses the file applies, not only the switch of the site whose AI settings the person picked: a file
 * used on a site that forbids image input is never sent through another site's settings.
 *
 * Uses are read without the person's page access (a hidden use still counts) from every non-deleted reference in any
 * language and any workspace, and from the reference index (images inside rich text). A use whose site cannot be
 * determined makes the decision "unknown", which is a refusal: only a decision that every use allows images sends one.
 * A file without uses depends on the chosen site alone, which the caller checks.
 */
final class MediaAiUsagePrivacy
{
    public const ALLOWED = 'allowed';
    public const BLOCKED = 'blocked';
    public const UNKNOWN = 'unknown';

    /** More uses than this are not evaluated one by one: the decision is "unknown". */
    private const MAX_USES = 1000;

    /** Index rows that are the file itself or its references, which are read directly. */
    private const IGNORED_INDEX_TABLES = [Tables::SYS_FILE, Tables::SYS_FILE_METADATA, Tables::SYS_FILE_REFERENCE];

    /** @var array<string,int> table:uid => page, per request */
    private array $recordPages = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly AiSiteSettingsService $siteSettingsService,
    ) {}

    public function decide(int $fileUid): string
    {
        if ($fileUid <= 0) {
            return self::UNKNOWN;
        }
        try {
            $pages = $this->usePages($fileUid);
        } catch (\Throwable) {
            return self::UNKNOWN;
        }
        if ($pages === null) {
            return self::UNKNOWN;
        }

        $sites = [];
        foreach (array_unique($pages) as $pageUid) {
            $site = $pageUid > 0 ? $this->siteResolutionService->resolveSiteByPageId($pageUid) : null;
            if ($site === null) {
                return self::UNKNOWN;
            }
            $sites[$site->getIdentifier()] = true;
        }
        foreach (array_keys($sites) as $siteIdentifier) {
            if (!$this->siteSettingsService->get((string)$siteIdentifier)->allowImageInput) {
                return self::BLOCKED;
            }
        }

        return self::ALLOWED;
    }

    /** Refuses before anything is loaded or sent. The message never names another site, page or record. */
    public function assertImageInputAllowed(int $fileUid): void
    {
        match ($this->decide($fileUid)) {
            self::ALLOWED => null,
            self::BLOCKED => throw new EditorAiException('privacy_images_usage', 'This image is used on a site that does not allow sending images to AI providers.', 422),
            default => throw new EditorAiException('privacy_usage_unknown', 'AQG could not determine where this image is used, so it is not sent.', 422),
        };
    }

    /** @return list<int>|null the page of every use, or null when there are too many to evaluate */
    private function usePages(int $fileUid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::SYS_FILE_REFERENCE);
        $queryBuilder->getRestrictions()->removeAll()->add(new DeletedRestriction());
        $references = $queryBuilder
            ->select('pid', 'tablenames', 'uid_foreign')
            ->from(Tables::SYS_FILE_REFERENCE)
            ->where($queryBuilder->expr()->eq('uid_local', $queryBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)))
            ->setMaxResults(self::MAX_USES + 1)
            ->executeQuery()
            ->fetchAllAssociative();

        $indexBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
        $indexBuilder->getRestrictions()->removeAll();
        $indexed = $indexBuilder
            ->select('tablename', 'recuid')
            ->from('sys_refindex')
            ->where(
                $indexBuilder->expr()->eq('ref_table', $indexBuilder->createNamedParameter(Tables::SYS_FILE)),
                $indexBuilder->expr()->eq('ref_uid', $indexBuilder->createNamedParameter($fileUid, Connection::PARAM_INT)),
                $indexBuilder->expr()->notIn('tablename', $indexBuilder->createNamedParameter(self::IGNORED_INDEX_TABLES, Connection::PARAM_STR_ARRAY)),
            )
            ->setMaxResults(self::MAX_USES + 1)
            ->executeQuery()
            ->fetchAllAssociative();
        if (count($references) + count($indexed) > self::MAX_USES) {
            return null;
        }

        $pages = [];
        foreach ($references as $reference) {
            $table = (string)$reference['tablenames'];
            // The reference row's pid is its page (for page media: the page itself, also for a page new in a workspace).
            $pages[] = (int)$reference['pid'] > 0 ? (int)$reference['pid'] : ($table === Tables::PAGES ? (int)$reference['uid_foreign'] : $this->recordPage($table, (int)$reference['uid_foreign']));
        }
        foreach ($indexed as $row) {
            $table = (string)$row['tablename'];
            $pages[] = $table === Tables::PAGES ? (int)$row['recuid'] : $this->recordPage($table, (int)$row['recuid']);
        }

        return $pages;
    }

    /** The page of a record, deleted or hidden ones included; 0 when it cannot be read (an unknown use). */
    private function recordPage(string $table, int $uid): int
    {
        if ($uid <= 0 || !isset($GLOBALS['TCA'][$table])) {
            return 0;
        }
        if (isset($this->recordPages[$table . ':' . $uid])) {
            return $this->recordPages[$table . ':' . $uid];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $pid = $queryBuilder
            ->select('pid')
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchOne();

        return $this->recordPages[$table . ':' . $uid] = $pid === false ? 0 : max(0, (int)$pid);
    }
}
