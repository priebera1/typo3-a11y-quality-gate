<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageBreakdown;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageSummaryService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;

/**
 * The administrator's usage report (Settings › AI suggestions › Usage & limits): per-person totals reconcile with the
 * site total, each row counts in exactly one money bucket (reported, worst case, $0 by rule, unknown, reserved), pending
 * attempts are neither failures nor spend, and another site's or month's rows never appear.
 */
final class AiUsageSummaryServiceTest extends TestCase
{
    private const NOW = '2026-11-20 12:00:00';

    /** Known synthetic usage of site "client-b" in November 2026 (the same set the browser check used). */
    private function fixture(): AiUsageRepositoryDouble
    {
        $repository = new AiUsageRepositoryDouble();
        $t = (new \DateTimeImmutable('2026-11-10 10:00:00'))->getTimestamp();
        $row = static fn (int $user, string $feature, string $outcome, int $in, int $out, int $reported, int $cacheRead, int $cost, int $known, string $basis, int $reserved = 0, string $model = 'gpt-5.4-mini', string $site = 'client-b', ?int $crdate = null): array => [
            'site_identifier' => $site, 'be_user' => $user, 'feature' => $feature, 'provider' => 'openai', 'model' => $model,
            'outcome' => $outcome, 'input_tokens' => $in, 'output_tokens' => $out, 'usage_reported' => $reported,
            'cache_read_tokens' => $cacheRead, 'cache_write_tokens' => 0, 'estimated_cost_micro_usd' => $cost,
            'cost_known' => $known, 'cost_basis' => $basis, 'reserved_cost_micro_usd' => $reserved, 'crdate' => $crdate ?? $t,
        ];
        foreach ([
            $row(3, 'alt_text', 'success', 1000, 100, 1, 0, 120_000, 1, 'reported'),
            $row(3, 'alt_text', 'success', 1500, 200, 1, 500, 240_000, 1, 'reported'),
            $row(3, 'link_text', 'success', 500, 50, 1, 0, 60_000, 1, 'reported'),
            $row(3, 'alt_text', 'failed_1771002330', 0, 0, 0, 0, 900_000, 1, 'bound'),
            $row(3, 'alt_text', 'failed_1771002310', 0, 0, 0, 0, 0, 1, 'none'),
            $row(7, 'assistant', 'success', 3000, 400, 1, 0, 550_000, 1, 'reported'),
            $row(7, 'assistant', 'pending', 0, 0, 0, 0, 0, 1, 'pending', 2_000_000),
            $row(8, 'explain', 'success', 800, 80, 1, 0, 100_000, 1, 'reported'), // deleted account
            $row(9, 'writing', 'success', 400, 40, 1, 0, 50_000, 1, 'reported'), // disabled account
            $row(0, 'connection_test', 'failed_1771002310', 0, 0, 0, 0, 0, 1, 'none'), // no backend user
            $row(3, 'iframe_title', 'success', 700, 70, 1, 0, 0, 0, 'unknown', 0, 'mystery-model'),
            $row(77, 'alt_text', 'success', 100, 10, 1, 0, 15_000, 1, 'reported'), // record gone
            // Never part of client-b / November 2026:
            $row(3, 'alt_text', 'success', 9000, 900, 1, 0, 7_000_000, 1, 'reported', 0, 'gpt-5.4-mini', 'main'),
            $row(3, 'alt_text', 'success', 2000, 100, 1, 0, 300_000, 1, 'reported', 0, 'gpt-5.4-mini', 'client-b', (new \DateTimeImmutable('2026-10-15 12:00:00'))->getTimestamp()),
            $row(3, 'alt_text', 'pending', 0, 0, 0, 0, 0, 1, 'pending', 5_000_000, 'gpt-5.4-mini', 'client-b', (new \DateTimeImmutable('2026-12-01 00:00:00'))->getTimestamp()),
        ] as $usage) {
            $repository->add($usage);
        }

        return $repository;
    }

    private function report(?AiUsageRepositoryInterface $repository = null, string $month = ''): array
    {
        return (new AiUsageSummaryService($repository ?? $this->fixture(), new AiModelCompatibilityRegistry()))
            ->month('client-b', $month, new \DateTimeImmutable(self::NOW));
    }

    #[Test]
    public function siteTotalsSeparateOutcomesAndMoneyBuckets(): void
    {
        $totals = $this->report()['totals'];

        self::assertSame(12, $totals['requests']);
        self::assertSame(8, $totals['succeeded']);
        self::assertSame(3, $totals['failed'], 'A pending attempt is not a failure.');
        self::assertSame(1, $totals['pending']);
        self::assertSame(8500, $totals['inputTokens']);
        self::assertSame(500, $totals['cachedInputTokens']);
        self::assertSame(950, $totals['outputTokens']);
        self::assertSame(1_135_000, $totals['reportedMicroUsd']);
        self::assertSame(900_000, $totals['boundMicroUsd']);
        self::assertSame(1, $totals['boundRequests']);
        self::assertSame(2, $totals['noCharge']);
        self::assertSame(1, $totals['unknown']);
        self::assertSame(2_000_000, $totals['reservedMicroUsd']);
        self::assertSame(2_035_000, $totals['estimatedMicroUsd'], 'Spend = reported + worst case; the reservation is not spend.');
        self::assertSame(4_035_000, $totals['exposureMicroUsd']);
        self::assertSame('estimated', $totals['costState']);
    }

    #[Test]
    public function perPersonTotalsReconcileWithTheSiteTotal(): void
    {
        $report = $this->report();
        $sum = static fn (array $rows, string $key): int => array_sum(array_column($rows, $key));
        foreach (['requests', 'succeeded', 'failed', 'pending', 'inputTokens', 'cachedInputTokens', 'outputTokens', 'reportedMicroUsd', 'boundMicroUsd', 'noCharge', 'unknown', 'reservedMicroUsd', 'estimatedMicroUsd', 'exposureMicroUsd'] as $key) {
            self::assertSame($report['totals'][$key], $sum($report['people'], $key), 'people: ' . $key);
            self::assertSame($report['totals'][$key], $sum($report['rows'], $key), 'features: ' . $key);
        }
    }

    #[Test]
    public function peopleAreOrderedByExposureWithKnownAmounts(): void
    {
        $people = $this->report()['people'];

        self::assertSame([7, 3, 8, 9, 77, 0], array_column($people, 'beUser'));
        $byUser = array_column($people, null, 'beUser');
        self::assertSame(550_000, $byUser[7]['estimatedMicroUsd']);
        self::assertSame(2_000_000, $byUser[7]['reservedMicroUsd']);
        self::assertSame(1, $byUser[7]['pending']);
        self::assertSame(0, $byUser[7]['failed']);
        self::assertSame(6, $byUser[3]['requests']);
        self::assertSame(2, $byUser[3]['failed']);
        self::assertSame(1_320_000, $byUser[3]['estimatedMicroUsd']);
        self::assertSame(1, $byUser[3]['unknown']);
        self::assertSame(4200, $byUser[3]['inputTokens']);
        self::assertSame(420, $byUser[3]['outputTokens']);
        self::assertSame([['feature' => 'alt_text', 'requests' => 4], ['feature' => 'link_text', 'requests' => 1], ['feature' => 'iframe_title', 'requests' => 1]], $byUser[3]['features']);
        self::assertSame(100_000, $byUser[8]['estimatedMicroUsd'], 'A deleted account keeps its historical usage.');
        self::assertSame(15_000, $byUser[77]['estimatedMicroUsd']);
        self::assertSame('zero', $byUser[0]['costState'], 'Rejected before processing: $0 by rule, not "unknown".');
        self::assertTrue($byUser[0]['connectionTestsOnly']);
    }

    #[Test]
    public function onlyFailuresWithoutUsageAreNotReportedAsFree(): void
    {
        $repository = new AiUsageRepositoryDouble();
        $t = (new \DateTimeImmutable('2026-11-10 10:00:00'))->getTimestamp();
        foreach (['none', 'none', 'bound'] as $basis) {
            $repository->add(['site_identifier' => 'client-b', 'be_user' => 3, 'feature' => 'alt_text', 'provider' => 'openai', 'model' => 'gpt-5.4-mini',
                'outcome' => 'failed_1', 'input_tokens' => 0, 'output_tokens' => 0, 'usage_reported' => 0,
                'estimated_cost_micro_usd' => $basis === 'bound' ? 400_000 : 0, 'cost_known' => 1, 'cost_basis' => $basis, 'crdate' => $t]);
        }
        $totals = $this->report($repository)['totals'];

        self::assertSame(3, $totals['failed']);
        self::assertSame(0, $totals['inputTokens']);
        self::assertSame(2, $totals['noCharge']);
        self::assertSame(400_000, $totals['estimatedMicroUsd'], 'A failure after sending counts at its worst case, never as $0.');
        self::assertSame('estimated', $totals['costState']);
    }

    #[Test]
    public function aMonthWithoutPricesIsUnknownNotZero(): void
    {
        $repository = new AiUsageRepositoryDouble();
        $repository->add(['site_identifier' => 'client-b', 'be_user' => 3, 'feature' => 'alt_text', 'provider' => 'openai', 'model' => 'x',
            'outcome' => 'success', 'input_tokens' => 10, 'output_tokens' => 1, 'usage_reported' => 1, 'estimated_cost_micro_usd' => 0,
            'cost_known' => 0, 'cost_basis' => 'unknown', 'crdate' => (new \DateTimeImmutable('2026-11-10'))->getTimestamp()]);

        self::assertSame('unknown', $this->report($repository)['totals']['costState']);
        self::assertSame('', $this->report($repository)['totals']['costLabel']);
    }

    #[Test]
    public function anEarlierMonthCanBeSelectedAndOutOfRangeValuesFallBack(): void
    {
        $october = $this->report(null, '2026-10');
        self::assertSame('2026-10', $october['month']);
        self::assertFalse($october['isCurrentMonth']);
        self::assertSame(1, $october['totals']['requests']);
        self::assertSame(300_000, $october['totals']['estimatedMicroUsd']);

        $now = new \DateTimeImmutable(self::NOW);
        foreach (['2026-12', '2025-10', '2026-13', '11/2026', 'x', ''] as $value) {
            self::assertSame('2026-11-01', AiUsageSummaryService::resolveMonth($value, $now)->format('Y-m-d'), $value);
        }
        self::assertSame('2025-11-01', AiUsageSummaryService::resolveMonth('2025-11', $now)->format('Y-m-d'));
    }

    #[Test]
    public function aFailedLoadIsReportedAndAnEmptySiteReadsNothing(): void
    {
        $repository = $this->createMock(AiUsageRepositoryInterface::class);
        $repository->method('breakdown')->willThrowException(new \RuntimeException('db down'));
        $report = $this->report($repository);
        self::assertTrue($report['loadFailed']);
        self::assertSame(0, $report['totals']['requests']);

        $never = $this->createMock(AiUsageRepositoryInterface::class);
        $never->expects(self::never())->method('breakdown');
        self::assertFalse((new AiUsageSummaryService($never, new AiModelCompatibilityRegistry()))->month('  ')['loadFailed']);
    }

    #[Test]
    public function eachRowCountsInExactlyOneBucket(): void
    {
        $groups = AiUsageBreakdown::group([
            ['be_user' => 1, 'feature' => 'alt_text', 'provider' => 'openai', 'model' => 'm', 'outcome' => 'pending', 'cost_known' => 1, 'reserved_cost_micro_usd' => 50, 'estimated_cost_micro_usd' => 0, 'cost_basis' => 'pending'],
            ['be_user' => 1, 'feature' => 'alt_text', 'provider' => 'openai', 'model' => 'm', 'outcome' => 'success', 'cost_known' => 1, 'reserved_cost_micro_usd' => 0, 'estimated_cost_micro_usd' => 70, 'cost_basis' => 'reported', 'usage_reported' => 1, 'input_tokens' => 5, 'output_tokens' => 2],
            ['be_user' => 1, 'feature' => 'alt_text', 'provider' => 'openai', 'model' => 'm', 'outcome' => 'pending', 'cost_known' => 0, 'reserved_cost_micro_usd' => 0, 'estimated_cost_micro_usd' => 0, 'cost_basis' => 'pending'],
            ['be_user' => 1, 'feature' => 'alt_text', 'provider' => 'openai', 'model' => 'm', 'outcome' => 'success', 'cost_known' => 1, 'estimated_cost_micro_usd' => 9, 'cost_basis' => '', 'usage_reported' => 1],
        ]);

        self::assertCount(1, $groups);
        self::assertSame(4, $groups[0]['requests']);
        self::assertSame(2, $groups[0]['pending']);
        self::assertSame(2, $groups[0]['succeeded']);
        self::assertSame(0, $groups[0]['failed']);
        self::assertSame(50, $groups[0]['reserved_micro_usd']);
        self::assertSame(79, $groups[0]['reported_micro_usd'], 'A pre-3.0 row without cost basis counts as reported.');
        self::assertSame(1, $groups[0]['unknown']);
        self::assertSame(0, $groups[0]['unreported'], 'Pending attempts are not "settled without usage".');
    }
}
