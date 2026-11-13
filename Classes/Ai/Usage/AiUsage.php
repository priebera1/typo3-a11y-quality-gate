<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

/**
 * Token counts as the provider reported them for one request, normalized into the categories that are billed at
 * different rates. $inputTokens are ORDINARY (uncached) input tokens only; cache reads and writes are separate.
 * $reported is false when the provider answered without usage data (or not at all); the counts are then 0 and never
 * estimated.
 *
 * OpenAI Responses API: usage.input_tokens INCLUDES input_tokens_details.cached_tokens (billed reads) and
 * input_tokens_details.cache_write_tokens (split into cache_write_tokens_details.ttl_30m_tokens / ttl_12h_tokens),
 * so ordinary input = input_tokens − cached − written. output_tokens includes reasoning tokens.
 * Anthropic Messages API: usage.input_tokens is already the ordinary input; cache_read_input_tokens and
 * cache_creation_input_tokens (split into cache_creation.ephemeral_5m/1h_input_tokens) come on top.
 */
final readonly class AiUsage
{
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public bool $reported,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
        public int $cacheWriteExtendedTokens = 0,
    ) {}

    public static function notReported(): self
    {
        return new self(0, 0, false);
    }

    /** Every input token of the request: ordinary, read from and written to the cache. */
    public function totalInputTokens(): int
    {
        return $this->inputTokens + $this->cacheReadTokens + $this->cacheWriteTokens + $this->cacheWriteExtendedTokens;
    }

    public function cacheWriteTotal(): int
    {
        return $this->cacheWriteTokens + $this->cacheWriteExtendedTokens;
    }

    public static function fromOpenAiResponses(mixed $usage): self
    {
        if (!is_array($usage)) {
            return self::notReported();
        }
        $input = $usage['input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;
        if (!self::isCount($input) || !self::isCount($output)) {
            return self::notReported();
        }
        $details = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $cached = self::count($details['cached_tokens'] ?? 0);
        $written = self::count($details['cache_write_tokens'] ?? 0);
        $writeDetails = is_array($details['cache_write_tokens_details'] ?? null) ? $details['cache_write_tokens_details'] : [];
        $extended = self::count($writeDetails['ttl_12h_tokens'] ?? 0);
        // A split without (or above) its total still counts every written token.
        $written = max($written, self::count($writeDetails['ttl_30m_tokens'] ?? 0) + $extended);

        // Inconsistent shares (more cached and written tokens than input) are read the expensive way: writes are kept
        // as far as the input allows and cache reads lose their discount — never an underestimate.
        $written = min($written, $input);
        $extended = min($extended, $written);
        if ($cached + $written > $input) {
            $cached = 0;
        }

        return new self($input - $cached - $written, $output, true, $cached, $written - $extended, $extended);
    }

    public static function fromAnthropicMessages(mixed $usage): self
    {
        if (!is_array($usage)) {
            return self::notReported();
        }
        $input = $usage['input_tokens'] ?? null;
        $output = $usage['output_tokens'] ?? null;
        if (!self::isCount($input) || !self::isCount($output)) {
            return self::notReported();
        }
        $read = self::count($usage['cache_read_input_tokens'] ?? 0);
        $written = self::count($usage['cache_creation_input_tokens'] ?? 0);
        $creation = is_array($usage['cache_creation'] ?? null) ? $usage['cache_creation'] : [];
        $extended = self::count($creation['ephemeral_1h_input_tokens'] ?? 0);
        $written = max($written, self::count($creation['ephemeral_5m_input_tokens'] ?? 0) + $extended);

        return new self($input, $output, true, $read, $written - $extended, $extended);
    }

    private static function isCount(mixed $value): bool
    {
        return is_int($value) && $value >= 0;
    }

    private static function count(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }
}
