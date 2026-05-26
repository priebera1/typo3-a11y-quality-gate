<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class PageTitleMissingRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.page_title_missing'; }
    public function getDefaultSeverity(): Severity { return Severity::Warning; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        $title = $context->document->getElementsByTagName('title')->item(0);
        if ($title instanceof \DOMElement && $this->normalizedText($title->textContent) !== '') { return; }
        $html = $context->document->getElementsByTagName('html')->item(0);
        if ($html instanceof \DOMElement) {
            yield $this->issueFactory->create($context, $html, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered page has no title.', 'Add a meaningful <title> for the page.');
        }
    }
}
