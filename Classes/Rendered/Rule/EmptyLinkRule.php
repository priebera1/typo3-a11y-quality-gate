<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class EmptyLinkRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.empty_link'; }
    public function getDefaultSeverity(): Severity { return Severity::Critical; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach ($context->document->getElementsByTagName('a') as $link) {
            if (!$link instanceof \DOMElement || $this->isInsideTemplate($link) || $this->isAriaHidden($link)) { continue; }
            if (!$this->hasAccessibleName($link, $context->xpath)) {
                yield $this->issueFactory->create($context, $link, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered link has no accessible name.', 'Add visible link text or an accessible label that describes the destination.');
            }
        }
    }
}
