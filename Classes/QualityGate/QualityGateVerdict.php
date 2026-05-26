<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\QualityGate;

final class QualityGateVerdict
{
    private function __construct(
        public readonly bool $passed,
        public readonly int $mode,
        /** @var array{critical: int, warning: int, info: int, needs_review?: int} */
        public readonly array $counts,
        /** @var string[] */
        public readonly array $reasons,
    ) {
    }

    public static function pass(
        int $mode = 0,
        array $counts = ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0]
    ): self {
        return new self(
            passed: true,
            mode: $mode,
            counts: $counts,
            reasons: [],
        );
    }

    /**
     * @param array{critical: int, warning: int, info: int, needs_review?: int} $counts
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
        return (int)($this->counts['critical'] ?? 0) > 0
            || (int)($this->counts['warning'] ?? 0) > 0
            || (int)($this->counts['info'] ?? 0) > 0
            || (int)($this->counts['needs_review'] ?? 0) > 0;
    }

    public function toFlashMessage(): string
    {
        return sprintf(
            'Accessibility quality gate: %s. Current open findings: %s. Needs review items are manual checks and do not block publishing.',
            implode(', ', $this->reasons),
            $this->formatCountsSummary()
        );
    }

    public function toPassedFlashMessage(): string
    {
        return sprintf(
            'Quality gate passed. Current open findings: %s. Needs review items are manual checks and do not block publishing.',
            $this->formatCountsSummary()
        );
    }

    private function formatCountsSummary(): string
    {
        $parts = [];
        $critical = (int)($this->counts['critical'] ?? 0);
        $warning = (int)($this->counts['warning'] ?? 0);
        $info = (int)($this->counts['info'] ?? 0);
        $needsReview = (int)($this->counts['needs_review'] ?? 0);

        if ($critical > 0) {
            $parts[] = $critical . ' critical';
        }
        if ($warning > 0) {
            $parts[] = $warning . ' warning';
        }
        if ($info > 0) {
            $parts[] = $info . ' info';
        }
        if ($needsReview > 0) {
            $parts[] = $needsReview . ' needs review';
        }

        return $parts !== [] ? implode(', ', $parts) : 'none';
    }
}
