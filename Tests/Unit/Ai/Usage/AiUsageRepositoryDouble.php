<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Usage\AiUsageBreakdown;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;

/**
 * In-memory tx_a11y_ai_usage for unit tests: admission inserts a pending row, settlement completes it, withdrawal removes
 * a pending one. $onSettled receives each settled row (the full row, as the pre-3.0 add() did). The switches simulate a
 * failing admission (INSERT) or settlement (UPDATE) write while reads keep working (PI-03).
 */
final class AiUsageRepositoryDouble implements AiUsageRepositoryInterface
{
    /** @var array<int, array<string, int|string>> */
    public array $rows = [];
    public bool $failAdmission = false;
    public bool $failSettlement = false;
    /** Runs at the start of admit(), e.g. to let a concurrent request's row appear (race regressions). */
    public ?\Closure $beforeAdmit = null;
    /** @var list<string> */
    public array $events = [];
    private int $nextUid = 1;

    /** @param (\Closure(array<string, int|string>): void)|null $onSettled */
    public function __construct(private readonly ?\Closure $onSettled = null) {}

    public function add(array $row): void
    {
        $this->rows[$this->nextUid++] = $row;
    }

    public function admit(array $row): int
    {
        $this->events[] = 'admit';
        if ($this->beforeAdmit !== null) {
            ($this->beforeAdmit)();
        }
        if ($this->failAdmission) {
            throw new \RuntimeException('QA TEST ADMISSION WRITE FAILURE');
        }
        $uid = $this->nextUid++;
        $this->rows[$uid] = $row;

        return $uid;
    }

    public function settle(int $uid, array $fields): bool
    {
        $this->events[] = 'settle';
        if ($this->failSettlement) {
            throw new \RuntimeException('QA TEST SETTLEMENT WRITE FAILURE');
        }
        if (($this->rows[$uid]['outcome'] ?? '') !== 'pending') {
            return false;
        }
        $this->rows[$uid] = $fields + $this->rows[$uid];
        if ($this->onSettled !== null) {
            ($this->onSettled)($this->rows[$uid]);
        }

        return true;
    }

    public function withdraw(int $uid): void
    {
        $this->events[] = 'withdraw';
        if (($this->rows[$uid]['outcome'] ?? '') === 'pending') {
            unset($this->rows[$uid]);
        }
    }

    /** @return list<array<string, int|string>> */
    public function pending(): array
    {
        return array_values(array_filter($this->rows, static fn (array $row): bool => $row['outcome'] === 'pending'));
    }

    public function summarize(string $siteIdentifier, int $from, int $until): array
    {
        return [];
    }

    public function breakdown(string $siteIdentifier, int $from, int $until): array
    {
        return AiUsageBreakdown::group(array_filter($this->rows, static fn (array $row): bool => $row['site_identifier'] === $siteIdentifier
            && (int)$row['crdate'] >= $from && (int)$row['crdate'] < $until));
    }

    public function summarizeConversation(string $siteIdentifier, int $beUser, string $conversationRef, int $since): array
    {
        $requests = 0;
        $cost = 0;
        $unknown = 0;
        foreach ($this->rows as $row) {
            if ($conversationRef !== '' && ($row['conversation_ref'] ?? '') === $conversationRef && (int)$row['be_user'] === $beUser
                && $row['site_identifier'] === $siteIdentifier && (int)$row['crdate'] >= $since) {
                $requests++;
                $cost += (int)($row['estimated_cost_micro_usd'] ?? 0) + (int)($row['reserved_cost_micro_usd'] ?? 0);
                $unknown += (int)($row['cost_known'] ?? 0) === 1 ? 0 : 1;
            }
        }

        return ['requests' => $requests, 'cost_micro_usd' => $cost, 'cost_unknown' => $unknown];
    }

    public function countUserFeatureRequests(string $siteIdentifier, int $beUser, string $feature, int $since): int
    {
        return count(array_filter($this->rows, static fn (array $row): bool => $row['site_identifier'] === $siteIdentifier
            && (int)$row['be_user'] === $beUser && $row['feature'] === $feature && (int)$row['crdate'] >= $since));
    }
}
