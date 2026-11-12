<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetStatus;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiPriceRepositoryInterface;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;

/** Monthly budget states, reservation and refusal (UX Atlas 01 E). */
final class AiBudgetServiceTest extends TestCase
{
    /** A model AQG does not know: no automatic price. */
    private const UNKNOWN_MODEL = 'claude-unknown-9';

    private InMemoryAiSiteSettingRepository $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new InMemoryAiSiteSettingRepository();
        $this->settings->saveProvider('main', 'anthropic', 1);
    }

    #[Test]
    public function budgetStatesFollowSpentPlusReservations(): void
    {
        self::assertSame(AiBudgetStatus::STATE_NONE, $this->budgetStatus(0)->state);

        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);
        self::assertSame(AiBudgetStatus::STATE_UNDER, $this->budgetStatus(5_950_000)->state);
        self::assertSame(12, $this->budgetStatus(5_950_000)->percent());

        $warning = $this->budgetStatus(39_000_000, 2_200_000);
        self::assertSame(AiBudgetStatus::STATE_WARNING, $warning->state);
        self::assertSame(82, $warning->percent());
        self::assertFalse($warning->isBlocking());

        $reached = $this->budgetStatus(50_000_000);
        self::assertSame(AiBudgetStatus::STATE_REACHED, $reached->state);
        self::assertSame(AiBudgetStatus::BLOCK_REACHED, $reached->blockCode());
    }

    #[Test]
    public function withoutAWarningThresholdThereIsNoWarningState(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 0, false, 1);

        self::assertSame(AiBudgetStatus::STATE_UNDER, $this->budgetStatus(49_000_000)->state);
    }

    #[Test]
    public function warningOnlyBudgetsNeverBlock(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 80, false, 1);

        $reached = $this->budgetStatus(70_000_000);
        self::assertSame(AiBudgetStatus::STATE_REACHED, $reached->state);
        self::assertSame('', $reached->blockCode());
        self::assertSame(140, $reached->percent());
    }

    #[Test]
    public function aBudgetWithoutAPriceIsNotEnforceable(): void
    {
        $this->settings->saveBudget('main', 20_000_000, 80, false, 1);
        self::assertSame(AiBudgetStatus::STATE_NO_PRICE, $this->budgetStatus(0, 0, false)->state);
        self::assertSame('', $this->budgetStatus(0, 0, false)->blockCode(), 'Warning only: cost unknown, AI keeps running.');

        $this->settings->saveBudget('main', 20_000_000, 80, true, 1);
        self::assertSame(AiBudgetStatus::BLOCK_UNENFORCEABLE, $this->budgetStatus(0, 0, false)->blockCode(), 'Without a complete price a hard stop cannot bound the spending.');
    }

    #[Test]
    public function aPre30ManualPriceNeverEnforcesAHardStop(): void
    {
        $legacy = ['provider' => 'anthropic', 'model' => self::UNKNOWN_MODEL, 'input_micro_usd' => 2_000_000, 'output_micro_usd' => 10_000_000];
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);
        $service = $this->service($this->createMock(AiBudgetRepositoryInterface::class), $legacy);

        self::assertSame(AiBudgetStatus::BLOCK_UNENFORCEABLE, $service->status('main', 'anthropic', self::UNKNOWN_MODEL)->blockCode());
        try {
            $service->reserve('main', 'alt_text', $this->configuration(self::UNKNOWN_MODEL), new AiCostBound(10, 10));
            self::fail('An incomplete manual price cannot bound a hard-stop request.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(AiBudgetService::CODE_BUDGET_UNENFORCEABLE, $exception->getCode());
        }

        $this->settings->saveBudget('main', 50_000_000, 80, false, 1);
        $reservation = $service->reserve('main', 'alt_text', $this->configuration(self::UNKNOWN_MODEL), new AiCostBound(1_000, 100));
        self::assertSame(0, $reservation->uid, 'Warning only: nothing to reserve.');
        self::assertSame(2_500 + 1_000 + 3, $reservation->estimateMicroUsd, 'Still estimated, with the 1.25× write bound (+3 µUSD rounding slack).');
    }

    #[Test]
    public function theReservationUsesTheLongContextTierItsInputCanReach(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 0, true, 1);
        $repository = $this->createMock(AiBudgetRepositoryInterface::class);
        // Claude Haiku 5.5 above 100,000 prompt tokens: $0.625 write (worst input) and $2.50 output per 1M.
        $repository->expects(self::once())->method('reserve')
            ->with('main', self::anything(), self::anything(), self::anything(), 50_000_000, 150_000 * 625_000 / 1_000_000 + 1_000 * 2_500_000 / 1_000_000 + 3)
            ->willReturn(5);

        $this->service($repository)->reserve('main', 'alt_text', $this->configuration('claude-haiku-5-5'), new AiCostBound(150_000, 1_000));
    }

    #[Test]
    public function aHardStopReservesTheWorstCaseUnderTheLock(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);
        $repository = $this->createMock(AiBudgetRepositoryInterface::class);
        // 10,000 input at the worst input rate ($2.50 cache write) + 1,000 output × $10/1M = 25,000 + 10,000 micro-USD,
        // plus 3 µUSD so a settlement rounded per category never exceeds it.
        $repository->expects(self::once())->method('reserve')
            ->with('main', 202610, strtotime('2026-10-01 00:00:00'), strtotime('2026-11-01 00:00:00'), 50_000_000, 35_003)
            ->willReturn(77);

        $reservation = $this->service($repository)->reserve('main', 'alt_text', $this->configuration(), new AiCostBound(10_000, 1_000), new \DateTimeImmutable('2026-10-27 15:40:00'));

        self::assertSame(77, $reservation->uid);
        self::assertSame(35_003, $reservation->estimateMicroUsd);
        self::assertNotNull($reservation->price);
    }

    #[Test]
    public function aRequestThatDoesNotFitIsRefusedBeforeItIsSent(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);
        $repository = $this->createMock(AiBudgetRepositoryInterface::class);
        $repository->method('reserve')->willReturn(null);

        $this->expectException(AiConfigurationException::class);
        $this->expectExceptionCode(AiBudgetService::CODE_BUDGET_REACHED);
        $this->service($repository)->reserve('main', 'alt_text', $this->configuration(), new AiCostBound(10, 10));
    }

    #[Test]
    public function aHardStopWithoutPriceIsRefusedAndAFailingCheckFailsClosed(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);
        try {
            $this->service($this->createMock(AiBudgetRepositoryInterface::class))->reserve('main', 'alt_text', $this->configuration(self::UNKNOWN_MODEL), new AiCostBound(10, 10));
            self::fail('No price, hard stop: refused.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(AiBudgetService::CODE_BUDGET_UNENFORCEABLE, $exception->getCode());
        }

        $repository = $this->createMock(AiBudgetRepositoryInterface::class);
        $repository->method('reserve')->willThrowException(new \RuntimeException('lock wait timeout'));
        $this->expectExceptionCode(AiBudgetService::CODE_BUDGET_UNENFORCEABLE);
        $this->service($repository)->reserve('main', 'alt_text', $this->configuration(), new AiCostBound(10, 10));
    }

    #[Test]
    public function connectionTestsAndWarningOnlyBudgetsReserveNothing(): void
    {
        $this->settings->saveBudget('main', 50_000_000, 80, true, 1);
        $repository = $this->createMock(AiBudgetRepositoryInterface::class);
        $repository->expects(self::never())->method('reserve');
        $service = $this->service($repository);

        $test = $service->reserve('main', 'connection_test', $this->configuration(), AiCostBound::forConnectionTest('anthropic'));
        self::assertSame(0, $test->uid);
        self::assertNotNull($test->price, 'The connection test is still priced and recorded.');

        $this->settings->saveBudget('main', 50_000_000, 80, false, 1);
        self::assertSame(0, $service->reserve('main', 'alt_text', $this->configuration(), new AiCostBound(10, 10))->uid);
        $this->settings->saveBudget('main', 0, 0, false, 1);
        self::assertSame(0, $service->reserve('main', 'alt_text', $this->configuration(), new AiCostBound(10, 10))->uid);
    }

    #[Test]
    public function releasingRemovesOnlyARealReservation(): void
    {
        $repository = $this->createMock(AiBudgetRepositoryInterface::class);
        $repository->expects(self::once())->method('release')->with(77);
        $service = $this->service($repository);

        $service->release(new \Priebera\A11yQualityGate\Ai\Usage\AiBudgetReservation(0, null, new AiCostBound(1, 1), 0));
        $service->release(new \Priebera\A11yQualityGate\Ai\Usage\AiBudgetReservation(77, null, new AiCostBound(1, 1), 0));
    }

    #[Test]
    public function thePeriodIsTheCalendarMonthInServerTime(): void
    {
        self::assertSame(
            [202612, strtotime('2026-12-01 00:00:00'), strtotime('2027-01-01 00:00:00')],
            AiBudgetService::period(new \DateTimeImmutable('2026-12-31 23:59:59')),
        );
    }

    private function budgetStatus(int $spent, int $reserved = 0, bool $priced = true): AiBudgetStatus
    {
        $repository = $this->createMock(AiBudgetRepositoryInterface::class);
        $repository->method('spentMicroUsd')->willReturn($spent);
        $repository->method('reservedMicroUsd')->willReturn($reserved);
        $repository->method('accounting')->willReturn(['spent' => $spent, 'reserved' => $reserved]);

        return $this->service($repository)->status('main', 'anthropic', $priced ? 'claude-sonnet-5-5' : self::UNKNOWN_MODEL);
    }

    /** @param array<string,mixed>|null $legacyPrice a pre-3.0 manual price row */
    private function service(AiBudgetRepositoryInterface $repository, ?array $legacyPrice = null): AiBudgetService
    {
        $prices = $this->createMock(AiPriceRepositoryInterface::class);
        $prices->method('find')->willReturn($legacyPrice);

        return new AiBudgetService(
            $repository,
            new AiPriceTable($prices, new AiModelCompatibilityRegistry()),
            new AiSiteSettingsService($this->settings, $this->createMock(AiConfigurationRepositoryInterface::class)),
        );
    }

    private function configuration(string $model = 'claude-sonnet-5-5'): AiProviderConfiguration
    {
        return new AiProviderConfiguration('anthropic', 'sk-ant-secret', $model, 'site', 'fp');
    }
}
