<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class TableMissingHeaderRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.table_missing_header';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Data table has no header cells.';
    }

    public function getHint(): string
    {
        return 'Add <th> elements for column or row headers. If this is a layout table, add role="presentation".';
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

            $rows = $table->getElementsByTagName('tr');
            if ($rows->length <= 1) {
                continue;
            }

            $cells = $table->getElementsByTagName('td');
            if ($cells->length === 0) {
                continue;
            }

            $headers = $table->getElementsByTagName('th');
            if ($headers->length > 0) {
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
}
