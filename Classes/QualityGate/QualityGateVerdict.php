<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\QualityGate;

/**
 * Result of a quality gate check.
 */
final class QualityGateVerdict
{
    private function __construct(
        public readonly bool $passed,
        public readonly int $mode,
        /** @var array{critical: int, warning: int, info: int} */
        public readonly array $counts,
        /** @var string[] */
        public readonly array $reasons,
    ) {
    }

    public static function pass(): self
    {
        return new self(
            passed: true,
            mode: 0,
            counts: ['critical' => 0, 'warning' => 0, 'info' => 0],
            reasons: [],
        );
    }

    /**
     * @param array{critical: int, warning: int, info: int} $counts
     * @param string[] $reasons
     */
    public static function fail(int $mode, array $counts, array $reasons): self
    {
        return new self(
            passed: false,
            mode: $mode,
            counts: $counts,
            reasons: $reasons,
        );
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function isFailed(): bool
    {
        return !$this->passed;
    }

    public function toFlashMessage(): string
    {
        return sprintf(
            'Accessibility quality gate: %s. Open the Accessibility module to review issues.',
            implode(', ', $this->reasons)
        );
    }
}
