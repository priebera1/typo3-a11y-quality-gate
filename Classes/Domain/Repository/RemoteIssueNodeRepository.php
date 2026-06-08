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

        $contrastDetails = $this->normalizeContrastDetailsForStorage(
            $node['contrastDetails'] ?? $node['contrast_details'] ?? []
        );
        $contrastSuggestion = $this->normalizeContrastSuggestionForStorage(
            $node['contrastSuggestion'] ?? $node['contrast_suggestion'] ?? []
        );
        if ($contrastSuggestion !== []) {
            if ($contrastDetails === []) {
                $contrastDetails[] = [
                    'contrastSuggestion' => $contrastSuggestion,
                ];
            } else {
                $firstKey = array_key_first($contrastDetails);
                if ($firstKey !== null && is_array($contrastDetails[$firstKey])) {
                    $contrastDetails[$firstKey]['contrastSuggestion'] ??= $contrastSuggestion;
                }
            }
        }

        $nodeRemediation = $this->normalizeNodeRemediationForStorage(
            $node['nodeRemediation'] ?? $node['node_remediation'] ?? []
        );

        $connection->insert(Tables::REMOTE_ISSUE_NODE, [
            'pid' => $pid,
            'remote_issue' => $remoteIssueUid,
            'target_json' => json_encode($targetJson, JSON_THROW_ON_ERROR),
            'html_snippet' => (string)($node['htmlSnippet'] ?? ''),
            'failure_summary' => (string)($node['failureSummary'] ?? ''),
            'screenshot_path' => (string)($node['screenshotPath'] ?? ''),
            'screenshot_url' => (string)($node['screenshotUrl'] ?? ''),
            'contrast_details_json' => $this->encodeJsonForColumn($contrastDetails),
            'node_remediation_json' => $this->encodeJsonForColumn($nodeRemediation),
            'mapped_table' => (string)($aqgMapping['table'] ?? ''),
            'mapped_uid' => (int)($aqgMapping['uid'] ?? 0),
            'mapped_cid' => (string)($aqgMapping['cid'] ?? ''),
            'mapped_ctype' => (string)($aqgMapping['ctype'] ?? ''),
            'crdate' => $now,
            'tstamp' => $now,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeContrastDetailsForStorage(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if ($value !== [] && !array_is_list($value)) {
            $value = [$value];
        }

        return array_values(array_filter($value, static fn (mixed $item): bool => is_array($item)));
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeContrastSuggestionForStorage(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $hasCandidates = is_array($value['suggestedForegroundCandidates'] ?? null)
            || is_array($value['suggested_foreground_candidates'] ?? null)
            || is_array($value['suggestedBackgroundCandidates'] ?? null)
            || is_array($value['suggested_background_candidates'] ?? null);
        $hasRatios = isset($value['actualRatio']) || isset($value['actual_ratio']) || isset($value['requiredRatio']) || isset($value['required_ratio']);
        $hasColors = isset($value['currentForeground']) || isset($value['current_foreground']) || isset($value['currentBackground']) || isset($value['current_background']);
        $hasCandidateDetails = is_array($value['suggestedForegroundCandidateDetails'] ?? null)
            || is_array($value['suggested_foreground_candidate_details'] ?? null)
            || is_array($value['suggestedBackgroundCandidateDetails'] ?? null)
            || is_array($value['suggested_background_candidate_details'] ?? null);
        $hasPreferredCandidate = isset($value['preferredCandidate'])
            || isset($value['preferred_candidate'])
            || isset($value['preferredCandidateEstimatedRatio'])
            || isset($value['preferred_candidate_estimated_ratio'])
            || isset($value['estimatedRatio'])
            || isset($value['estimated_ratio']);
        $hasReviewFields = isset($value['riskLevel']) || isset($value['risk_level']) || isset($value['reviewHint']) || isset($value['review_hint']) || isset($value['candidateType']) || isset($value['candidate_type']);
        $hasNote = trim((string)($value['note'] ?? '')) !== '';

        return ($hasCandidates || $hasCandidateDetails || $hasPreferredCandidate || $hasRatios || $hasColors || $hasReviewFields || $hasNote) ? $value : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeNodeRemediationForStorage(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $hasText = trim((string)($value['summary'] ?? $value['documentationHint'] ?? $value['documentation_hint'] ?? '')) !== '';
        $hasSteps = is_array($value['steps'] ?? null) && $value['steps'] !== [];
        $hasMeta = trim((string)($value['recommendedOwner'] ?? $value['recommended_owner'] ?? $value['fixType'] ?? $value['fix_type'] ?? $value['confidence'] ?? '')) !== '';

        return ($hasText || $hasSteps || $hasMeta) ? $value : [];
    }

    private function encodeJsonForColumn(mixed $value): string
    {
        if (!is_array($value) || $value === []) {
            return '';
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR) ?: '';
        } catch (\JsonException) {
            return '';
        }
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
