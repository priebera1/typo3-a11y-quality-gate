<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class HeaderCtypeEmptyRule implements RuleInterface
{
    public function getRuleId(): string
    {
        return 'structured.header_ctype_empty';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Header content element has an empty headline.';
    }

    public function getHint(): string
    {
        return 'Add a meaningful headline or use a different content element type.';
    }

    public function supports(CheckContext $context): bool
    {
        return $context->sourceTable === Tables::TT_CONTENT
            && $context->sourceField === 'header'
            && $context->cType === 'header';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $value = trim((string)$context->content);

        if ($value !== '') {
            return [];
        }

        return [
            new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: '',
                contextPath: $context->contextPath,
            ),
        ];
    }
}
