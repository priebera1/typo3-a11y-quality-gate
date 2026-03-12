<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class TableThMissingScopeRule extends AbstractRteRule
{
    private const VALID_SCOPE_VALUES = ['col', 'row', 'colgroup', 'rowgroup'];

    public function getRuleId(): string
    {
        return 'rte.table_th_missing_scope';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Table header cell is missing a scope attribute.';
    }

    public function getHint(): string
    {
        return 'Add scope="col" for column headers or scope="row" for row headers.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);

        foreach ($dom->getElementsByTagName('th') as $tableHeaderCell) {
            if (!$tableHeaderCell instanceof \DOMElement) {
                continue;
            }

            if ($this->isInsidePresentationTable($tableHeaderCell)) {
                continue;
            }

            $scope = strtolower($this->normalizedText($tableHeaderCell->getAttribute('scope')));

            if (in_array($scope, self::VALID_SCOPE_VALUES, true)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($tableHeaderCell),
                contextPath: $this->buildXPath($tableHeaderCell),
            );
        }

        return $violations;
    }

    private function isInsidePresentationTable(\DOMElement $element): bool
    {
        $table = $this->findAncestorTable($element);

        return $table instanceof \DOMElement && $this->isPresentationTable($table);
    }
}
