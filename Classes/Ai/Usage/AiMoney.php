<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

/**
 * Money arithmetic for AI cost estimates. Amounts are integers in micro-USD (1 USD = 1,000,000 micro-USD), never
 * floats, so sums of thousands of small requests stay exact. Prices are micro-USD per 1M tokens.
 */
final class AiMoney
{
    public const MICRO_PER_USD = 1_000_000;
    public const TOKENS_PER_PRICE_UNIT = 1_000_000;
    /** Upper limit for an entered price ($10,000 per 1M tokens); far above any real model price. */
    public const MAX_PRICE_MICRO = 10_000 * self::MICRO_PER_USD;
    public const MIN_BUDGET_MICRO = self::MICRO_PER_USD;
    public const MAX_BUDGET_MICRO = 1_000_000 * self::MICRO_PER_USD;

    /**
     * Cost of $tokens at $pricePerMillionMicro, rounded up to the next micro-USD: an estimate never comes out lower
     * than the exact product.
     */
    public static function costMicro(int $tokens, int $pricePerMillionMicro): int
    {
        if ($tokens <= 0 || $pricePerMillionMicro <= 0) {
            return 0;
        }
        $product = $tokens * $pricePerMillionMicro;

        return intdiv($product, self::TOKENS_PER_PRICE_UNIT) + ($product % self::TOKENS_PER_PRICE_UNIT > 0 ? 1 : 0);
    }

    /**
     * Parses a USD amount typed by an administrator ("2", "2.5", "0.075"). Accepts digits with an optional "." or ","
     * and at most $maxDecimals decimals; anything else (signs, exponents, thousands separators) is rejected.
     */
    public static function parseUsd(string $value, int $maxDecimals): ?int
    {
        $value = str_replace(',', '.', trim($value));
        $value = ltrim($value, '$');
        if (preg_match('/^(\d{1,9})(?:\.(\d{1,' . max(0, min(6, $maxDecimals)) . '}))?$/', $value, $matches) !== 1) {
            return null;
        }
        $fraction = str_pad($matches[2] ?? '', 6, '0');

        return (int)$matches[1] * self::MICRO_PER_USD + (int)$fraction;
    }

    /** "$5.95"; amounts below one cent but above zero read "< $0.01". Rounded half up to cents. */
    public static function formatUsd(int $micro): string
    {
        if ($micro > 0 && $micro < 10_000) {
            return '< $0.01';
        }
        $cents = intdiv(max(0, $micro) + 5_000, 10_000);

        return '$' . number_format(intdiv($cents, 100)) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A price per 1M tokens with at least two and at most six decimals: "$2.00", "$0.075". */
    public static function formatPrice(int $micro): string
    {
        $micro = max(0, $micro);
        $fraction = rtrim(str_pad((string)($micro % self::MICRO_PER_USD), 6, '0', STR_PAD_LEFT), '0');

        return '$' . number_format(intdiv($micro, self::MICRO_PER_USD)) . '.' . str_pad($fraction, 2, '0');
    }

    /** The plain input value for a form field ("2.5"), without currency sign or grouping. */
    public static function toInput(int $micro): string
    {
        if ($micro <= 0) {
            return '';
        }
        $fraction = rtrim(str_pad((string)($micro % self::MICRO_PER_USD), 6, '0', STR_PAD_LEFT), '0');

        return intdiv($micro, self::MICRO_PER_USD) . ($fraction !== '' ? '.' . $fraction : '');
    }

    /** Whole percent of $part in $whole, rounded half up; a budget reads 100 % only once it is used up. */
    public static function percent(int $part, int $whole): int
    {
        if ($whole <= 0) {
            return 0;
        }
        $part = max(0, $part);
        $percent = intdiv($part * 200 + $whole, $whole * 2);

        return $part < $whole ? min(99, $percent) : $percent;
    }
}
