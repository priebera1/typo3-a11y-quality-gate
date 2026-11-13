<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertNotifier;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertRecipient;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetAlertService;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetAlertRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiBudgetRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiBudgetAlertRepository;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;

/**
 * F-39 budget warning e-mail: threshold crossing from recorded usage, one alert per site/month/threshold/budget,
 * insert-first claim, bounded retries, recipient resolution and isolation from the AI request.
 */
final class AiBudgetAlertServiceTest extends TestCase
{
    private const BUDGET = 50_000_000; // $50

    private InMemoryAiSiteSettingRepository $settings;
    private InMemoryAiBudgetAlertRepository $alerts;
    /** @var array<string,int> recorded micro-USD per site */
    private array $spent = [];
    /** @var array<string,int> unknown-cost requests per site */
    private array $unknown = [];
    /** @var list<array{to:string,source:string,alert:array<string,mixed>}> */
    private array $sent = [];
    private int $failingSends = 0;
    private int $sendAttempts = 0;
    private mixed $warningEmailAddress = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new InMemoryAiSiteSettingRepository();
        $this->alerts = new InMemoryAiBudgetAlertRepository();
        $this->warningEmailAddress = $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] ?? null;
        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = '';
    }

    protected function tearDown(): void
    {
        if ($this->warningEmailAddress === null) {
            unset($GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr']);
        } else {
            $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = $this->warningEmailAddress;
        }
        parent::tearDown();
    }

    #[Test]
    public function belowTheThresholdNothingIsClaimedOrSent(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 39_999_999;

        self::assertSame(AiBudgetAlertService::OUTCOME_BELOW, $this->service()->afterUsageRecorded('main', $this->now()));
        self::assertSame([], $this->sent);
        self::assertSame([], $this->alerts->rows);
    }

    #[Test]
    public function crossingTheThresholdSendsExactlyOneAlertPerSiteMonthThresholdAndBudget(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 40_000_000;
        $service = $this->service();

        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main', $this->now()));
        $this->spent['main'] = 45_000_000;
        self::assertSame(AiBudgetAlertService::OUTCOME_ALREADY_SENT, $service->afterUsageRecorded('main', $this->now(60)));
        self::assertSame(AiBudgetAlertService::OUTCOME_ALREADY_SENT, $service->afterUsageRecorded('main', $this->now(86_400)));

        self::assertCount(1, $this->sent);
        self::assertSame('alerts@example.com', $this->sent[0]['to']);
        self::assertSame(AiBudgetAlertRecipient::SOURCE_SITE, $this->sent[0]['source']);
        self::assertSame([
            'siteIdentifier' => 'main',
            'month' => '2026-11',
            'thresholdPercent' => 80,
            'budgetMicroUsd' => self::BUDGET,
            'spentMicroUsd' => 40_000_000,
            'unknownCostRequests' => 0,
            'hardStop' => false,
        ], $this->sent[0]['alert']);
        $row = $this->alerts->row('main', '2026-11', 80, self::BUDGET);
        self::assertSame(AiBudgetAlertRepositoryInterface::STATUS_SENT, $row['status']);
        self::assertSame(1, $row['attempts']);
    }

    #[Test]
    public function noBudgetOrNoWarningThresholdMeansNoAlert(): void
    {
        $this->settings->saveBudgetAlertEmail('main', 'alerts@example.com');
        $this->spent['main'] = 99_000_000;
        self::assertSame(AiBudgetAlertService::OUTCOME_NO_BUDGET, $this->service()->afterUsageRecorded('main', $this->now()));

        $this->configure('main', 0, 'alerts@example.com');
        self::assertSame(AiBudgetAlertService::OUTCOME_NO_THRESHOLD, $this->service()->afterUsageRecorded('main', $this->now()));
        self::assertSame([], $this->sent);
        self::assertSame([], $this->alerts->rows);
    }

    #[Test]
    public function withoutARecipientNothingIsSentOrClaimedAndALaterAddressStillGetsTheAlert(): void
    {
        $this->configure('main', 80, '');
        $this->spent['main'] = 41_000_000;
        $service = $this->service();

        self::assertSame(AiBudgetAlertService::OUTCOME_NO_RECIPIENT, $service->afterUsageRecorded('main', $this->now()));
        self::assertSame([], $this->sent);
        self::assertSame([], $this->alerts->rows);

        $this->settings->saveBudgetAlertEmail('main', 'later@example.com');
        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main', $this->now(60)));
        self::assertSame('later@example.com', $this->sent[0]['to']);
    }

    #[Test]
    public function typo3sWarningAddressIsTheFallbackRecipientOnlyWithoutASiteAddress(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = 'ops@example.org';
        $this->configure('main', 80, '');
        $this->configure('other', 80, 'other@example.com');
        $this->spent = ['main' => 40_000_000, 'other' => 40_000_000];
        $service = $this->service();

        $service->afterUsageRecorded('main', $this->now());
        $service->afterUsageRecorded('other', $this->now());

        self::assertSame(['ops@example.org', 'other@example.com'], array_column($this->sent, 'to'));
        self::assertSame([AiBudgetAlertRecipient::SOURCE_TYPO3, AiBudgetAlertRecipient::SOURCE_SITE], array_column($this->sent, 'source'));
    }

    #[Test]
    public function anInvalidTypo3WarningAddressIsNoRecipient(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr'] = 'ops@example.org, boss@example.org';
        $this->configure('main', 80, '');
        $this->spent['main'] = 40_000_000;

        self::assertSame(AiBudgetAlertService::OUTCOME_NO_RECIPIENT, $this->service()->afterUsageRecorded('main', $this->now()));
    }

    #[Test]
    public function oneSitesCrossingNeverAlertsAnotherSite(): void
    {
        $this->configure('a', 80, 'a@example.com');
        $this->configure('b', 80, 'b@example.com');
        $this->spent = ['a' => 45_000_000, 'b' => 10_000_000];
        $service = $this->service();

        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('a', $this->now()));
        self::assertSame(AiBudgetAlertService::OUTCOME_BELOW, $service->afterUsageRecorded('b', $this->now()));
        self::assertSame(['a@example.com'], array_column($this->sent, 'to'));

        // Site B's own crossing is its own alert, to its own recipient; site A's claim does not cover it.
        $this->spent['b'] = 40_000_000;
        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('b', $this->now(60)));
        self::assertSame(['a@example.com', 'b@example.com'], array_column($this->sent, 'to'));
        self::assertSame('b', $this->sent[1]['alert']['siteIdentifier']);
    }

    #[Test]
    public function aChangedThresholdOrBudgetIsANewAlertAndGoingBackSendsNothingAgain(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 46_000_000; // 92 %
        $service = $this->service();
        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main', $this->now()));

        // Raised threshold not reached yet: nothing.
        $this->configure('main', 95, 'alerts@example.com');
        self::assertSame(AiBudgetAlertService::OUTCOME_BELOW, $service->afterUsageRecorded('main', $this->now(60)));
        // Lowered threshold already exceeded: exactly one alert for it.
        $this->configure('main', 90, 'alerts@example.com');
        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main', $this->now(120)));
        self::assertSame(AiBudgetAlertService::OUTCOME_ALREADY_SENT, $service->afterUsageRecorded('main', $this->now(180)));
        // Back to 80 %: that alert was already sent this month.
        $this->configure('main', 80, 'alerts@example.com');
        self::assertSame(AiBudgetAlertService::OUTCOME_ALREADY_SENT, $service->afterUsageRecorded('main', $this->now(240)));
        // A smaller budget with the same threshold: a new key.
        $this->settings->saveBudget('main', 30_000_000, 80, false, 1);
        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main', $this->now(300)));

        self::assertSame([80, 90, 80], array_map(static fn (array $sent): int => $sent['alert']['thresholdPercent'], $this->sent));
        self::assertSame([self::BUDGET, self::BUDGET, 30_000_000], array_map(static fn (array $sent): int => $sent['alert']['budgetMicroUsd'], $this->sent));
    }

    #[Test]
    public function aNewMonthIsANewAlert(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 40_000_000;
        $service = $this->service();

        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main', new \DateTimeImmutable('2026-11-30 23:59:00')));
        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main', new \DateTimeImmutable('2026-12-01 00:01:00')));
        self::assertSame(['2026-11', '2026-12'], array_map(static fn (array $sent): string => $sent['alert']['month'], $this->sent));
    }

    #[Test]
    public function aFailedSendIsRetriedAfterTheBackoffAndThenNeverSentAgain(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 40_000_000;
        $this->failingSends = 1;
        $service = $this->service();

        self::assertSame(AiBudgetAlertService::OUTCOME_FAILED, $service->afterUsageRecorded('main', $this->now()));
        $row = $this->alerts->row('main', '2026-11', 80, self::BUDGET);
        self::assertSame(AiBudgetAlertRepositoryInterface::STATUS_FAILED, $row['status']);
        self::assertSame(1, $row['attempts']);

        self::assertSame(AiBudgetAlertService::OUTCOME_RETRY_LATER, $service->afterUsageRecorded('main', $this->now(AiBudgetAlertService::retryDelay(1) - 1)));
        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main', $this->now(AiBudgetAlertService::retryDelay(1))));
        self::assertSame(AiBudgetAlertService::OUTCOME_ALREADY_SENT, $service->afterUsageRecorded('main', $this->now(86_400)));

        self::assertSame(2, $this->sendAttempts);
        self::assertCount(1, $this->sent);
        $row = $this->alerts->row('main', '2026-11', 80, self::BUDGET);
        self::assertSame(AiBudgetAlertRepositoryInterface::STATUS_SENT, $row['status']);
        self::assertSame(2, $row['attempts']);
    }

    #[Test]
    public function retriesStopAfterTheAttemptLimit(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 40_000_000;
        $this->failingSends = PHP_INT_MAX;
        $service = $this->service();

        $offset = 0;
        for ($attempt = 1; $attempt <= AiBudgetAlertService::MAX_ATTEMPTS; $attempt++) {
            self::assertSame(AiBudgetAlertService::OUTCOME_FAILED, $service->afterUsageRecorded('main', $this->now($offset)), 'attempt ' . $attempt);
            $offset += AiBudgetAlertService::retryDelay($attempt);
        }
        self::assertSame(AiBudgetAlertService::OUTCOME_ATTEMPTS_EXHAUSTED, $service->afterUsageRecorded('main', $this->now($offset)));
        self::assertSame(AiBudgetAlertService::OUTCOME_ATTEMPTS_EXHAUSTED, $service->afterUsageRecorded('main', $this->now($offset + 86_400)));

        self::assertSame(AiBudgetAlertService::MAX_ATTEMPTS, $this->sendAttempts);
        self::assertSame([], $this->sent);
        self::assertSame([300, 600, 1_200, 2_400], array_map(AiBudgetAlertService::retryDelay(...), [1, 2, 3, 4]));
    }

    #[Test]
    public function aConcurrentSettlementLosesTheClaimAndSendsNothing(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 40_000_000;
        // The first settlement has claimed the alert and is still delivering it (pending).
        self::assertIsInt($this->alerts->claim('main', '2026-11', 80, self::BUDGET, $this->now()->getTimestamp()));
        self::assertNull($this->alerts->claim('main', '2026-11', 80, self::BUDGET, $this->now()->getTimestamp()), 'The unique key lets only one claim win.');

        self::assertSame(AiBudgetAlertService::OUTCOME_CLAIMED_ELSEWHERE, $this->service()->afterUsageRecorded('main', $this->now(5)));
        self::assertSame([], $this->sent);

        // Two retries that read the same failed row: only one reclaim succeeds.
        $uid = $this->alerts->row('main', '2026-11', 80, self::BUDGET)['uid'];
        $this->alerts->markFailed($uid, $this->now()->getTimestamp());
        $read = $this->alerts->find('main', '2026-11', 80, self::BUDGET);
        self::assertTrue($this->alerts->reclaim($uid, $read['status'], $read['attempts'], $read['last_attempt_at'], $this->now(400)->getTimestamp()));
        self::assertFalse($this->alerts->reclaim($uid, $read['status'], $read['attempts'], $read['last_attempt_at'], $this->now(400)->getTimestamp()));
    }

    #[Test]
    public function aPendingClaimWhoseProcessDiedIsTakenOverAfterItGoesStale(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 40_000_000;
        $this->alerts->claim('main', '2026-11', 80, self::BUDGET, $this->now()->getTimestamp());
        $service = $this->service();

        self::assertSame(AiBudgetAlertService::OUTCOME_CLAIMED_ELSEWHERE, $service->afterUsageRecorded('main', $this->now(AiBudgetAlertService::PENDING_STALE_SECONDS - 1)));
        self::assertSame(AiBudgetAlertService::OUTCOME_SENT, $service->afterUsageRecorded('main', $this->now(AiBudgetAlertService::PENDING_STALE_SECONDS)));
        self::assertCount(1, $this->sent);
    }

    #[Test]
    public function theAlertCountsUnknownCostRequestsInsteadOfTreatingThemAsFree(): void
    {
        $this->configure('main', 80, 'alerts@example.com', hardStop: true);
        $this->spent['main'] = 40_000_000;
        $this->unknown['main'] = 3;

        $this->service()->afterUsageRecorded('main', $this->now());

        self::assertSame(3, $this->sent[0]['alert']['unknownCostRequests']);
        self::assertTrue($this->sent[0]['alert']['hardStop']);
    }

    #[Test]
    public function aBrokenAlertTableIsLoggedAndNeverThrown(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 40_000_000;
        $this->alerts->failClaims = true;

        self::assertSame(AiBudgetAlertService::OUTCOME_ERROR, $this->service()->afterUsageRecorded('main', $this->now()));
        self::assertSame([], $this->sent);
    }

    #[Test]
    public function aMailFailureNeverFailsTheAiRequestOrItsSettlement(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 40_000_000;
        $this->failingSends = PHP_INT_MAX;
        $rows = [];
        $usage = $this->usageRepository();
        $usage->method('admit')->willReturn(1);
        $usage->method('settle')->willReturnCallback(static function (int $uid, array $row) use (&$rows): bool {
            $rows[] = $row;

            return true;
        });
        $recorder = new AiUsageRecorder($usage, new AiUsageMeter(), $this->createMock(BackendUserServiceInterface::class), null, $this->service($usage));

        $result = $recorder->track('main', 'explain', new AiProviderConfiguration('anthropic', 'sk-ant-secret', 'claude-sonnet-5-5', 'site', 'fp'), static fn (): string => 'answer', new AiCostBound(1_000, 100));

        self::assertSame('answer', $result);
        self::assertCount(1, $rows, 'The usage row is recorded although the alert e-mail failed.');
        self::assertSame(1, $this->sendAttempts);
        // The recorder checks at the real current time, so the month is whatever "now" is.
        self::assertSame([AiBudgetAlertRepositoryInterface::STATUS_FAILED], array_values(array_column($this->alerts->rows, 'status')));
    }

    #[Test]
    public function aRecordedRequestTriggersTheCheckAndAnUnrecordedOneDoesNot(): void
    {
        $this->configure('main', 80, 'alerts@example.com');
        $this->spent['main'] = 40_000_000;
        $usage = $this->usageRepository();
        // Admitted before the request, but the settlement write after it fails: the row stays pending, no alert check.
        $usage->method('admit')->willReturn(1);
        $usage->method('settle')->willThrowException(new \RuntimeException('usage table unavailable'));
        $recorder = new AiUsageRecorder($usage, new AiUsageMeter(), $this->createMock(BackendUserServiceInterface::class), null, $this->service($usage));

        $recorder->track('main', 'explain', new AiProviderConfiguration('anthropic', 'sk-ant-secret', 'claude-sonnet-5-5', 'site', 'fp'), static fn (): string => 'answer', new AiCostBound(1_000, 100));
        self::assertSame(0, $this->sendAttempts, 'Without a recorded usage row there is nothing new to alert about.');
    }

    private function configure(string $site, int $warningPercent, string $email, bool $hardStop = false): void
    {
        $this->settings->saveBudget($site, self::BUDGET, $warningPercent, $hardStop, 1);
        $this->settings->saveBudgetAlertEmail($site, $email);
    }

    private function now(int $offsetSeconds = 0): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('2026-11-15 12:00:00'))->modify(sprintf('%+d seconds', $offsetSeconds));
    }

    private function usageRepository(): AiUsageRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject
    {
        $usage = $this->createMock(AiUsageRepositoryInterface::class);
        $usage->method('summarize')->willReturnCallback(fn (string $site): array => [
            ['feature' => 'explain', 'provider' => 'anthropic', 'model' => 'x', 'cost_unknown' => $this->unknown[$site] ?? 0],
        ]);

        return $usage;
    }

    private function service(?AiUsageRepositoryInterface $usage = null): AiBudgetAlertService
    {
        $budget = $this->createMock(AiBudgetRepositoryInterface::class);
        $budget->method('spentMicroUsd')->willReturnCallback(fn (string $site): int => $this->spent[$site] ?? 0);
        $notifier = $this->createMock(AiBudgetAlertNotifier::class);
        $notifier->method('send')->willReturnCallback(function (AiBudgetAlertRecipient $recipient, array $alert): void {
            $this->sendAttempts++;
            if ($this->failingSends > 0) {
                $this->failingSends--;
                throw new \RuntimeException('SMTP 451 for alerts@example.com');
            }
            $this->sent[] = ['to' => $recipient->address, 'source' => $recipient->source, 'alert' => $alert];
        });

        return new AiBudgetAlertService(
            $this->alerts,
            $budget,
            $usage ?? $this->usageRepository(),
            new AiSiteSettingsService($this->settings, $this->createMock(AiConfigurationRepositoryInterface::class)),
            $notifier,
        );
    }
}
