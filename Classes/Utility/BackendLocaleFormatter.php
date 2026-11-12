<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

use Priebera\A11yQualityGate\Ai\Usage\AiMoney;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Dates, numbers and USD amounts in the backend user's language. English (also the CLI and any request without a
 * backend language) keeps AQG's established output ("2 Nov 2026", "1,332", "$25.00"); every other language is
 * formatted by ext-intl for that locale (German: "02.11.2026", "November 2026", "1.332", "25,00 $"). Without ext-intl
 * the English output is used.
 */
final class BackendLocaleFormatter
{
    private const ENGLISH = 'en';

    private readonly string $locale;

    public function __construct(?string $locale = null)
    {
        $locale = trim(str_replace('_', '-', (string)($locale ?? self::backendLocale())));
        $this->locale = $locale === '' || $locale === 'default' || !extension_loaded('intl') ? self::ENGLISH : $locale;
    }

    public static function forBackendUser(): self
    {
        return new self();
    }

    public function isEnglish(): bool
    {
        return $this->locale === self::ENGLISH || str_starts_with($this->locale, self::ENGLISH . '-');
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * The BCP 47 locale for Intl in backend JavaScript: English is en-GB ("2 Nov 2026, 19:39", like date() and
     * dateTime() here), every other backend language its own locale ("02.11.2026, 19:39").
     */
    public function jsLocale(): string
    {
        return $this->isEnglish() ? 'en-GB' : $this->locale;
    }

    /** A calendar date: "2 Nov 2026" / "02.11.2026". */
    public function date(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        if ($this->isEnglish()) {
            return date('j M Y', $timestamp);
        }

        return $this->formatDate($timestamp, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE) ?? date('j M Y', $timestamp);
    }

    /** Date and time: "2 Nov 2026, 19:39" / "02.11.2026, 19:39". */
    public function dateTime(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        if ($this->isEnglish()) {
            return date('j M Y, H:i', $timestamp);
        }

        return $this->formatDate($timestamp, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT) ?? date('j M Y, H:i', $timestamp);
    }

    /** Month and year: "November 2026" / "November 2026" (de) / "listopad 2026" (cs). */
    public function monthYear(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }
        if ($this->isEnglish()) {
            return date('F Y', $timestamp);
        }
        $pattern = 'MMMM y';
        if (class_exists(\IntlDatePatternGenerator::class)) {
            $pattern = (string)((new \IntlDatePatternGenerator($this->locale))->getBestPattern('MMMMy') ?: $pattern);
        }
        $formatter = new \IntlDateFormatter($this->locale, \IntlDateFormatter::NONE, \IntlDateFormatter::NONE, null, null, $pattern);
        $formatted = $formatter->format($timestamp);

        return is_string($formatted) ? $formatted : date('F Y', $timestamp);
    }

    /** A whole number with the locale's grouping: "1,332" / "1.332". */
    public function integer(int $value): string
    {
        if ($this->isEnglish()) {
            return number_format($value);
        }
        $formatted = (new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL))->format($value, \NumberFormatter::TYPE_INT64);

        return is_string($formatted) ? $formatted : number_format($value);
    }

    /** Grouping separator the locale uses for whole numbers (for f:format.number in Fluid). */
    public function thousandsSeparator(): string
    {
        if ($this->isEnglish()) {
            return ',';
        }
        $symbol = (new \NumberFormatter($this->locale, \NumberFormatter::DECIMAL))->getSymbol(\NumberFormatter::GROUPING_SEPARATOR_SYMBOL);

        return is_string($symbol) && $symbol !== '' ? $symbol : ',';
    }

    /**
     * An amount in micro-USD, rounded to cents: "$5.95" / "5,95 $". An amount above zero but below one cent reads
     * "< $0.01" / "< 0,01 $" (see isBelowOneCent()).
     */
    public function usd(int $micro): string
    {
        if ($this->isEnglish()) {
            return AiMoney::formatUsd($micro);
        }
        if (self::isBelowOneCent($micro)) {
            return '< ' . $this->currency(0.01, 2, 2);
        }
        $cents = intdiv(max(0, $micro) + 5_000, 10_000);

        return $this->currency($cents / 100, 2, 2);
    }

    /** A price per 1M tokens with two to six decimals: "$0.075" / "0,075 $". */
    public function usdPrice(int $micro): string
    {
        if ($this->isEnglish()) {
            return AiMoney::formatPrice($micro);
        }

        return $this->currency(max(0, $micro) / AiMoney::MICRO_PER_USD, 2, 6);
    }

    /** True when usd() shows "< $0.01": the label is already a bound, so no "≈" belongs in front of it. */
    public static function isBelowOneCent(int $micro): bool
    {
        return $micro > 0 && $micro < 10_000;
    }

    private function currency(float $amount, int $minDecimals, int $maxDecimals): string
    {
        $formatter = new \NumberFormatter($this->locale, \NumberFormatter::CURRENCY);
        $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $minDecimals);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxDecimals);
        $formatted = $formatter->formatCurrency($amount, 'USD');
        if (!is_string($formatted)) {
            return '$' . number_format($amount, $minDecimals);
        }

        // ICU separates the symbol with a no-break space; keep it, but never a narrow one that some fonts lack.
        return str_replace("\u{202F}", "\u{00A0}", $formatted);
    }

    private function formatDate(int $timestamp, int $dateType, int $timeType): ?string
    {
        $formatted = (new \IntlDateFormatter($this->locale, $dateType, $timeType))->format($timestamp);

        return is_string($formatted) ? $formatted : null;
    }

    private static function backendLocale(): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return self::ENGLISH;
        }
        $locale = $languageService->getLocale();
        if ($locale !== null) {
            return $locale->getName();
        }

        return $languageService->lang;
    }
}
