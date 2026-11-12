<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

/**
 * Groups tx_a11y_ai_usage rows by backend user, feature, provider and model for the administrator's usage report. Every
 * total the report shows (site, per person, per feature) is a sum of these groups, so they reconcile by construction.
 *
 * Each row counts exactly once, in one money bucket chosen by its state (no row is counted as both reserved and settled):
 * - settled, cost_basis "reported"  → reportedMicroUsd: provider-reported tokens × the price valid at the time;
 * - settled, cost_basis "bound"     → boundMicroUsd: no usage reported, so the request's worst case (AQG cannot prove
 *   the provider did not bill it);
 * - settled, cost_basis "none"      → noCharge: rejected before processing (key, quota, rate limit…), $0 by rule;
 * - pending (admitted, not settled) → reservedMicroUsd: its worst case, held until settled (PI-03); it is neither a
 *   success nor a failure;
 * - cost_known 0                    → unknown: no price, so no amount (counted in requests and tokens only).
 * Rows from before 3.0 have no cost_basis; with a known price they count as reported.
 */
final class AiUsageBreakdown
{
    /**
     * @param iterable<array<string,mixed>> $rows
     * @return list<array{be_user:int,feature:string,provider:string,model:string,requests:int,succeeded:int,failed:int,pending:int,input_tokens:int,cache_read_tokens:int,output_tokens:int,unreported:int,reported_micro_usd:int,bound_micro_usd:int,bound_requests:int,no_charge:int,unknown:int,reserved_micro_usd:int}>
     */
    public static function group(iterable $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $user = max(0, (int)($row['be_user'] ?? 0));
            $feature = (string)($row['feature'] ?? '');
            $provider = (string)($row['provider'] ?? '');
            $model = (string)($row['model'] ?? '');
            $key = $user . "\0" . $feature . "\0" . $provider . "\0" . $model;
            $groups[$key] ??= [
                'be_user' => $user,
                'feature' => $feature,
                'provider' => $provider,
                'model' => $model,
                'requests' => 0,
                'succeeded' => 0,
                'failed' => 0,
                'pending' => 0,
                'input_tokens' => 0,
                'cache_read_tokens' => 0,
                'output_tokens' => 0,
                'unreported' => 0,
                'reported_micro_usd' => 0,
                'bound_micro_usd' => 0,
                'bound_requests' => 0,
                'no_charge' => 0,
                'unknown' => 0,
                'reserved_micro_usd' => 0,
            ];
            $group = &$groups[$key];
            $group['requests']++;
            $outcome = (string)($row['outcome'] ?? '');
            $known = (int)($row['cost_known'] ?? 0) === 1;
            if (!$known) {
                $group['unknown']++;
            }
            if ($outcome === AiUsageRecorder::OUTCOME_PENDING) {
                $group['pending']++;
                $group['reserved_micro_usd'] += $known ? max(0, (int)($row['reserved_cost_micro_usd'] ?? 0)) : 0;
                unset($group);
                continue;
            }
            $group[$outcome === 'success' ? 'succeeded' : 'failed']++;
            // input_tokens = every input token (ordinary + cache reads + cache writes), as the usage table always showed.
            $group['input_tokens'] += max(0, (int)($row['input_tokens'] ?? 0)) + max(0, (int)($row['cache_read_tokens'] ?? 0)) + max(0, (int)($row['cache_write_tokens'] ?? 0));
            $group['cache_read_tokens'] += max(0, (int)($row['cache_read_tokens'] ?? 0));
            $group['output_tokens'] += max(0, (int)($row['output_tokens'] ?? 0));
            $group['unreported'] += (int)($row['usage_reported'] ?? 0) === 1 ? 0 : 1;
            if ($known) {
                $cost = max(0, (int)($row['estimated_cost_micro_usd'] ?? 0));
                match ((string)($row['cost_basis'] ?? '')) {
                    'bound' => [$group['bound_micro_usd'] += $cost, $group['bound_requests']++],
                    'none' => $group['no_charge']++,
                    default => $group['reported_micro_usd'] += $cost,
                };
            }
            unset($group);
        }

        return array_values($groups);
    }
}
