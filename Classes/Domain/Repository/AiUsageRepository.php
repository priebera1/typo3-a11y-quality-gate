<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Ai\Usage\AiUsageBreakdown;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;
use TYPO3\CMS\Core\Database\Connection;

final class AiUsageRepository extends AbstractRepository implements AiUsageRepositoryInterface
{
    public function add(array $row): void
    {
        $this->getConnection(Tables::AI_USAGE)->insert(Tables::AI_USAGE, ['pid' => 0] + $row);
    }

    public function admit(array $row): int
    {
        $connection = $this->getConnection(Tables::AI_USAGE);
        $connection->insert(Tables::AI_USAGE, ['pid' => 0] + $row);
        $uid = (int)$connection->lastInsertId();
        if ($uid <= 0) {
            throw new \RuntimeException('The usage row was not written.', 1771002407);
        }

        return $uid;
    }

    public function settle(int $uid, array $fields): bool
    {
        if ($uid <= 0) {
            return false;
        }

        return $this->getConnection(Tables::AI_USAGE)->update(Tables::AI_USAGE, $fields, ['uid' => $uid, 'outcome' => 'pending']) === 1;
    }

    public function withdraw(int $uid): void
    {
        if ($uid > 0) {
            $this->getConnection(Tables::AI_USAGE)->delete(Tables::AI_USAGE, ['uid' => $uid, 'outcome' => 'pending']);
        }
    }

    public function summarize(string $siteIdentifier, int $from, int $until): array
    {
        $qb = $this->getQueryBuilder(Tables::AI_USAGE);
        $rows = $qb->select('feature', 'provider', 'model', 'outcome', 'input_tokens', 'cache_read_tokens', 'cache_write_tokens', 'output_tokens', 'usage_reported', 'estimated_cost_micro_usd', 'reserved_cost_micro_usd', 'cost_known')
            ->from(Tables::AI_USAGE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($from, Connection::PARAM_INT)),
                $qb->expr()->lt('crdate', $qb->createNamedParameter($until, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $groups = [];
        foreach ($rows as $row) {
            $key = $row['feature'] . "\0" . $row['provider'] . "\0" . $row['model'];
            $groups[$key] ??= [
                'feature' => (string)$row['feature'],
                'provider' => (string)$row['provider'],
                'model' => (string)$row['model'],
                'requests' => 0,
                'failed' => 0,
                'input_tokens' => 0,
                'cache_read_tokens' => 0,
                'cache_write_tokens' => 0,
                'output_tokens' => 0,
                'unreported' => 0,
                'cost_micro_usd' => 0,
                'cost_unknown' => 0,
            ];
            $groups[$key]['requests']++;
            $groups[$key]['failed'] += (string)$row['outcome'] === 'success' ? 0 : 1;
            // input_tokens = every input token (ordinary + cache reads + cache writes), as before 3.0 for OpenAI rows,
            // whose ordinary input then still included the cached share.
            $groups[$key]['input_tokens'] += max(0, (int)$row['input_tokens']) + max(0, (int)$row['cache_read_tokens']) + max(0, (int)$row['cache_write_tokens']);
            $groups[$key]['cache_read_tokens'] += max(0, (int)$row['cache_read_tokens']);
            $groups[$key]['cache_write_tokens'] += max(0, (int)$row['cache_write_tokens']);
            $groups[$key]['output_tokens'] += max(0, (int)$row['output_tokens']);
            $groups[$key]['unreported'] += (int)$row['usage_reported'] === 1 ? 0 : 1;
            // An unsettled attempt (still pending) is reported at its worst case.
            $groups[$key]['cost_micro_usd'] += max(0, (int)$row['estimated_cost_micro_usd']) + max(0, (int)($row['reserved_cost_micro_usd'] ?? 0));
            $groups[$key]['cost_unknown'] += (int)$row['cost_known'] === 1 ? 0 : 1;
        }

        return array_values($groups);
    }

    public function breakdown(string $siteIdentifier, int $from, int $until): array
    {
        if (trim($siteIdentifier) === '' || $until <= $from) {
            return [];
        }
        $qb = $this->getQueryBuilder(Tables::AI_USAGE);
        $result = $qb->select('be_user', 'feature', 'provider', 'model', 'outcome', 'input_tokens', 'cache_read_tokens', 'cache_write_tokens', 'output_tokens', 'usage_reported', 'estimated_cost_micro_usd', 'reserved_cost_micro_usd', 'cost_known', 'cost_basis')
            ->from(Tables::AI_USAGE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($from, Connection::PARAM_INT)),
                $qb->expr()->lt('crdate', $qb->createNamedParameter($until, Connection::PARAM_INT)),
            )
            ->executeQuery();

        return AiUsageBreakdown::group((static function () use ($result): \Generator {
            while (($row = $result->fetchAssociative()) !== false) {
                yield $row;
            }
        })());
    }

    public function summarizeConversation(string $siteIdentifier, int $beUser, string $conversationRef, int $since): array
    {
        if ($conversationRef === '') {
            return ['requests' => 0, 'cost_micro_usd' => 0, 'cost_unknown' => 0];
        }
        $qb = $this->getQueryBuilder(Tables::AI_USAGE);
        $row = $qb->selectLiteral('COUNT(*) AS requests', 'COALESCE(SUM(estimated_cost_micro_usd + reserved_cost_micro_usd), 0) AS cost', 'COALESCE(SUM(CASE WHEN cost_known = 0 THEN 1 ELSE 0 END), 0) AS unknown_cost')
            ->from(Tables::AI_USAGE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUser, Connection::PARAM_INT)),
                $qb->expr()->eq('conversation_ref', $qb->createNamedParameter($conversationRef)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($since, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchAssociative();

        return [
            'requests' => (int)($row['requests'] ?? 0),
            'cost_micro_usd' => (int)($row['cost'] ?? 0),
            'cost_unknown' => (int)($row['unknown_cost'] ?? 0),
        ];
    }

    public function countUserFeatureRequests(string $siteIdentifier, int $beUser, string $feature, int $since): int
    {
        $qb = $this->getQueryBuilder(Tables::AI_USAGE);

        return (int)$qb->count('uid')
            ->from(Tables::AI_USAGE)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('be_user', $qb->createNamedParameter($beUser, Connection::PARAM_INT)),
                $qb->expr()->eq('feature', $qb->createNamedParameter($feature)),
                $qb->expr()->gte('crdate', $qb->createNamedParameter($since, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();
    }
}
