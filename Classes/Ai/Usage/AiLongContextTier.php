<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

/**
 * A prompt-length price tier: a request whose input (ordinary, cached and cache-written tokens together) exceeds
 * $threshold tokens is billed at $rates for the whole request — OpenAI's long-context rule (e.g. gpt-5.4 above 272K)
 * and Anthropic's prompt-length pricing (Claude Haiku 5.5 above 100,000). The tier is decided per request.
 * The long rates are stored as printed by the provider, never derived through a multiplier.
 */
final readonly class AiLongContextTier
{
    public function __construct(
        public int $threshold,
        public AiRates $rates,
    ) {}

    /** @return array{threshold:int,rates:array<string,int>} */
    public function toArray(): array
    {
        return ['threshold' => $this->threshold, 'rates' => $this->rates->toArray()];
    }

    public static function fromArray(mixed $tier): ?self
    {
        if (!is_array($tier) || !is_int($tier['threshold'] ?? null) || $tier['threshold'] < 1) {
            return null;
        }
        $rates = AiRates::fromArray($tier['rates'] ?? null);

        return $rates !== null ? new self($tier['threshold'], $rates) : null;
    }

    public function applies(int $inputTokens): bool
    {
        return $inputTokens > $this->threshold;
    }
}
