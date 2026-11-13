<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Workspace;

use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\WorkspaceRestriction;

/**
 * The Quality Gate's look at a workspace publish before it happens. While run() executes, the readers of the content
 * scan (records, file references, metadata) return the records as they will be live after the publish instead of the
 * live records; otherwise nothing changes. Nothing read in this state is stored. A shared service: the gate and the
 * readers receive the same instance through dependency injection.
 */
final class PublishPreviewContext
{
    private ?WorkspacePublishSet $set = null;

    /**
     * @template T
     * @param callable(): T $evaluation
     * @return T
     */
    public function run(WorkspacePublishSet $set, callable $evaluation): mixed
    {
        $previous = $this->set;
        $this->set = $set;
        try {
            return $evaluation();
        } finally {
            $this->set = $previous;
        }
    }

    public function active(): ?WorkspacePublishSet
    {
        return $this->set;
    }

    /**
     * The enable fields a frontend applies, for rows read without restrictions in a preview.
     *
     * @param array<string, mixed> $row
     */
    public static function isEnabled(array $row, int $now): bool
    {
        return (int)($row['hidden'] ?? 0) === 0
            && (int)($row['starttime'] ?? 0) <= $now
            && ((int)($row['endtime'] ?? 0) === 0 || (int)$row['endtime'] > $now);
    }

    /**
     * Restricts a query to live records plus the records of the previewed workspace (no visibility restriction: the
     * caller re-applies its conditions after WorkspacePublishSet::overlay()). Returns false outside a preview.
     */
    public function restrictForPreview(QueryBuilder $queryBuilder): bool
    {
        if ($this->set === null) {
            return false;
        }
        $queryBuilder->getRestrictions()->removeAll()
            ->add(new DeletedRestriction())
            ->add(new WorkspaceRestriction($this->set->workspaceId, true));

        return true;
    }
}
