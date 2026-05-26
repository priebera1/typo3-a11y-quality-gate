<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class EmptyButtonRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.empty_button'; }
    public function getDefaultSeverity(): Severity { return Severity::Critical; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach ($context->document->getElementsByTagName('button') as $button) {
            if (!$button instanceof \DOMElement || $this->isAriaHidden($button)) { continue; }
            if (!$this->hasAccessibleName($button, $context->xpath)) {
                yield $this->issueFactory->create($context, $button, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered button has no accessible name.', 'Add visible button text or an accessible label such as aria-label.');
            }
        }
    }
}
