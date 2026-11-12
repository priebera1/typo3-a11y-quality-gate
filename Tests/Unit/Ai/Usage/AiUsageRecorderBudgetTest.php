<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetReservation;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceRepositoryInterface;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Pricing\InMemoryAiPriceCatalogueRepository;

/**
 * AiUsageRecorder::track() is the choke point of every provider request: budget enforcement before the request,
 * reservation settlement and the estimated-cost snapshot after it.
 */
final class AiUsageRecorderBudgetTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];
    /** @var list<string> */
    private array $events = [];
    /** @var list<int> */
    private array $reservedEstimates = [];
    private InMemoryAiSiteSettingRepository $settings;
    private AiUsageMeter $meter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new InMemoryAiSiteSettingRepository();
        $this->settings->saveProvider('main', 'anthropic', 1);
        $this->meter = new AiUsageMeter();
    }

    #[Test]
    public function aRefusedRequestIsNeverSentNorRecorded(): void
    {
        $this->settings->saveBudget('main', 1_000_000, 0, true, 1);
        $recorder = $this->recorder(reserveResult: null);
        $sent = false;

        try {
            $recorder->track('main', 'explain', $this->configuration(), static function () use (&$sent): string {
                $sent = true;

                return 'answer';
            }, new AiCostBound(1_000, 100));
            self::fail('The budget must refuse the request.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(AiBudgetService::CODE_BUDGET_REACHED, $exception->getCode());
        }

        self::assertFalse($sent);
        self::assertSame([], $this->rows);
    }

    #[Test]
    public function theLastRecordSaysWhetherAClickReachedTheProviderAndWhatWasCounted(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);
        $recorder = $this->recorder(reserveResult: 9);
        self::assertNull($recorder->lastRecord(), 'Nothing sent yet.');

        $recorder->track('main', 'alt_text', $this->configuration(), function (): string {
            $this->meter->report(new AiUsage(1_000, 50, true));

            return 'Bridge';
        }, new AiCostBound(20_000, 2_228));
        self::assertSame(['outcome' => 'success', 'costMicro' => 2_500, 'costKnown' => true, 'basis' => 'reported'], $recorder->lastRecord());

        $recorder->forgetLastRecord();
        self::assertNull($recorder->lastRecord());
        try {
            $recorder->track('main', 'alt_text', $this->configuration(), static function (): string {
                throw new AiProviderException('timeout', 1771002302);
            }, new AiCostBound(20_000, 2_228));
            self::fail('The provider failure must reach the caller.');
        } catch (AiProviderException) {
        }
        // A failed request without reported usage is counted at its bound, and is marked as failed.
        self::assertSame('failed', $recorder->lastRecord()['outcome'] ?? null);
        self::assertSame('bound', $recorder->lastRecord()['basis'] ?? null);
        self::assertGreaterThan(0, $recorder->lastRecord()['costMicro'] ?? 0);
    }

    #[Test]
    public function aSettledRequestRecordsTheReportedCostAndThenReleasesItsReservation(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);
        $recorder = $this->recorder(reserveResult: 9);

        $result = $recorder->track('main', 'alt_text', $this->configuration(), function (): string {
            $this->meter->report(new AiUsage(1_000, 50, true));

            return 'Bridge';
        }, new AiCostBound(20_000, 2_228));

        self::assertSame('Bridge', $result);
        self::assertCount(1, $this->rows);
        // 1,000 × $2/1M + 50 × $10/1M = 2,000 + 500 micro-USD.
        self::assertSame(2_500, $this->rows[0]['estimated_cost_micro_usd']);
        self::assertSame(1, $this->rows[0]['cost_known']);
        self::assertSame('reported', $this->rows[0]['cost_basis']);
        self::assertSame(['reserve', 'add', 'release:9'], $this->events, 'Released only after the usage row exists.');
    }

    #[Test]
    public function aRequestWithoutReportedUsageIsChargedItsWorstCase(): void
    {
        $recorder = $this->recorder(reserveResult: 3);
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);

        try {
            $recorder->track('main', 'alt_text', $this->configuration(), static function (): void {
                throw new AiProviderException('timeout', 1771002302);
            }, new AiCostBound(20_000, 2_228));
        } catch (AiProviderException) {
        }

        // Worst case: 20,000 input at the $2.50 cache-write rate + 2,228 output × $10/1M.
        self::assertSame(50_000 + 22_280 + 3, $this->rows[0]['estimated_cost_micro_usd']);
        self::assertSame('bound', $this->rows[0]['cost_basis']);
        self::assertSame('failed_1771002302', $this->rows[0]['outcome']);
    }

    #[Test]
    public function aDefinitiveRejectionBeforeProcessingCostsNothing(): void
    {
        $recorder = $this->recorder(reserveResult: 3);

        foreach (AiUsageRecorder::PRE_PROCESSING_REJECTIONS as $code) {
            try {
                $recorder->track('main', 'alt_text', $this->configuration(), static function () use ($code): void {
                    throw new AiProviderException('rejected', $code);
                }, new AiCostBound(20_000, 2_228));
            } catch (AiProviderException) {
            }
        }

        self::assertSame([0], array_values(array_unique(array_column($this->rows, 'estimated_cost_micro_usd'))));
        self::assertSame(['none'], array_values(array_unique(array_column($this->rows, 'cost_basis'))));
    }

    #[Test]
    public function withoutAPriceTheCostIsUnknownNotZero(): void
    {
        $recorder = $this->recorder(reserveResult: 3);

        $recorder->track('main', 'alt_text', $this->configuration('claude-unknown-9'), function (): string {
            $this->meter->report(new AiUsage(1_000, 50, true));

            return 'ok';
        });

        self::assertSame(0, $this->rows[0]['estimated_cost_micro_usd']);
        self::assertSame(0, $this->rows[0]['cost_known']);
        self::assertSame('unknown', $this->rows[0]['cost_basis']);
        self::assertSame(1_000, $this->rows[0]['input_tokens']);
        self::assertSame('', $this->rows[0]['price_origin']);
    }

    #[Test]
    public function cachedAndCacheWrittenTokensAreSettledAtTheirOwnRates(): void
    {
        $recorder = $this->recorder(reserveResult: 3);
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);

        $recorder->track('main', 'alt_text', $this->configuration(), function (): string {
            $this->meter->report(new AiUsage(1_000, 50, true, 4_000, 2_000));

            return 'ok';
        }, new AiCostBound(10_000, 500));

        // Claude Sonnet 5.5: 1,000 × $2 + 4,000 × $0.10 (cache hits) + 2,000 × $2.50 (5-minute writes) + 50 × $10.
        self::assertSame(2_000 + 400 + 5_000 + 500, $this->rows[0]['estimated_cost_micro_usd']);
        self::assertSame(1_000, $this->rows[0]['input_tokens'], 'Ordinary input only.');
        self::assertSame(4_000, $this->rows[0]['cache_read_tokens']);
        self::assertSame(2_000, $this->rows[0]['cache_write_tokens']);
        self::assertSame('bundled', $this->rows[0]['price_origin']);
        self::assertGreaterThan(0, $this->rows[0]['price_verified_at']);
        self::assertSame(['reserve', 'add', 'release:3'], $this->events);
    }

    #[Test]
    public function connectionTestsAreRecordedButNeverBlocked(): void
    {
        $this->settings->saveBudget('main', 1_000_000, 0, true, 1);
        $recorder = $this->recorder(reserveResult: null);

        $recorder->track('main', 'connection_test', $this->configuration(), function (): string {
            $this->meter->report(new AiUsage(300, 20, true));

            return 'ok';
        });

        self::assertNotContains('reserve', $this->events);
        self::assertSame('connection_test', $this->rows[0]['feature']);
        self::assertSame(600 + 200, $this->rows[0]['estimated_cost_micro_usd']);
    }

    #[Test]
    public function aPriceEditAfterTheRequestStartedDoesNotChangeItsCost(): void
    {
        // The reservation keeps the price valid when the request started; a sync meanwhile never reprices it.
        $reservation = new AiBudgetReservation(0, AiTestPrices::simple('anthropic', 'claude-sonnet-5-5', 2_000_000, 10_000_000), new AiCostBound(1, 1), 0);

        self::assertSame([2_500, true, 'reported'], AiUsageRecorder::cost(new AiUsage(1_000, 50, true), $reservation, null));
    }

    #[Test]
    public function aCatalogueRefreshDuringARequestNeitherRepricesItNorWeakensTheNextReservation(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);
        $catalogue = new InMemoryAiPriceCatalogueRepository();
        $setPrice = static function (int $input, int $output, int $verifiedAt) use ($catalogue): void {
            $catalogue->prices["anthropic\0claude-sonnet-5-5"] = [
                'provider' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'label' => 'claude-sonnet-5-5', 'listed' => 1,
                'rates' => (string)json_encode(['standard' => ['input' => $input, 'output' => $output, 'cached_input' => intdiv($input, 10), 'cache_write' => $input * 5 / 4, 'cache_write_extended' => $input * 2], 'long_context' => null]),
                'source_url' => 'https://platform.claude.com/docs/en/about-claude/pricing.md', 'verified_at' => $verifiedAt,
            ];
        };
        $setPrice(2_000_000, 10_000_000, time() - 60);
        $table = new AiPriceTable($this->createMock(AiPriceRepositoryInterface::class), new AiModelCompatibilityRegistry(), $catalogue);
        $recorder = $this->recorder(reserveResult: 9, table: $table);
        $bound = new AiCostBound(20_000, 2_000);

        $recorder->track('main', 'alt_text', $this->configuration(), function () use ($setPrice, $table): string {
            // The central catalogue raises the price while the provider is answering.
            $setPrice(4_000_000, 20_000_000, time());
            $table->flush();
            $this->meter->report(new AiUsage(1_000, 50, true));

            return 'Bridge';
        }, $bound);

        self::assertSame(2_500, $this->rows[0]['estimated_cost_micro_usd'], 'Settled at the price the reservation was made with.');
        self::assertSame('central', $this->rows[0]['price_origin']);

        $recorder->track('main', 'alt_text', $this->configuration(), function (): string {
            $this->meter->report(new AiUsage(1_000, 50, true));

            return 'Bridge';
        }, $bound);

        self::assertSame(5_000, $this->rows[1]['estimated_cost_micro_usd'], 'The next request uses the new price.');
        self::assertCount(2, $this->reservedEstimates);
        self::assertGreaterThan($this->reservedEstimates[0], $this->reservedEstimates[1], 'The next reservation covers the higher price, never the older lower one.');
        self::assertGreaterThanOrEqual(20_000 * 5_000_000 / 1_000_000 + 2_000 * 20, $this->reservedEstimates[1]);
    }

    private function recorder(?int $reserveResult, ?AiPriceTable $table = null): AiUsageRecorder
    {
        $usage = new AiUsageRepositoryDouble(function (array $row): void {
            $this->rows[] = $row;
            $this->events[] = 'add';
        });
        $budget = $this->createMock(AiBudgetRepositoryInterface::class);
        $budget->method('reserve')->willReturnCallback(function (string $site, int $period, int $from, int $until, int $budget, int $estimate) use ($reserveResult): ?int {
            $this->events[] = 'reserve';
            $this->reservedEstimates[] = $estimate;

            return $reserveResult;
        });
        $budget->method('release')->willReturnCallback(function (int $uid): void {
            $this->events[] = 'release:' . $uid;
        });
        $prices = $this->createMock(AiPriceRepositoryInterface::class);
        $prices->method('find')->willReturn(null);
        $users = $this->createMock(BackendUserServiceInterface::class);
        $users->method('getBackendUserUid')->willReturn(5);

        return new AiUsageRecorder($usage, $this->meter, $users, new AiBudgetService(
            $budget,
            $table ?? new AiPriceTable($prices, new AiModelCompatibilityRegistry()),
            new AiSiteSettingsService($this->settings, $this->createMock(AiConfigurationRepositoryInterface::class)),
        ));
    }

    private function configuration(string $model = 'claude-sonnet-5-5'): AiProviderConfiguration
    {
        return new AiProviderConfiguration('anthropic', 'sk-ant-secret', $model, 'site', 'fp');
    }
}
