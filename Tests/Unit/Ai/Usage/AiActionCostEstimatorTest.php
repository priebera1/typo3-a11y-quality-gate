<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator;
use Priebera\A11yQualityGate\Ai\Usage\AiCostBound;
use Priebera\A11yQualityGate\Ai\Usage\AiPriceTable;

/**
 * AQG-300-FEAT-02/03: the short cost inside an AI action button ("Explain with AI (<$0.02)") is the worst case of one
 * request from the price table the budget uses. It never understates, never shows "$0.00" for a paid request, and is
 * absent when no reliable price exists.
 */
final class AiActionCostEstimatorTest extends TestCase
{
    /** @return iterable<string,array{0:int,1:string}> */
    public static function amountProvider(): iterable
    {
        yield 'a fraction of a tenth of a cent' => [120, '0.001'];
        yield 'just under a cent rounds up' => [9_500, '0.01'];
        yield 'an exact cent stays strictly above' => [10_000, '0.02'];
        yield 'one significant digit, rounded up' => [13_700, '0.02'];
        yield 'three decimals below a cent' => [3_100, '0.004'];
        yield 'two decimals above ten cents' => [123_000, '0.20'];
    }

    #[Test]
    #[DataProvider('amountProvider')]
    public function theAmountIsAnUpperBoundWithReasonablePrecision(int $micro, string $expected): void
    {
        self::assertSame($expected, AiActionCostEstimator::formatUpperBound($micro));
        self::assertGreaterThan($micro / 1_000_000, (float)$expected);
    }

    #[Test]
    public function germanUsesTheDecimalComma(): void
    {
        self::assertSame('0,02', AiActionCostEstimator::formatUpperBound(13_700, ','));
    }

    #[Test]
    public function theBoundIsTheBudgetBoundOfTheLargestRequestTheActionCanSend(): void
    {
        $estimator = new AiActionCostEstimator($this->createMock(AiConfigurationResolverInterface::class), $this->createMock(AiPriceTable::class));

        $explain = $estimator->bound(AiActionCostEstimator::ACTION_EXPLAIN, 'anthropic');
        self::assertInstanceOf(AiCostBound::class, $explain);
        // Output cap plus Anthropic's thinking headroom, as AiCostBound reserves it for the real request.
        self::assertSame(700 + AiCostBound::ANTHROPIC_THINKING_HEADROOM_TOKENS, $explain->outputTokens);
        self::assertGreaterThan(AiActionCostEstimator::MAX_CONTEXT_CHARACTERS + AiCostBound::ENVELOPE_TOKENS, $explain->inputTokens);
        self::assertSame(700, $estimator->bound(AiActionCostEstimator::ACTION_EXPLAIN, 'openai')?->outputTokens);
        // An image alt text sends the image: the bound counts it.
        self::assertGreaterThan(AiCostBound::IMAGE_TOKENS, $estimator->bound(AiActionCostEstimator::ACTION_FIX_IMAGE, 'openai')?->inputTokens);
        self::assertNull($estimator->bound('verify_fix', 'openai'));
        // Bytes, not characters: a capped context in Cyrillic or CJK (up to 4 bytes each) still fits the bound.
        self::assertGreaterThanOrEqual(AiActionCostEstimator::MAX_CONTEXT_CHARACTERS * 4, $explain->inputTokens - AiCostBound::ENVELOPE_TOKENS);
    }

    #[Test]
    public function aKnownPriceGivesAnAmountAndNoPriceOrNoProviderGivesNone(): void
    {
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(static fn (string $site): AiProviderConfiguration => $site === 'off'
            ? throw new AiConfigurationException('not configured', 1)
            : new AiProviderConfiguration('openai', 'key', $site === 'unknown' ? 'gpt-unknown' : 'gpt-5.4-mini', 'site'));
        $prices = $this->createMock(AiPriceTable::class);
        $prices->method('find')->willReturnCallback(static fn (string $provider, string $model) => $model === 'gpt-5.4-mini'
            ? AiTestPrices::simple('openai', $model, 750_000, 4_500_000)
            : null);
        $estimator = new AiActionCostEstimator($resolver, $prices);

        $micro = $estimator->maxCostMicro('main', AiActionCostEstimator::ACTION_EXPLAIN);
        self::assertIsInt($micro);
        self::assertSame(
            $estimator->bound(AiActionCostEstimator::ACTION_EXPLAIN, 'openai')?->costMicro(AiTestPrices::simple('openai', 'gpt-5.4-mini', 750_000, 4_500_000)),
            $micro
        );
        self::assertNull($estimator->maxCostMicro('unknown', AiActionCostEstimator::ACTION_EXPLAIN));
        self::assertNull($estimator->maxCostMicro('off', AiActionCostEstimator::ACTION_EXPLAIN));
    }
}
