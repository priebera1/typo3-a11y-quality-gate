<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class HeaderLevelIsH1Rule implements RuleInterface
{
    public function getRuleId(): string
    {
        return 'structured.header_level_is_h1';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::NeedsReview;
    }

    public function getMessage(): string
    {
        return 'Header content element is configured as H1.';
    }

    public function getHint(): string
    {
        return 'This is a review item, not an automatic failure. Verify manually that this is the only H1 on the page and that it represents the main page heading.';
    }

    public function supports(CheckContext $context): bool
    {
        return $context->sourceTable === Tables::TT_CONTENT
            && $context->sourceField === 'header_layout'
            && strtolower(trim($context->cType)) === 'header';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        if (trim((string)$context->content) !== '1') {
            return [];
        }

        return [
            new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: 'header_layout=1',
                contextPath: $context->contextPath,
            ),
        ];
    }
}
