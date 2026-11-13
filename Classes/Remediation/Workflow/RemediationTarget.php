<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

/**
 * The exact place a prepared change would go, resolved server-side from a stored finding (the browser only ever
 * names the finding). $baseHash fingerprints the target's current value: a change prepared on one value is never
 * applied over another. $unavailableReason explains why no safe target exists; such findings keep manual guidance.
 */
final readonly class RemediationTarget
{
    /** @param array<string,mixed> $descriptor */
    public function __construct(
        public string $kind,
        public string $table,
        public int $uid,
        public string $field,
        public array $descriptor,
        public string $currentValue,
        public string $baseHash,
        public string $aiKind = '',
        public string $unavailableReason = '',
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(RemediationTargetKind::MANUAL, '', 0, '', [], '', '', '', $reason);
    }

    public function isAvailable(): bool
    {
        return $this->unavailableReason === '' && $this->kind !== RemediationTargetKind::MANUAL;
    }

    /** The value fingerprint every target kind uses: the same bytes give the same hash, any edit changes it. */
    public static function hashValue(string ...$parts): string
    {
        return hash('sha256', implode("\x1f", $parts));
    }
}
