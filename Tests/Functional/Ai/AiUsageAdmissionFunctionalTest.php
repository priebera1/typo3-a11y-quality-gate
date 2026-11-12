<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\AiBudgetRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiSiteSettingRepository;
use Priebera\A11yQualityGate\Domain\Repository\AiUsageRepository;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;

/**
 * PI-03 with the real database: an AI attempt is admitted as a pending usage row before its provider request, settled
 * afterwards, and keeps counting (requests and worst-case cost) when the settlement write fails. The monthly hard-stop
 * reservation is kept in that case and never counted twice; an attempt that cannot be admitted is never sent.
 */
final class AiUsageAdmissionFunctionalTest extends AbstractFunctionalTestCase
{
    private const REF = 'aaaaaaaaaaaaaaaaaaaaaaaa';
    private bool $failAdmission = false;
    private bool $failSettlement = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', ['uid' => 3, 'username' => 'eva', 'admin' => 0, 'disable' => 0, 'deleted' => 0]);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.test/');
        $this->setUpBackendUser(3);
    }

    private function recorder(): AiUsageRecorder
    {
        $real = $this->get(AiUsageRepository::class);
        $test = $this;
        // The real repository; only the switched write fails (reads keep working), like a transient INSERT/UPDATE failure.
        $repository = new class ($real, $test) implements AiUsageRepositoryInterface {
            public function __construct(private readonly AiUsageRepository $real, private readonly AiUsageAdmissionFunctionalTest $test) {}
            public function add(array $row): void
            {
                $this->real->add($row);
            }
            public function admit(array $row): int
            {
                $this->test->failWrite('admission');

                return $this->real->admit($row);
            }
            public function settle(int $uid, array $fields): bool
            {
                $this->test->failWrite('settlement');

                return $this->real->settle($uid, $fields);
            }
            public function withdraw(int $uid): void
            {
                $this->real->withdraw($uid);
            }
            public function summarize(string $siteIdentifier, int $from, int $until): array
            {
                return $this->real->summarize($siteIdentifier, $from, $until);
            }
            public function breakdown(string $siteIdentifier, int $from, int $until): array
            {
                return $this->real->breakdown($siteIdentifier, $from, $until);
            }
            public function summarizeConversation(string $siteIdentifier, int $beUser, string $conversationRef, int $since): array
            {
                return $this->real->summarizeConversation($siteIdentifier, $beUser, $conversationRef, $since);
            }
            public function countUserFeatureRequests(string $siteIdentifier, int $beUser, string $feature, int $since): int
            {
                return $this->real->countUserFeatureRequests($siteIdentifier, $beUser, $feature, $since);
            }
        };

        return new AiUsageRecorder($repository, $this->get(AiUsageMeter::class), $this->get(BackendUserServiceInterface::class), $this->get(AiBudgetService::class));
    }

    /** Called by the repository double: throws for the write that is switched to fail. */
    public function failWrite(string $write): void
    {
        if (($write === 'admission' && $this->failAdmission) || ($write === 'settlement' && $this->failSettlement)) {
            throw new \RuntimeException('QA TEST ' . $write . ' write failure');
        }
    }

    private function configuration(): AiProviderConfiguration
    {
        return new AiProviderConfiguration('anthropic', 'sk-ant-test', 'claude-sonnet-5-5', 'site', 'fp');
    }

    private function hardStopBudget(): void
    {
        $settings = $this->get(AiSiteSettingRepository::class);
        $settings->saveProvider('main', 'anthropic', 1);
        $settings->saveBudget('main', 50_000, 0, true, 1);
    }

    /** @return list<array<string, mixed>> */
    private function usageRows(): array
    {
        return $this->getConnectionPool()->getConnectionForTable(Tables::AI_USAGE)->select(['*'], Tables::AI_USAGE, ['site_identifier' => 'main'])->fetchAllAssociative();
    }

    private function answer(?\Throwable $failure = null): \Closure
    {
        $meter = $this->get(AiUsageMeter::class);

        return static function () use ($meter, $failure): string {
            if ($failure !== null) {
                throw $failure;
            }
            $meter->report(new AiUsage(1_000, 100, true));

            return 'ok';
        };
    }

    #[Test]
    public function aSettledAttemptCarriesItsActualCostOnceAndReleasesItsReservation(): void
    {
        $this->hardStopBudget();
        self::assertSame('ok', $this->recorder()->track('main', 'assistant', $this->configuration(), $this->answer(), new AiCostBound(1_100, 2_148), self::REF));

        $rows = $this->usageRows();
        self::assertCount(1, $rows);
        self::assertSame(['success', 'reported', 0], [$rows[0]['outcome'], $rows[0]['cost_basis'], (int)$rows[0]['reserved_cost_micro_usd']]);
        self::assertSame(2_000 + 1_000, (int)$rows[0]['estimated_cost_micro_usd']);
        self::assertSame(0, $this->countRows(AiBudgetRepository::RESERVATION_TABLE));
        [, $from, $until] = AiBudgetService::period();
        self::assertSame(3_000, $this->get(AiBudgetRepository::class)->spentMicroUsd('main', $from, $until));
        $conversation = $this->get(AiUsageRepository::class)->summarizeConversation('main', 3, self::REF, 0);
        self::assertSame([1, 3_000], [$conversation['requests'], $conversation['cost_micro_usd']]);
    }

    #[Test]
    public function aFailedSettlementKeepsTheAttemptCountedAtItsWorstCaseWithoutCountingItTwice(): void
    {
        $this->hardStopBudget();
        $this->failSettlement = true;
        self::assertSame('ok', $this->recorder()->track('main', 'assistant', $this->configuration(), $this->answer(), new AiCostBound(1_100, 2_148), self::REF), 'the answer is still returned');

        $rows = $this->usageRows();
        self::assertCount(1, $rows, 'the only record of the sent request is kept');
        self::assertSame('pending', $rows[0]['outcome']);
        $worstCase = (int)$rows[0]['reserved_cost_micro_usd'];
        self::assertGreaterThan(0, $worstCase);
        self::assertGreaterThan(0, (int)$rows[0]['budget_reservation']);
        self::assertSame(1, $this->countRows(AiBudgetRepository::RESERVATION_TABLE), 'the monthly reservation stays');

        [$period, $from, $until] = AiBudgetService::period();
        $budget = $this->get(AiBudgetRepository::class);
        self::assertSame($worstCase, $budget->spentMicroUsd('main', $from, $until) + $budget->reservedMicroUsd('main', $period), 'counted once: the reservation, not the pending row again');
        $usage = $this->get(AiUsageRepository::class);
        self::assertSame(1, $usage->countUserFeatureRequests('main', 3, 'assistant', 0), 'the daily cap counts it');
        self::assertSame([1, $worstCase], array_values(array_intersect_key($usage->summarizeConversation('main', 3, self::REF, 0), ['requests' => 0, 'cost_micro_usd' => 0])));
        $report = $usage->summarize('main', $from, $until);
        self::assertSame([1, 1, $worstCase], [$report[0]['requests'], $report[0]['failed'], $report[0]['cost_micro_usd']], 'reported as an unsettled request at its worst case');
    }

    #[Test]
    public function aProviderFailureIsSettledAndAFailedSettlementAfterATimeoutStaysCounted(): void
    {
        try {
            $this->recorder()->track('main', 'assistant', $this->configuration(), $this->answer(new AiProviderException('timeout', 1771002302)), null, self::REF);
            self::fail('expected the provider failure');
        } catch (AiProviderException) {
        }
        $this->failSettlement = true;
        try {
            $this->recorder()->track('main', 'assistant', $this->configuration(), $this->answer(new AiProviderException('timeout', 1771002302)), null, self::REF);
            self::fail('expected the provider failure');
        } catch (AiProviderException) {
        }

        self::assertSame(['failed_1771002302', 'pending'], array_column($this->usageRows(), 'outcome'));
        self::assertSame(2, $this->get(AiUsageRepository::class)->countUserFeatureRequests('main', 3, 'assistant', 0));
    }

    #[Test]
    public function anAttemptThatCannotBeAdmittedIsNeverSentAndReleasesItsReservation(): void
    {
        $this->hardStopBudget();
        $this->failAdmission = true;
        $sent = false;
        try {
            $this->recorder()->track('main', 'assistant', $this->configuration(), static function () use (&$sent): string {
                $sent = true;

                return 'ok';
            }, new AiCostBound(1_100, 2_148), self::REF);
            self::fail('expected a refusal');
        } catch (AiConfigurationException $exception) {
            self::assertSame(AiUsageRecorder::CODE_USAGE_UNAVAILABLE, $exception->getCode());
        }
        self::assertFalse($sent);
        self::assertSame([], $this->usageRows());
        self::assertSame(0, $this->countRows(AiBudgetRepository::RESERVATION_TABLE));

        // Recovery: once the write works again the next attempt is admitted and settled normally.
        $this->failAdmission = false;
        self::assertSame('ok', $this->recorder()->track('main', 'assistant', $this->configuration(), $this->answer(), new AiCostBound(1_100, 2_148), self::REF));
        self::assertSame(['success'], array_column($this->usageRows(), 'outcome'));
    }

    #[Test]
    public function aRefusalAfterAdmissionWithdrawsTheUnsentAttempt(): void
    {
        $this->hardStopBudget();
        $sent = false;
        try {
            $this->recorder()->track('main', 'assistant', $this->configuration(), static function () use (&$sent): string {
                $sent = true;

                return 'ok';
            }, new AiCostBound(1_100, 2_148), self::REF, function (): void {
                self::assertSame(1, $this->get(AiUsageRepository::class)->countUserFeatureRequests('main', 3, 'assistant', 0), 'the check sees the admitted row');
                throw new EditorAiException('daily_limit', 'limit', 429);
            });
            self::fail('expected the refusal');
        } catch (EditorAiException $exception) {
            self::assertSame('daily_limit', $exception->safeCode);
        }
        self::assertFalse($sent);
        self::assertSame([], $this->usageRows());
        self::assertSame(0, $this->countRows(AiBudgetRepository::RESERVATION_TABLE));
    }

    #[Test]
    public function withoutAHardStopAPendingAttemptCountsItsWorstCaseInTheMonthlySpend(): void
    {
        $this->failSettlement = true;
        $this->get(AiSiteSettingRepository::class)->saveProvider('main', 'anthropic', 1);
        $this->recorder()->track('main', 'assistant', $this->configuration(), $this->answer(), new AiCostBound(1_100, 2_148), self::REF);

        $row = $this->usageRows()[0];
        self::assertSame([0, 'pending'], [(int)$row['budget_reservation'], $row['outcome']]);
        [, $from, $until] = AiBudgetService::period();
        self::assertSame((int)$row['reserved_cost_micro_usd'], $this->get(AiBudgetRepository::class)->spentMicroUsd('main', $from, $until));
    }
}
