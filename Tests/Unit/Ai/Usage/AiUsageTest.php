<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Usage\AiLongContextTier;
use Priebera\A11yQualityGate\Ai\Usage\AiRates;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Domain\Repository\AiConfigurationRepository;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageSummaryService;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;

final class AiUsageTest extends TestCase
{
    #[Test]
    public function providerUsageIsReadStrictly(): void
    {
        foreach (['fromOpenAiResponses', 'fromAnthropicMessages'] as $reader) {
            self::assertEquals(new AiUsage(12, 3, true), AiUsage::$reader(['input_tokens' => 12, 'output_tokens' => 3]), $reader);
            self::assertFalse(AiUsage::$reader(['input_tokens' => '12', 'output_tokens' => 3])->reported, $reader);
            self::assertFalse(AiUsage::$reader(['input_tokens' => -1, 'output_tokens' => 3])->reported, $reader);
            self::assertFalse(AiUsage::$reader(['input_tokens' => 12])->reported, $reader);
            self::assertFalse(AiUsage::$reader(null)->reported, $reader);
        }
    }

    #[Test]
    public function openAiResponsesInputIncludesCachedAndWrittenTokens(): void
    {
        // Prompt-caching guide example: 12,000 input = 1,000 ordinary + 4,000 cached + 7,000 written (3,000 × 30 min, 4,000 × 12 h).
        $usage = AiUsage::fromOpenAiResponses([
            'input_tokens' => 12_000,
            'input_tokens_details' => [
                'cached_tokens' => 4_000,
                'cache_write_tokens' => 7_000,
                'cache_write_tokens_details' => ['ttl_30m_tokens' => 3_000, 'ttl_12h_tokens' => 4_000],
            ],
            'output_tokens' => 500,
            'output_tokens_details' => ['reasoning_tokens' => 300],
        ]);

        self::assertEquals(new AiUsage(1_000, 500, true, 4_000, 3_000, 4_000), $usage);
        self::assertSame(12_000, $usage->totalInputTokens());
        // $1 input, $0.10 cached, 1.25× / 2.5× writes (the guide's worked example): $0.01515 input + output.
        $price = AiTestPrices::full(1_000_000, 8_000_000, 100_000, 1_250_000, 2_500_000, null, 'openai', 'gpt-5.4-mini');
        self::assertSame(15_150 + 4_000, $price->costOfUsage($usage));

        // Older models report cached_tokens only (automatic caching, no write premium).
        self::assertEquals(new AiUsage(872, 20, true, 128, 0, 0), AiUsage::fromOpenAiResponses(['input_tokens' => 1_000, 'input_tokens_details' => ['cached_tokens' => 128], 'output_tokens' => 20]));
        // Inconsistent shares are read the expensive way: never fewer billed input tokens, never a cheaper category.
        self::assertEquals(new AiUsage(0, 1, true, 0, 100, 0), AiUsage::fromOpenAiResponses(['input_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 90, 'cache_write_tokens' => 400], 'output_tokens' => 1]));
        self::assertEquals(new AiUsage(10, 1, true, 0, 90, 0), AiUsage::fromOpenAiResponses(['input_tokens' => 100, 'input_tokens_details' => ['cached_tokens' => 20, 'cache_write_tokens' => 90], 'output_tokens' => 1]));
    }

    #[Test]
    public function aWriteSplitWithoutItsTotalStillCountsEveryWrittenToken(): void
    {
        self::assertEquals(new AiUsage(100, 1, true, 0, 300, 600), AiUsage::fromOpenAiResponses(['input_tokens' => 1_000, 'input_tokens_details' => ['cache_write_tokens_details' => ['ttl_30m_tokens' => 300, 'ttl_12h_tokens' => 600]], 'output_tokens' => 1]));
        self::assertEquals(new AiUsage(50, 1, true, 0, 200, 100), AiUsage::fromAnthropicMessages(['input_tokens' => 50, 'cache_creation' => ['ephemeral_5m_input_tokens' => 200, 'ephemeral_1h_input_tokens' => 100], 'output_tokens' => 1]));
    }

    #[Test]
    public function anthropicMessagesReportCacheTokensOnTopOfInput(): void
    {
        $usage = AiUsage::fromAnthropicMessages([
            'input_tokens' => 50,
            'cache_creation_input_tokens' => 2_000,
            'cache_read_input_tokens' => 6_000,
            'cache_creation' => ['ephemeral_5m_input_tokens' => 1_500, 'ephemeral_1h_input_tokens' => 500],
            'output_tokens' => 120,
        ]);

        self::assertEquals(new AiUsage(50, 120, true, 6_000, 1_500, 500), $usage);
        self::assertSame(8_050, $usage->totalInputTokens());
        // Claude Sonnet 5.5: $2 input, $0.10 hits, $2.50 5-minute writes, $4 1-hour writes, $10 output.
        $price = AiTestPrices::full(2_000_000, 10_000_000, 100_000, 2_500_000, 4_000_000);
        self::assertSame(100 + 600 + 3_750 + 2_000 + 1_200, $price->costOfUsage($usage));
    }

    #[Test]
    public function aPromptLengthTierAppliesToTheWholeRequestCountingCacheTokens(): void
    {
        $tier = new AiLongContextTier(100_000, new AiRates(500_000, 2_500_000, 50_000, 625_000, 1_000_000));
        $haiku = AiTestPrices::full(100_000, 500_000, 10_000, 125_000, 200_000, $tier, 'anthropic', 'claude-haiku-5-5');

        // 40,000 ordinary + 70,000 cache reads = 110,000 prompt tokens: over 100,000, so every category pays the long rate.
        $usage = new AiUsage(40_000, 1_000, true, 70_000);
        self::assertSame(20_000 + 3_500 + 2_500, $haiku->costOfUsage($usage));
        self::assertSame(4_000 + 600 + 500, $haiku->costOfUsage(new AiUsage(40_000, 1_000, true, 60_000)), 'At exactly 100,000 the standard rates apply.');
    }

    #[Test]
    public function recorderStoresOnlyMetadataForSuccessAndFailure(): void
    {
        $rows = [];
        $repository = new AiUsageRepositoryDouble(static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });
        $users = $this->createMock(BackendUserServiceInterface::class);
        $users->method('getBackendUserUid')->willReturn(5);
        $meter = new AiUsageMeter();
        $recorder = new AiUsageRecorder($repository, $meter, $users);
        $configuration = new AiProviderConfiguration('anthropic', 'sk-ant-secret', 'claude-haiku-5-5', 'site', 'fp');

        $result = $recorder->track('main', 'alt_text', $configuration, static function () use ($meter): string {
            $meter->report(new AiUsage(900, 20, true));

            return 'Bridge';
        });
        self::assertSame('Bridge', $result);

        try {
            $recorder->track('main', 'connection_test', $configuration, static function (): void {
                throw new AiProviderException('Anthropic could not complete the request.', 1771002324);
            });
        } catch (AiProviderException) {
        }

        self::assertCount(2, $rows);
        self::assertSame(['main', 5, 'alt_text', 'anthropic', 'claude-haiku-5-5', 'success', 900, 20, 1], [
            $rows[0]['site_identifier'], $rows[0]['be_user'], $rows[0]['feature'], $rows[0]['provider'], $rows[0]['model'],
            $rows[0]['outcome'], $rows[0]['input_tokens'], $rows[0]['output_tokens'], $rows[0]['usage_reported'],
        ]);
        self::assertSame('failed_1771002324', $rows[1]['outcome']);
        self::assertSame(0, $rows[1]['usage_reported']);
        self::assertStringNotContainsString('secret', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Bridge', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aKeyOrModelRejectionOfAnEditorRequestEndsTheStoredVerification(): void
    {
        // M-04 (2026-11-05 company acceptance): after a 401 editors read "AI is paused" while Settings still said
        // "Connected". The rejection now resets the site's verification, so both say the same thing.
        $marked = [];
        $configurations = $this->createMock(AiConfigurationRepository::class);
        $configurations->method('markProviderRejected')->willReturnCallback(static function (string $site, string $provider, string $code) use (&$marked): void {
            $marked[] = [$site, $provider, $code];
        });
        $recorder = new AiUsageRecorder(new AiUsageRepositoryDouble(static function (): void {}), new AiUsageMeter(), $this->createMock(BackendUserServiceInterface::class), configurationRepository: $configurations);
        $configuration = new AiProviderConfiguration('openai', 'k', 'gpt-5.4-mini', 'site');

        foreach ([['explain', 1771002310], ['explain', 1771002311], ['connection_test', 1771002310], ['alt_text', 1771002317]] as [$task, $code]) {
            try {
                $recorder->track('northwind', $task, $configuration, static function () use ($code): void {
                    throw new AiProviderException('rejected', $code);
                });
            } catch (AiProviderException) {
            }
        }

        self::assertSame(
            [['northwind', 'openai', 'provider_1771002310'], ['northwind', 'openai', 'provider_1771002317']],
            $marked,
            'A rate limit is no verdict on the key, and a connection test records its own result.'
        );
    }

    #[Test]
    public function aFailingSettlementWriteNeverChangesTheResultAndKeepsTheAttemptCounted(): void
    {
        $repository = new AiUsageRepositoryDouble();
        $repository->failSettlement = true;
        $recorder = new AiUsageRecorder($repository, new AiUsageMeter(), $this->createMock(BackendUserServiceInterface::class));

        self::assertSame('ok', $recorder->track('main', 'alt_text', new AiProviderConfiguration('openai', 'k', 'gpt-5.4-mini', 'site'), static fn (): string => 'ok'));
        self::assertCount(1, $repository->pending(), 'the admitted row stays pending and keeps counting');
    }

    #[Test]
    public function aFailingAdmissionWriteRefusesBeforeAnythingIsSent(): void
    {
        $repository = new AiUsageRepositoryDouble();
        $repository->failAdmission = true;
        $recorder = new AiUsageRecorder($repository, new AiUsageMeter(), $this->createMock(BackendUserServiceInterface::class));
        $sent = 0;

        try {
            $recorder->track('main', 'alt_text', new AiProviderConfiguration('openai', 'k', 'gpt-5.4-mini', 'site'), static function () use (&$sent): string {
                $sent++;

                return 'ok';
            });
            self::fail('expected a refusal');
        } catch (AiConfigurationException $exception) {
            self::assertSame(AiUsageRecorder::CODE_USAGE_UNAVAILABLE, $exception->getCode());
        }
        self::assertSame(0, $sent);
        self::assertSame([], $repository->rows);
    }

    #[Test]
    public function anUnrecordableRequestIsReportedAsSuchNotAsMissingAi(): void
    {
        $exception = new AiConfigurationException('x', AiUsageRecorder::CODE_USAGE_UNAVAILABLE);
        self::assertSame('usage_unavailable', \Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService::stateForConfigurationCode(AiUsageRecorder::CODE_USAGE_UNAVAILABLE));
        $response = \Priebera\A11yQualityGate\Ai\Editor\EditorAiErrorResponse::fromThrowable($exception, false);
        self::assertSame(503, $response?->getStatusCode());
        self::assertSame('usage_unavailable', json_decode((string)$response?->getBody(), true)['code']);
    }

    private static function group(string $feature, int $requests, int $succeeded, int $failed, int $in, int $out, int $unreported, int $reported, int $unknown): array
    {
        return ['be_user' => 1, 'feature' => $feature, 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'requests' => $requests,
            'succeeded' => $succeeded, 'failed' => $failed, 'pending' => 0, 'input_tokens' => $in, 'cache_read_tokens' => 0,
            'output_tokens' => $out, 'unreported' => $unreported, 'reported_micro_usd' => $reported, 'bound_micro_usd' => 0,
            'bound_requests' => 0, 'no_charge' => 0, 'unknown' => $unknown, 'reserved_micro_usd' => 0];
    }

    /**
     * Until AQG 3.0 the summary carried no cost at all (no prices existed). Usage rows now store the estimated cost
     * of their request; rows written without a price (and summary groups from before this change) stay "Unknown".
     */
    #[Test]
    public function monthlySummaryTotalsReportedTokensAndUnknownCostWithoutPrices(): void
    {
        $repository = $this->createMock(AiUsageRepositoryInterface::class);
        $repository->expects(self::once())->method('breakdown')
            ->with('main', strtotime('2026-10-01 00:00:00'), strtotime('2026-11-01 00:00:00'))
            ->willReturn([
                self::group('connection_test', 2, 1, 1, 600, 40, 1, 0, 2),
                self::group('alt_text', 3, 3, 0, 2700, 60, 0, 0, 3),
            ]);

        $summary = (new AiUsageSummaryService($repository, new AiModelCompatibilityRegistry()))
            ->currentMonth('main', new \DateTimeImmutable('2026-10-22 12:00:00'));

        self::assertSame(['alt_text', 'connection_test'], array_column($summary['rows'], 'feature'));
        self::assertSame('Claude Haiku 5.5', $summary['rows'][0]['modelLabel']);
        self::assertSame('Anthropic Claude', $summary['rows'][0]['providerLabel']);
        $totals = array_intersect_key($summary['totals'], array_flip(['requests', 'failed', 'inputTokens', 'cachedInputTokens', 'outputTokens', 'unreported', 'costMicroUsd', 'costUnknown', 'costLabel', 'costState']));
        self::assertEquals(['requests' => 5, 'failed' => 1, 'inputTokens' => 3300, 'cachedInputTokens' => 0, 'outputTokens' => 100, 'unreported' => 1, 'costMicroUsd' => 0, 'costUnknown' => 5, 'costLabel' => '', 'costState' => 'unknown'], $totals);
        self::assertSame('', $summary['rows'][0]['costLabel'], 'No price: the template shows "Unknown", never $0.00.');
    }

    #[Test]
    public function monthlySummaryAddsTheStoredEstimatedCost(): void
    {
        $repository = $this->createMock(AiUsageRepositoryInterface::class);
        $repository->method('breakdown')->willReturn([
            self::group('alt_text', 3, 3, 0, 2700, 60, 0, 5_950_000, 0),
            self::group('link_text', 2, 2, 0, 100, 10, 0, 4_000, 1),
        ]);

        $summary = (new AiUsageSummaryService($repository, new AiModelCompatibilityRegistry()))
            ->currentMonth('main', new \DateTimeImmutable('2026-10-22 12:00:00'));

        self::assertSame('$5.95', $summary['rows'][0]['costLabel']);
        self::assertSame('< $0.01', $summary['rows'][1]['costLabel']);
        self::assertSame(5_954_000, $summary['totals']['costMicroUsd']);
        self::assertSame(1, $summary['totals']['costUnknown']);
        self::assertSame('$5.95', $summary['totals']['costLabel']);
    }
}
