<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Assignment;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Whether a backend user can work on an issue type on a page in a language — checked AS THAT USER, never inferred from
 * the assigner's rights: active account, access to the Accessibility module, the page in a web mount with show access,
 * the language, and the page's live translation (it has page permissions of its own).
 *
 * Reasons: '' (eligible), 'missing', 'disabled', 'no_module', 'no_page', 'no_language'.
 */
class AssigneeEligibility
{
    public const MODULE = 'web_a11y';

    /** @var array<int, BackendUserAuthentication|string> */
    private array $users = [];
    /** @var array<string, string> */
    private array $verdicts = [];

    public function __construct(
        private readonly AssignmentRepository $repository,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function reason(int $userUid, int $pageUid, int $languageUid): string
    {
        $key = $userUid . ':' . $pageUid . ':' . $languageUid;
        if (isset($this->verdicts[$key])) {
            return $this->verdicts[$key];
        }

        $user = $this->user($userUid);
        if (is_string($user)) {
            return $this->verdicts[$key] = $user;
        }
        if (!$user->isAdmin()) {
            if (!$user->check('modules', self::MODULE)) {
                return $this->verdicts[$key] = 'no_module';
            }
            if (!$this->canShowPage($user, $pageUid)) {
                return $this->verdicts[$key] = 'no_page';
            }
            if (!$user->checkLanguageAccess($languageUid)) {
                return $this->verdicts[$key] = 'no_language';
            }
            $translationUid = $languageUid > 0 ? $this->liveTranslationUid($pageUid, $languageUid) : 0;
            if ($translationUid > 0 && !$this->canShowPage($user, $translationUid)) {
                return $this->verdicts[$key] = 'no_page';
            }
        }

        return $this->verdicts[$key] = '';
    }

    public function isEligible(int $userUid, int $pageUid, int $languageUid): bool
    {
        return $this->reason($userUid, $pageUid, $languageUid) === '';
    }

    /**
     * Assignee candidates for an issue type on a page: active users who could open and fix it.
     *
     * @return list<array{uid:int,label:string}>
     */
    public function candidates(int $pageUid, int $languageUid): array
    {
        $candidates = [];
        foreach ($this->repository->findActiveBackendUsers() as $row) {
            if ($this->isEligible($row['uid'], $pageUid, $languageUid)) {
                $candidates[] = ['uid' => $row['uid'], 'label' => self::label($row)];
            }
        }

        return $candidates;
    }

    /** @param array{username:string,realName:string} $row */
    public static function label(array $row): string
    {
        $name = trim((string)($row['realName'] ?? ''));

        return $name !== '' ? $name : (string)($row['username'] ?? '');
    }

    private function user(int $uid): BackendUserAuthentication|string
    {
        if (isset($this->users[$uid])) {
            return $this->users[$uid];
        }
        $row = $this->repository->findBackendUser($uid);
        if ($row === null || $row['deleted'] === 1) {
            return $this->users[$uid] = 'missing';
        }
        if ($row['disable'] === 1) {
            return $this->users[$uid] = 'disabled';
        }

        $user = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $user->setBeUserByUid($uid);
        if (!is_array($user->user ?? null)) {
            return $this->users[$uid] = 'missing';
        }
        $user->fetchGroupData();

        return $this->users[$uid] = $user;
    }

    private function canShowPage(BackendUserAuthentication $user, int $pageUid): bool
    {
        if ($pageUid <= 0 || !$user->isInWebMount($pageUid)) {
            return false;
        }
        $page = BackendUtility::getRecord(Tables::PAGES, $pageUid);

        return is_array($page) && $user->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }

    private function liveTranslationUid(int $pageUid, int $languageUid): int
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')->from(Tables::PAGES)
            ->where(
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
                $qb->expr()->eq('t3ver_wsid', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->orderBy('uid', 'ASC')->setMaxResults(1)->executeQuery()->fetchOne();

        return (int)$uid;
    }
}
