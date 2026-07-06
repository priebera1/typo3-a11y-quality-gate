<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class RemoteIssueRepository extends AbstractRepository
{
    /** @var array<string, array<string, bool>> */
    private array $tableColumnCache = [];

    public function deleteByRemoteScan(int $remoteScanUid): void
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        $queryBuilder
            ->delete(Tables::REMOTE_ISSUE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->executeStatement();
    }

    /**
     * @return array<int, int>
     */
    public function findUidsByRemoteScan(int $remoteScanUid): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        $rows = $queryBuilder
            ->select('uid')
            ->from(Tables::REMOTE_ISSUE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchFirstColumn();

        return array_values(array_map('intval', $rows ?: []));
    }

    public function saveIssue(
        int $remoteScanUid,
        int $remoteScanPageUid,
        array $issue,
        int $pid = 0,
    ): int {
        $connection = $this->getConnection(Tables::REMOTE_ISSUE);
        $now = time();

        $row = [
            'pid' => $pid,
            'remote_scan' => $remoteScanUid,
            'remote_scan_page' => $remoteScanPageUid,
            'rule_id' => (string)($issue['ruleId'] ?? $issue['rule_id'] ?? $issue['id'] ?? $issue['rule'] ?? ''),
            'impact' => (string)($issue['impact'] ?? $issue['severity'] ?? ''),
            'help' => (string)($issue['help'] ?? $issue['description'] ?? $issue['title'] ?? ''),
            'help_url' => (string)($issue['helpUrl'] ?? $issue['help_url'] ?? $issue['url'] ?? ''),
            'plain_language_title' => $this->extractMetadataString($issue, 'plainLanguageTitle', 'plain_language_title', 255),
            'plain_language_description' => $this->extractMetadataString($issue, 'plainLanguageDescription', 'plain_language_description', 2000),
            'affected_users_json' => $this->extractMetadataJson($issue, 'affectedUsers', 'affected_users'),
            'wcag_references_json' => $this->extractMetadataJson($issue, 'wcagReferences', 'wcag_references', 'wcag'),
            'standards_json' => $this->extractMetadataJson($issue, 'standards', 'standards'),
            'rule_documentation_json' => $this->extractMetadataJson($issue, 'ruleDocumentation', 'rule_documentation', 'documentation'),
            'technical_tags_json' => $this->extractMetadataJson($issue, 'technicalTags', 'technical_tags', 'tags'),
            'rule_metadata_json' => $this->encodeRuleMetadata($issue),
            'guidance_why_it_matters' => $this->extractGuidanceText($issue, 'whyItMatters', 'why_it_matters'),
            'guidance_how_to_fix' => $this->extractGuidanceText($issue, 'howToFix', 'how_to_fix'),
            'who_should_fix' => $this->extractGuidanceChoice($issue, 'whoShouldFix', 'who_should_fix'),
            'fix_type' => $this->extractGuidanceChoice($issue, 'fixType', 'fix_type'),
            'confidence' => $this->extractGuidanceChoice($issue, 'confidence', 'confidence'),
            'nodes_count' => is_array($issue['nodes'] ?? null) ? count($issue['nodes']) : 0,
            'fingerprint' => (string)($issue['fingerprint'] ?? ''),
            'status' => (string)($issue['status'] ?? 'open'),
            'crdate' => $now,
            'tstamp' => $now,
        ];

        $connection->insert(Tables::REMOTE_ISSUE, $this->filterExistingColumns(Tables::REMOTE_ISSUE, $row));

        return (int)$connection->lastInsertId();
    }




    /**
     * @param array<string, mixed> $issue
     */
    private function extractGuidanceText(array $issue, string $camelKey, string $snakeKey): string
    {
        $guidance = is_array($issue['guidance'] ?? null) ? $issue['guidance'] : [];
        $value = $issue[$camelKey] ?? $issue[$snakeKey] ?? $guidance[$camelKey] ?? $guidance[$snakeKey] ?? '';

        return trim((string)$value);
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function extractGuidanceChoice(array $issue, string $camelKey, string $snakeKey): string
    {
        $value = strtolower($this->extractGuidanceText($issue, $camelKey, $snakeKey));
        $value = preg_replace('/[^a-z0-9_-]+/', '_', $value) ?? '';
        $value = trim($value, '_-');

        return substr($value, 0, 50);
    }


    /**
     * @param array<string, mixed> $issue
     * @return array<string, mixed>
     */
    private function extractRuleMetadata(array $issue): array
    {
        $metadata = [];
        foreach (['ruleMetadata', 'rule_metadata', 'metadata', 'normalizedMetadata', 'normalized_metadata'] as $key) {
            if (is_array($issue[$key] ?? null)) {
                $metadata = array_replace_recursive($metadata, $issue[$key]);
            }
        }

        foreach ([
            'plainLanguageTitle', 'plain_language_title',
            'plainLanguageDescription', 'plain_language_description',
            'affectedUsers', 'affected_users',
            'wcagReferences', 'wcag_references', 'wcag',
            'standards', 'tags', 'technicalTags', 'technical_tags',
            'ruleDocumentation', 'rule_documentation', 'documentation',
            'whyItMatters', 'why_it_matters',
            'remediation', 'howToFix', 'how_to_fix',
            'suggestedOwner', 'suggested_owner', 'fixType', 'fix_type',
        ] as $key) {
            if (array_key_exists($key, $issue) && !array_key_exists($key, $metadata)) {
                $metadata[$key] = $issue[$key];
            }
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function extractMetadataString(array $issue, string $camelKey, string $snakeKey, int $maxLength): string
    {
        $metadata = $this->extractRuleMetadata($issue);
        $value = $issue[$camelKey] ?? $issue[$snakeKey] ?? $metadata[$camelKey] ?? $metadata[$snakeKey] ?? '';
        if (is_array($value)) {
            return '';
        }

        return substr(trim((string)$value), 0, $maxLength);
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function extractMetadataJson(array $issue, string $camelKey, string $snakeKey, string ...$aliases): string
    {
        $metadata = $this->extractRuleMetadata($issue);
        $value = [];
        foreach (array_merge([$camelKey, $snakeKey], $aliases) as $key) {
            if (array_key_exists($key, $issue)) {
                $value = $issue[$key];
                break;
            }
            if (array_key_exists($key, $metadata)) {
                $value = $metadata[$key];
                break;
            }
        }
        if ($value === null || $value === '' || $value === []) {
            return '';
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            } else {
                $value = [$value];
            }
        }
        if (!is_array($value)) {
            return '';
        }

        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $issue
     */
    private function encodeRuleMetadata(array $issue): string
    {
        $metadata = $this->extractRuleMetadata($issue);
        if ($metadata === []) {
            return '';
        }

        return (string)json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function filterExistingColumns(string $tableName, array $row): array
    {
        $columns = $this->getExistingColumns($tableName);
        if ($columns === []) {
            return $row;
        }

        return array_filter(
            $row,
            static fn (string $column): bool => isset($columns[$column]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @return array<string, bool>
     */
    private function getExistingColumns(string $tableName): array
    {
        if (isset($this->tableColumnCache[$tableName])) {
            return $this->tableColumnCache[$tableName];
        }

        try {
            $schemaManager = $this->getConnection($tableName)->createSchemaManager();
            $columns = [];
            foreach ($schemaManager->listTableColumns($tableName) as $column) {
                $columns[$column->getName()] = true;
            }

            return $this->tableColumnCache[$tableName] = $columns;
        } catch (\Throwable) {
            return $this->tableColumnCache[$tableName] = [];
        }
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    public function findIssueRowsForRemoteScan(int $remoteScanUid): array
    {
        if ($remoteScanUid <= 0) {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        return $queryBuilder
            ->select('ri.*')
            ->addSelectLiteral('rsp.uid AS remote_scan_page_uid')
            ->from(Tables::REMOTE_ISSUE, 'ri')
            ->leftJoin(
                'ri',
                Tables::REMOTE_SCAN_PAGE,
                'rsp',
                $queryBuilder->expr()->eq('rsp.uid', 'ri.remote_scan_page')
            )
            ->where(
                $queryBuilder->expr()->eq(
                    'ri.remote_scan',
                    $queryBuilder->createNamedParameter($remoteScanUid, Connection::PARAM_INT)
                )
            )
            ->andWhere(
                $queryBuilder->expr()->neq(
                    'ri.status',
                    $queryBuilder->createNamedParameter('ignored')
                )
            )
            ->orderBy('ri.impact', 'ASC')
            ->addOrderBy('ri.rule_id', 'ASC')
            ->addOrderBy('ri.uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findByRemoteScanPage(int $remoteScanPageUid): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        return $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_ISSUE)
            ->where(
                $queryBuilder->expr()->eq(
                    'remote_scan_page',
                    $queryBuilder->createNamedParameter($remoteScanPageUid, Connection::PARAM_INT)
                )
            )
            ->orderBy('impact', 'ASC')
            ->addOrderBy('rule_id', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();
    }

    public function findOneByUid(int $uid): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::REMOTE_ISSUE);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::REMOTE_ISSUE)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
