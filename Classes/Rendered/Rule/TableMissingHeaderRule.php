<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class TableMissingHeaderRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.table_missing_header'; }
    public function getDefaultSeverity(): Severity { return Severity::Warning; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach ($context->document->getElementsByTagName('table') as $table) {
            if (!$table instanceof \DOMElement || $this->isPresentationTable($table)) { continue; }
            if ($table->getElementsByTagName('th')->length === 0) {
                yield $this->issueFactory->create($context, $table, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered table has no header cells.', 'Add table header cells (<th>) for data tables, or mark layout tables as presentation.');
            }
        }
    }

    private function isPresentationTable(\DOMElement $table): bool
    {
        $role = strtolower($this->normalizedText($table->getAttribute('role')));
        return in_array($role, ['presentation', 'none'], true);
    }
}
