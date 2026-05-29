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
            if (!$iframe instanceof \DOMElement || $this->isInsideTemplate($iframe) || $this->isAriaHidden($iframe) || $this->isNonUserFacingIframe($iframe)) {
                continue;
            }

            if (trim($iframe->getAttribute('title')) === '') {
                yield $this->issueFactory->create($context, $iframe, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered iframe has no title.', 'Add a short title attribute that describes the iframe content.');
            }
        }
    }

    private function isNonUserFacingIframe(\DOMElement $iframe): bool
    {
        if ($this->isInsideNoscript($iframe) || $this->isRenderedHidden($iframe)) {
            return true;
        }

        $width = trim($iframe->getAttribute('width'));
        $height = trim($iframe->getAttribute('height'));
        return in_array($width, ['0', '0px'], true) && in_array($height, ['0', '0px'], true);
    }

    private function isInsideNoscript(\DOMElement $element): bool
    {
        $node = $element->parentNode;
        while ($node instanceof \DOMElement) {
            if (strtolower($node->tagName) === 'noscript') {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
    }
}
