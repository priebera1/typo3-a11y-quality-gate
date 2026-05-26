<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class HtmlLangMissingRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.html_lang_missing'; }
    public function getDefaultSeverity(): Severity { return Severity::Warning; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        $html = $context->document->getElementsByTagName('html')->item(0);
        if (!$html instanceof \DOMElement) { return; }
        if (trim($html->getAttribute('lang')) === '') {
            yield $this->issueFactory->create($context, $html, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered HTML document has no lang attribute.', 'Set the lang attribute on the <html> element according to the page language.');
        }
    }
}
