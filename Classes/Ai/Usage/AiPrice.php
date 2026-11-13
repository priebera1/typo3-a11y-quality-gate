<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

/**
 * The effective price of one provider model, as AiPriceTable resolves it — THE price the settings screen shows, the
 * budget reserves, a request is settled at and the monthly usage sums up. Rates are micro-USD per 1M tokens.
 *
 * Origin:
 * - bundled: the reviewed catalogue shipped with this AQG release (AiBundledPriceCatalogue);
 * - central: the verified catalogue of the AQG service (AiPriceCatalogueService), read from the providers' official
 *   pricing pages once, centrally;
 * - manual: a price an administrator entered before AQG 3.0 (tx_a11y_ai_price). Kept only as a fallback for a model
 *   the automatic catalogue does not price. It carries no cache or prompt-length rates, so it is not "complete":
 *   cache reads are priced as input, cache writes at 1.25× input, and a hard-stop budget refuses to rely on it.
 */
final readonly class AiPrice
{
    public const ORIGIN_BUNDLED = 'bundled';
    public const ORIGIN_CENTRAL = 'central';
    public const ORIGIN_MANUAL = 'manual';

    public int $inputMicroPerMillion;
    public int $outputMicroPerMillion;

    public function __construct(
        public string $provider,
        public string $model,
        public AiRates $rates,
        public ?AiLongContextTier $longContext,
        public string $origin,
        public string $sourceUrl,
        public int $verifiedAt,
        public bool $complete,
        public string $label = '',
    ) {
        $this->inputMicroPerMillion = $rates->input;
        $this->outputMicroPerMillion = $rates->output;
    }

    /**
     * A pre-3.0 administrator-entered price (tx_a11y_ai_price row): input and output only. Null for a row without a
     * usable input or output price.
     *
     * @param array<string,mixed> $row
     */
    public static function fromLegacyRow(array $row): ?self
    {
        $input = max(0, (int)($row['input_micro_usd'] ?? 0));
        $output = max(0, (int)($row['output_micro_usd'] ?? 0));
        if ($input <= 0 || $output <= 0) {
            return null;
        }
        // Upper bounds, never an assumed discount: reads at the input rate, writes at the highest default-lifetime
        // premium either provider charges (1.25×), extended writes at 2×.
        $rates = new AiRates($input, $output, $input, intdiv($input * 5 + 3, 4), $input * 2);

        return new self(
            (string)($row['provider'] ?? ''),
            (string)($row['model'] ?? ''),
            $rates,
            null,
            self::ORIGIN_MANUAL,
            (string)($row['source'] ?? ''),
            max(0, (int)($row['price_as_of'] ?? 0)),
            false,
        );
    }

    /** The rates a request with $inputTokens of input is billed at. */
    public function ratesFor(int $inputTokens): AiRates
    {
        return $this->longContext !== null && $this->longContext->applies($inputTokens) ? $this->longContext->rates : $this->rates;
    }

    /**
     * Estimated cost of one request from the usage the provider reported, each category at its own rate and rounded
     * up to the next micro-USD.
     */
    public function costOfUsage(AiUsage $usage): int
    {
        $rates = $this->ratesFor($usage->totalInputTokens());

        return AiMoney::costMicro($usage->inputTokens, $rates->input)
            + AiMoney::costMicro($usage->cacheReadTokens, $rates->cachedInput)
            + AiMoney::costMicro($usage->cacheWriteTokens, $rates->cacheWrite)
            + AiMoney::costMicro($usage->cacheWriteExtendedTokens, $rates->cacheWriteExtended)
            + AiMoney::costMicro($usage->outputTokens, $rates->output);
    }

    /**
     * The most a request with at most $inputTokens input and $outputTokens output can cost: the prompt-length tier
     * its input could reach, every input token at the worst input rate (an automatic cache write at most), every
     * output token at the output rate.
     */
    public function worstCaseMicro(int $inputTokens, int $outputTokens): int
    {
        $rates = $this->ratesFor($inputTokens);

        // + 3: a settlement rounds up to five category terms separately, the bound two; never let it exceed the bound.
        return AiMoney::costMicro($inputTokens, $rates->worstInput()) + AiMoney::costMicro($outputTokens, $rates->output) + 3;
    }

    public function isStale(int $now, int $maxAgeSeconds): bool
    {
        return $this->verifiedAt <= 0 || $this->verifiedAt < $now - $maxAgeSeconds;
    }
}
