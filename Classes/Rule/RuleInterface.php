<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;

interface RuleInterface
{
    public function getRuleId(): string;

    public function getDefaultSeverity(): Severity;

    public function getMessage(): string;

    public function getHint(): string;

    public function supports(CheckContext $context): bool;

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array;
}
