<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\QualityGate;

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

    public static function pass(
        int $mode = 0,
        array $counts = ['critical' => 0, 'warning' => 0, 'info' => 0]
    ): self {
        return new self(
            passed: true,
            mode: $mode,
            counts: $counts,
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

    public function isDisabled(): bool
    {
        return $this->mode === 0;
    }

    public function isWarningOnly(): bool
    {
        return $this->mode === 1;
    }

    public function isBlockingMode(): bool
    {
        return $this->mode === 2;
    }

    public function hasAnyIssues(): bool
    {
        return $this->counts['critical'] > 0
            || $this->counts['warning'] > 0
            || $this->counts['info'] > 0;
    }

    public function toFlashMessage(): string
    {
        return sprintf(
            'Accessibility quality gate: %s. Open the Accessibility module to review issues.',
            implode(', ', $this->reasons)
        );
    }

    public function toPassedFlashMessage(): string
    {
        return sprintf(
            'Quality gate passed. %d critical, %d warning, %d info issue(s) remain.',
            $this->counts['critical'],
            $this->counts['warning'],
            $this->counts['info']
        );
    }
}