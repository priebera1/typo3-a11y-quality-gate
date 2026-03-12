<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class TableMissingCaptionRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.table_missing_caption';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Info;
    }

    public function getMessage(): string
    {
        return 'Data table has no accessible name (missing caption or aria-label).';
    }

    public function getHint(): string
    {
        return 'Add a <caption> element or an aria-label/aria-labelledby attribute to describe the table purpose.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);

        foreach ($dom->getElementsByTagName('table') as $table) {
            if (!$table instanceof \DOMElement) {
                continue;
            }

            if ($this->isPresentationTable($table)) {
                continue;
            }

            if ($table->getElementsByTagName('tr')->length <= 1) {
                continue;
            }

            if ($this->hasAccessibleName($table)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($table, 300),
                contextPath: $this->buildXPath($table),
            );
        }

        return $violations;
    }

    private function hasAccessibleName(\DOMElement $table): bool
    {
        if ($this->hasNonEmptyAttribute($table, 'aria-label')) {
            return true;
        }

        if ($this->hasNonEmptyAttribute($table, 'aria-labelledby')) {
            return true;
        }

        foreach ($table->childNodes as $child) {
            if ($child instanceof \DOMElement && strtolower($child->tagName) === 'caption') {
                return $this->normalizedText($child->textContent) !== '';
            }
        }

        return false;
    }
}
