<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class EmptyHeadingRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.empty_heading'; }
    public function getDefaultSeverity(): Severity { return Severity::Critical; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach (['h1','h2','h3','h4','h5','h6'] as $tagName) {
            foreach ($context->document->getElementsByTagName($tagName) as $heading) {
                if (!$heading instanceof \DOMElement || $this->isAriaHidden($heading)) { continue; }
                if (!$this->hasMeaningfulText($heading)) {
                    yield $this->issueFactory->create($context, $heading, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered heading is empty.', 'Remove the empty heading or add meaningful heading text.');
                }
            }
        }
    }
}
