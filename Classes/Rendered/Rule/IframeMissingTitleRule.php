<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class IframeMissingTitleRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.iframe_missing_title'; }
    public function getDefaultSeverity(): Severity { return Severity::Critical; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach ($context->document->getElementsByTagName('iframe') as $iframe) {
            if (!$iframe instanceof \DOMElement || $this->isAriaHidden($iframe)) { continue; }
            if (trim($iframe->getAttribute('title')) === '') {
                yield $this->issueFactory->create($context, $iframe, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered iframe has no title.', 'Add a short title attribute that describes the iframe content.');
            }
        }
    }
}
