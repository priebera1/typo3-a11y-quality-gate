<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Priebera\A11yQualityGate\Rule\RuleViolation;

/**
 * @phpstan-type RenderedPageViolations list<RuleViolation>
 */
final readonly class RenderedPageScanResult
{
    /**
     * @param list<RuleViolation> $violations
     */
    public function __construct(
        public bool $completed,
        public array $violations = [],
        public string $warning = '',
    ) {
    }
}
