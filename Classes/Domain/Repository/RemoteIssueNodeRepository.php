<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class RemoteIssueNodeRepository extends AbstractRepository
{
    public function deleteByRemoteIssueUids(array $remoteIssueUids): void
    {
        $remoteIssueUids = array_values(array_filter(array_map('intval', $remoteIssueUids)));

        if ($remoteIssueUids === []) {
            return;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE_NODE);

        $queryBuilder
            ->delete(Tables::REMOTE_ISSUE_NODE)
            ->where(
                $queryBuilder->expr()->in(
                    'remote_issue',
                    $queryBuilder->createNamedParameter(
                        $remoteIssueUids,
                        Connection::PARAM_INT_ARRAY
                    )
                )
            )
            ->executeStatement();
    }

    public function saveNode(
        int $remoteIssueUid,
        array $node,
        int $pid = 0,
    ): void {
        $connection = $this->getConnection(Tables::REMOTE_ISSUE_NODE);
        $now = time();

        $targetJson = $node['target'] ?? [];
        if (!is_array($targetJson)) {
            $targetJson = [];
        }

        $aqgMapping = $node['aqgMapping'] ?? [];
        if (!is_array($aqgMapping)) {
            $aqgMapping = [];
        }

        $connection->insert(Tables::REMOTE_ISSUE_NODE, [
            'pid' => $pid,
            'remote_issue' => $remoteIssueUid,
            'target_json' => json_encode($targetJson, JSON_THROW_ON_ERROR),
            'html_snippet' => (string)($node['htmlSnippet'] ?? ''),
            'failure_summary' => (string)($node['failureSummary'] ?? ''),
            'screenshot_path' => (string)($node['screenshotPath'] ?? ''),
            'screenshot_url' => (string)($node['screenshotUrl'] ?? ''),
            'mapped_table' => (string)($aqgMapping['table'] ?? ''),
            'mapped_uid' => (int)($aqgMapping['uid'] ?? 0),
            'mapped_cid' => (string)($aqgMapping['cid'] ?? ''),
            'mapped_ctype' => (string)($aqgMapping['ctype'] ?? ''),
            'crdate' => $now,
            'tstamp' => $now,
        ]);
    }

    public function findByRemoteIssue(int $remoteIssueUid): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE_NODE);

        return $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_ISSUE_NODE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_issue',
                    $queryBuilder->createNamedParameter($remoteIssueUid, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }
}