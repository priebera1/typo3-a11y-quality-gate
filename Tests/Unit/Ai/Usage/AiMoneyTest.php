<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Usage\AiMoney;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;

/** Price math in integer micro-USD: never floats, estimates rounded up. */
final class AiMoneyTest extends TestCase
{
    #[Test]
    public function costIsRoundedUpToTheNextMicroDollar(): void
    {
        // $2.00 per 1M tokens: 1 token = 2 micro-USD exactly.
        self::assertSame(2, AiMoney::costMicro(1, 2_000_000));
        // $0.10 per 1M tokens: 1 token = 0.1 micro-USD, rounded up to 1.
        self::assertSame(1, AiMoney::costMicro(1, 100_000));
        // 10 tokens at $0.10 = exactly 1 micro-USD: no extra rounding.
        self::assertSame(1, AiMoney::costMicro(10, 100_000));
        self::assertSame(2, AiMoney::costMicro(11, 100_000));
        self::assertSame(0, AiMoney::costMicro(0, 2_000_000));
        self::assertSame(0, AiMoney::costMicro(500, 0));
    }

    #[Test]
    public function aPriceAddsBothDirectionsSeparatelyRounded(): void
    {
        $price = AiTestPrices::simple('anthropic', 'claude-sonnet-5-5', 2_000_000, 10_000_000);
        $usage = new AiUsage(1_912_400, 212_880, true);

        // 1,912,400 input × $2/1M = $3.8248; 212,880 output × $10/1M = $2.1288.
        self::assertSame(3_824_800 + 2_128_800, $price->costOfUsage($usage));
        self::assertSame('$5.95', AiMoney::formatUsd($price->costOfUsage($usage)));
    }

    #[Test]
    public function largeMonthlyTotalsStayExact(): void
    {
        // A million requests of 2,000 tokens at the highest accepted price never overflow 64-bit integers.
        $perRequest = AiMoney::costMicro(2_000, AiMoney::MAX_PRICE_MICRO);
        self::assertSame(20_000_000, $perRequest);
        self::assertSame(20_000_000_000_000, $perRequest * 1_000_000);
    }

    /** @return iterable<string,array{string,int,?int}> */
    public static function usdInputs(): iterable
    {
        yield 'whole dollars' => ['50', 2, 50_000_000];
        yield 'cents' => ['41.20', 2, 41_200_000];
        yield 'comma decimal' => ['2,5', 2, 2_500_000];
        yield 'dollar sign' => ['$20', 2, 20_000_000];
        yield 'six decimals for prices' => ['0.075', 6, 75_000];
        yield 'too many decimals' => ['0.075', 2, null];
        yield 'negative' => ['-1', 2, null];
        yield 'exponent' => ['1e3', 2, null];
        yield 'thousands separator' => ['1,000.00', 2, null];
        yield 'empty' => ['', 2, null];
    }

    #[Test]
    #[DataProvider('usdInputs')]
    public function typedAmountsAreParsedStrictly(string $input, int $decimals, ?int $expected): void
    {
        self::assertSame($expected, AiMoney::parseUsd($input, $decimals));
    }

    #[Test]
    public function amountsAreFormattedForAdministrators(): void
    {
        self::assertSame('$0.00', AiMoney::formatUsd(0));
        self::assertSame('< $0.01', AiMoney::formatUsd(4_999));
        self::assertSame('$0.01', AiMoney::formatUsd(10_000));
        self::assertSame('$50.00', AiMoney::formatUsd(49_995_000));
        self::assertSame('$1,234.57', AiMoney::formatUsd(1_234_565_000));
        self::assertSame('$2.00', AiMoney::formatPrice(2_000_000));
        self::assertSame('$0.075', AiMoney::formatPrice(75_000));
        self::assertSame('2.5', AiMoney::toInput(2_500_000));
        self::assertSame('', AiMoney::toInput(0));
    }

    #[Test]
    public function percentRoundsHalfUpButReads100OnlyWhenUsedUp(): void
    {
        self::assertSame(99, AiMoney::percent(49_999_999, 50_000_000));
        self::assertSame(100, AiMoney::percent(50_000_000, 50_000_000));
        self::assertSame(82, AiMoney::percent(41_200_000, 50_000_000));
        self::assertSame(12, AiMoney::percent(5_950_000, 50_000_000));
        self::assertSame(0, AiMoney::percent(5, 0));
    }
}
