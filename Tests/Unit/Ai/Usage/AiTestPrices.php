<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Usage\AiLongContextTier;
use Priebera\A11yQualityGate\Ai\Usage\AiPrice;
use Priebera\A11yQualityGate\Ai\Usage\AiRates;

/** Hand-built prices for tests (micro-USD per 1M tokens). */
final class AiTestPrices
{
    /** Input and output only; cache reads and writes at the input rate. */
    public static function simple(string $provider, string $model, int $input, int $output): AiPrice
    {
        return new AiPrice($provider, $model, new AiRates($input, $output, $input, $input, $input), null, AiPrice::ORIGIN_BUNDLED, '', 1, true);
    }

    public static function full(
        int $input,
        int $output,
        int $cached,
        int $write,
        int $writeExtended,
        ?AiLongContextTier $tier = null,
        string $provider = 'anthropic',
        string $model = 'claude-sonnet-5-5',
    ): AiPrice {
        return new AiPrice($provider, $model, new AiRates($input, $output, $cached, $write, $writeExtended), $tier, AiPrice::ORIGIN_CENTRAL, 'https://example.test/pricing.md', 1, true);
    }
}
