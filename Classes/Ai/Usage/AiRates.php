<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

/**
 * The per-category rates of one model at one context tier, in micro-USD per 1M tokens (integers, never floats).
 *
 * - input: ordinary, uncached input tokens;
 * - cachedInput: input tokens read from the provider's prompt cache (OpenAI "cached input", Anthropic "cache hits");
 * - cacheWrite: input tokens written to the cache at the provider's default lifetime (OpenAI 30 minutes, Anthropic
 *   5 minutes) — the only lifetime AQG's requests can cause;
 * - cacheWriteExtended: input tokens written at the longer lifetime (OpenAI 12 hours, Anthropic 1 hour). AQG never
 *   asks for it; the rate is kept so a reported write of that kind is still priced exactly;
 * - output: output tokens, reasoning and thinking tokens included.
 */
final readonly class AiRates
{
    public function __construct(
        public int $input,
        public int $output,
        public int $cachedInput,
        public int $cacheWrite,
        public int $cacheWriteExtended,
    ) {}

    /** @return array{input:int,output:int,cached_input:int,cache_write:int,cache_write_extended:int} */
    public function toArray(): array
    {
        return [
            'input' => $this->input,
            'output' => $this->output,
            'cached_input' => $this->cachedInput,
            'cache_write' => $this->cacheWrite,
            'cache_write_extended' => $this->cacheWriteExtended,
        ];
    }

    /**
     * Reads a stored rate set; null when any rate is missing, not an integer, out of range, or breaks the relations
     * every provider price obeys (cached ≤ input ≤ cache write ≤ extended cache write).
     */
    public static function fromArray(mixed $rates): ?self
    {
        if (!is_array($rates)) {
            return null;
        }
        $values = [];
        foreach (['input', 'output', 'cached_input', 'cache_write', 'cache_write_extended'] as $key) {
            $value = $rates[$key] ?? null;
            if (!is_int($value) || $value < 0 || $value > AiMoney::MAX_PRICE_MICRO) {
                return null;
            }
            $values[$key] = $value;
        }
        $candidate = new self($values['input'], $values['output'], $values['cached_input'], $values['cache_write'], $values['cache_write_extended']);

        return $candidate->isConsistent() ? $candidate : null;
    }

    public function isConsistent(): bool
    {
        return $this->input > 0
            && $this->output > 0
            && $this->cachedInput > 0
            && $this->cachedInput <= $this->input
            && $this->cacheWrite >= $this->input
            && $this->cacheWriteExtended >= $this->cacheWrite;
    }

    /** The highest rate any input token of an AQG request can be billed at (an automatic cache write at most). */
    public function worstInput(): int
    {
        return max($this->input, $this->cacheWrite);
    }

    /** @return list<int> every rate, for change comparisons */
    public function values(): array
    {
        return [$this->input, $this->output, $this->cachedInput, $this->cacheWrite, $this->cacheWriteExtended];
    }
}
