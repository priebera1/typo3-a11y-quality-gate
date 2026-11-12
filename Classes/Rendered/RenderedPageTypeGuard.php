<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Priebera\A11yQualityGate\Database\PageDoktypes;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

final class RenderedPageTypeGuard
{
    /** Visitors are not shown the page: TYPO3 answers HTTP 404 (or 403) to the rendered check, which requests it as one. */
    public const RESTRICTION_HIDDEN = 'hidden';
    public const RESTRICTION_SCHEDULED = 'scheduled';
    public const RESTRICTION_RESTRICTED = 'restricted';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function supportsPageUid(int $pageUid): bool
    {
        return $this->supportsDoktype($this->getDoktypeForPage($pageUid));
    }

    public function supportsDoktype(?int $doktype): bool
    {
        if ($doktype === null) {
            return false;
        }

        return PageDoktypes::supportsRenderedCheck($doktype);
    }

    public function getDoktypeForPage(int $pageUid): ?int
    {
        if ($pageUid <= 0) {
            return null;
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        // Live rows only: a workspace draft of the page or its translation does not change what visitors get.
        $qb->getRestrictions()->removeAll()->add(new WorkspaceRestriction(0));

        $row = $qb
            ->select('doktype')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return (int)($row['doktype'] ?? 0);
    }

    /**
     * Why visitors do not get this page in the language, or '' when it is public: hidden (the page or its translation),
     * outside its start and stop time, or limited to frontend user groups. The rendered check requests pages as an
     * anonymous visitor and never sends the Scanner Token, so such a page cannot be checked there.
     */
    public function visitorRestriction(int $pageUid, int $languageUid = 0): string
    {
        return $this->visitorRestrictions($pageUid, $languageUid)[0] ?? '';
    }

    /**
     * Every reason why visitors do not get this page in the language (hidden, scheduled, restricted, in that order);
     * empty when it is public. A hidden page can be scheduled as well, and each reason needs its own access path.
     *
     * @return list<string>
     */
    public function visitorRestrictions(int $pageUid, int $languageUid = 0): array
    {
        if ($pageUid <= 0) {
            return [];
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::PAGES);
        // Live rows only: a workspace draft of the page or its translation does not change what visitors get.
        $qb->getRestrictions()->removeAll()->add(new WorkspaceRestriction(0));
        $constraints = [
            $qb->expr()->eq('uid', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
        ];
        if ($languageUid > 0) {
            $constraints[] = $qb->expr()->and(
                $qb->expr()->eq('l10n_parent', $qb->createNamedParameter($pageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
            );
        }

        return self::restrictionsOfRows($qb
            ->select('hidden', 'starttime', 'endtime', 'fe_group')
            ->from(Tables::PAGES)
            ->where(
                $qb->expr()->or(...$constraints),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function restrictionsOfRows(array $rows): array
    {
        $now = time();
        $restrictions = [];
        foreach ([self::RESTRICTION_HIDDEN, self::RESTRICTION_SCHEDULED, self::RESTRICTION_RESTRICTED] as $restriction) {
            foreach ($rows as $row) {
                if (self::rowHasRestriction($row, $restriction, $now)) {
                    $restrictions[] = $restriction;
                    break;
                }
            }
        }

        return $restrictions;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function rowHasRestriction(array $row, string $restriction, int $now): bool
    {
        return match ($restriction) {
            self::RESTRICTION_HIDDEN => (int)($row['hidden'] ?? 0) === 1,
            self::RESTRICTION_SCHEDULED => (int)($row['starttime'] ?? 0) > $now
                || ((int)($row['endtime'] ?? 0) > 0 && (int)($row['endtime'] ?? 0) <= $now),
            // -1 hides the page at login only; anonymous visitors still get it.
            self::RESTRICTION_RESTRICTED => array_diff(
                array_filter(array_map('trim', explode(',', (string)($row['fe_group'] ?? ''))), static fn (string $group): bool => $group !== ''),
                ['0', '-1']
            ) !== [],
            default => false,
        };
    }
}
