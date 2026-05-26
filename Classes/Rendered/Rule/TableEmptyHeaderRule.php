<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class TableEmptyHeaderRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.table_empty_header'; }
    public function getDefaultSeverity(): Severity { return Severity::Warning; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach ($context->document->getElementsByTagName('th') as $th) {
            if (!$th instanceof \DOMElement || $this->isAriaHidden($th)) { continue; }
            if (!$this->hasMeaningfulText($th)) {
                yield $this->issueFactory->create($context, $th, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered table header cell is empty.', 'Add meaningful text to the table header cell or remove the empty header.');
            }
        }
    }
}
