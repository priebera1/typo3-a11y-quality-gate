<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class TableMissingCaptionRule implements RuleInterface
{
    public function getRuleId(): string
    {
        return 'structured.table_missing_caption';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Info;
    }

    public function getMessage(): string
    {
        return 'Table content element has no caption.';
    }

    public function getHint(): string
    {
        return 'Add a short table caption to describe the table purpose.';
    }

    public function supports(CheckContext $context): bool
    {
        return $context->sourceTable === Tables::TT_CONTENT
            && $context->sourceField === 'table_caption'
            && $context->cType === 'table';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        if (trim((string)$context->content) !== '') {
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
