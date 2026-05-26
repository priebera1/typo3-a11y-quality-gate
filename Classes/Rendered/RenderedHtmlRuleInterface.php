<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\RuleViolation;

interface RenderedHtmlRuleInterface
{
    public function getRuleId(): string;

    public function getDefaultSeverity(): Severity;

    public function supports(RenderedHtmlContext $context): bool;

    /**
     * @return iterable<RuleViolation>
     */
    public function evaluate(RenderedHtmlContext $context): iterable;
}
