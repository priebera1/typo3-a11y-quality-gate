<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;

/**
 * One calendar month of one site's AI usage, from the usage rows (administrators only, D6). The site total, the rows per
 * feature and model and the rows per backend user are all sums of the same AiUsageBreakdown groups, so they reconcile.
 *
 * Money (micro-USD, "estimated": provider invoices can differ and AQG cannot certify them):
 * - estimatedMicroUsd = reported + bound: settled requests at the price valid when they ran (never repriced);
 *   boundMicroUsd is the part for requests without usage data, counted at their worst case;
 * - reservedMicroUsd: pending (unsettled) attempts at their worst case — exposure, not spend;
 * - noCharge: rejected before processing ($0 by rule); unknown: no price, so no amount.
 * Token counts are what providers reported (input = ordinary + cached + cache-written; cachedInputTokens = cache reads).
 */
final class AiUsageSummaryService
{
    /** How many past months the report offers (the current month plus 12 earlier ones). */
    public const MONTHS_BACK = 12;

    private const EMPTY_TOTALS = [
        'requests' => 0, 'succeeded' => 0, 'failed' => 0, 'pending' => 0,
        'inputTokens' => 0, 'cachedInputTokens' => 0, 'outputTokens' => 0, 'unreported' => 0,
        'reportedMicroUsd' => 0, 'boundMicroUsd' => 0, 'boundRequests' => 0, 'noCharge' => 0, 'unknown' => 0,
        'reservedMicroUsd' => 0,
    ];

    public function __construct(
        private readonly AiUsageRepositoryInterface $repository,
        private readonly AiModelCompatibilityRegistry $registry,
    ) {}

    /** @return array<string,mixed> see month() */
    public function currentMonth(string $siteIdentifier, ?\DateTimeImmutable $now = null): array
    {
        return $this->month($siteIdentifier, '', $now);
    }

    /**
     * The first day of a requested "YYYY-MM" month, or of the current month for an empty, malformed, future or too old
     * value (server time, like the monthly budget).
     */
    public static function resolveMonth(string $month, ?\DateTimeImmutable $now = null): \DateTimeImmutable
    {
        $current = ($now ?? new \DateTimeImmutable())->modify('first day of this month')->setTime(0, 0);
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $match) !== 1) {
            return $current;
        }
        $requested = $current->setDate((int)$match[1], (int)$match[2], 1);
        $oldest = $current->modify('-' . self::MONTHS_BACK . ' months');

        return $requested > $current || $requested < $oldest ? $current : $requested;
    }

    /**
     * @return array{
     *     periodStart:int, periodEnd:int, month:string, isCurrentMonth:bool, loadFailed:bool,
     *     totals:array<string,int>,
     *     rows:list<array<string,mixed>>,
     *     people:list<array<string,mixed>>
     * }
     */
    public function month(string $siteIdentifier, string $month = '', ?\DateTimeImmutable $now = null): array
    {
        $start = self::resolveMonth($month, $now);
        $end = $start->modify('+1 month');
        $current = self::resolveMonth('', $now);
        $groups = [];
        $loadFailed = false;
        if (trim($siteIdentifier) !== '') {
            try {
                $groups = $this->repository->breakdown($siteIdentifier, $start->getTimestamp(), $end->getTimestamp());
            } catch (\Throwable) {
                // The report says it could not be loaded; it never shows an empty month instead.
                $loadFailed = true;
                $groups = [];
            }
        }

        $totals = self::EMPTY_TOTALS;
        $rows = [];
        $people = [];
        foreach ($groups as $group) {
            $amounts = self::amounts($group);
            $totals = self::add($totals, $amounts);
            $rowKey = $group['feature'] . "\0" . $group['provider'] . "\0" . $group['model'];
            $rows[$rowKey] ??= [
                'feature' => $group['feature'],
                'provider' => $group['provider'],
                'providerLabel' => AiProviderCatalog::label($group['provider']) ?: $group['provider'],
                'model' => $group['model'],
                'modelLabel' => (string)($this->registry->find($group['model'])['label'] ?? $group['model']),
            ] + self::EMPTY_TOTALS;
            $rows[$rowKey] = self::add($rows[$rowKey], $amounts);
            $user = (int)$group['be_user'];
            $people[$user] ??= ['beUser' => $user, 'features' => []] + self::EMPTY_TOTALS;
            $people[$user] = self::add($people[$user], $amounts);
            $people[$user]['features'][$group['feature']] = ($people[$user]['features'][$group['feature']] ?? 0) + $group['requests'];
        }

        $featureOrder = array_flip(AiTask::all());
        usort($rows, static fn (array $a, array $b): int => [
            $featureOrder[$a['feature']] ?? 99, $a['provider'], $a['model'],
        ] <=> [
            $featureOrder[$b['feature']] ?? 99, $b['provider'], $b['model'],
        ]);
        foreach ($people as $user => $person) {
            arsort($person['features']);
            $features = [];
            foreach ($person['features'] as $feature => $requests) {
                $features[] = ['feature' => (string)$feature, 'requests' => (int)$requests];
            }
            $people[$user]['features'] = $features;
            $people[$user]['connectionTestsOnly'] = count($features) === 1 && $features[0]['feature'] === AiTask::CONNECTION_TEST;
        }
        // Largest exposure first (estimated + reserved), then most requests, then user id: stable between page loads.
        usort($people, static fn (array $a, array $b): int => [
            $b['reportedMicroUsd'] + $b['boundMicroUsd'] + $b['reservedMicroUsd'], $b['requests'], $a['beUser'],
        ] <=> [
            $a['reportedMicroUsd'] + $a['boundMicroUsd'] + $a['reservedMicroUsd'], $a['requests'], $b['beUser'],
        ]);

        return [
            'periodStart' => $start->getTimestamp(),
            'periodEnd' => $end->getTimestamp(),
            'month' => $start->format('Y-m'),
            'isCurrentMonth' => $start == $current,
            'loadFailed' => $loadFailed,
            'totals' => self::finish($totals),
            'rows' => array_map(self::finish(...), array_values($rows)),
            'people' => array_map(self::finish(...), $people),
        ];
    }

    /**
     * @param array<string,mixed> $group
     * @return array<string,int>
     */
    private static function amounts(array $group): array
    {
        return [
            'requests' => (int)$group['requests'],
            'succeeded' => (int)$group['succeeded'],
            'failed' => (int)$group['failed'],
            'pending' => (int)$group['pending'],
            'inputTokens' => (int)$group['input_tokens'],
            'cachedInputTokens' => (int)$group['cache_read_tokens'],
            'outputTokens' => (int)$group['output_tokens'],
            'unreported' => (int)$group['unreported'],
            'reportedMicroUsd' => (int)$group['reported_micro_usd'],
            'boundMicroUsd' => (int)$group['bound_micro_usd'],
            'boundRequests' => (int)$group['bound_requests'],
            'noCharge' => (int)$group['no_charge'],
            'unknown' => (int)$group['unknown'],
            'reservedMicroUsd' => (int)$group['reserved_micro_usd'],
        ];
    }

    /**
     * @param array<string,mixed> $sum
     * @param array<string,int> $amounts
     * @return array<string,mixed>
     */
    private static function add(array $sum, array $amounts): array
    {
        foreach ($amounts as $key => $value) {
            $sum[$key] = (int)($sum[$key] ?? 0) + $value;
        }

        return $sum;
    }

    /**
     * Derived amounts: estimatedMicroUsd (settled, reported + bound), exposureMicroUsd (+ reserved) and the cost state
     * the template words: "unknown" (no request had a price), "zero" (every priced, settled request cost $0 by rule or
     * by reported usage), "estimated". costMicroUsd/costUnknown/costLabel keep their earlier meaning (estimated +
     * reserved; requests without price; '' = unknown) for existing callers.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function finish(array $row): array
    {
        $row['estimatedMicroUsd'] = (int)$row['reportedMicroUsd'] + (int)$row['boundMicroUsd'];
        $row['exposureMicroUsd'] = $row['estimatedMicroUsd'] + (int)$row['reservedMicroUsd'];
        $priced = (int)$row['requests'] - (int)$row['unknown'];
        $row['costState'] = match (true) {
            (int)$row['requests'] > 0 && $priced <= 0 => 'unknown',
            $row['estimatedMicroUsd'] === 0 && (int)$row['reservedMicroUsd'] === 0 => 'zero',
            default => 'estimated',
        };
        $row['costMicroUsd'] = $row['exposureMicroUsd'];
        $row['costUnknown'] = (int)$row['unknown'];
        $row['costLabel'] = $row['costState'] === 'unknown' ? '' : AiMoney::formatUsd($row['exposureMicroUsd']);

        return $row;
    }
}
