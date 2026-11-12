<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Task;

use Priebera\A11yQualityGate\Ai\Usage\AiUsage;

/**
 * A validated structured answer. $data matched the request's schema; it is still model output, so callers treat
 * every value as untrusted text (never markup, never instructions) and only offer it as a suggestion.
 */
final readonly class AiStructuredResponse
{
    /** @param array<string,string|bool> $data */
    public function __construct(
        public array $data,
        public AiUsage $usage,
        public string $provider,
        public string $model,
    ) {}
}
