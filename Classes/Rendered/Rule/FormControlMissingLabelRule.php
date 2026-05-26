<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class FormControlMissingLabelRule extends AbstractRenderedHtmlRule
{
    private const SKIPPED_INPUT_TYPES = ['hidden', 'submit', 'button', 'reset', 'image'];

    public function getRuleId(): string { return 'rendered.form_control_missing_label'; }
    public function getDefaultSeverity(): Severity { return Severity::Critical; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        foreach (['input', 'select', 'textarea'] as $tagName) {
            foreach ($context->document->getElementsByTagName($tagName) as $control) {
                if (!$control instanceof \DOMElement || $this->isAriaHidden($control)) { continue; }
                $type = strtolower(trim($control->getAttribute('type')));
                if ($tagName === 'input' && in_array($type, self::SKIPPED_INPUT_TYPES, true)) { continue; }
                if ($this->hasFormControlLabel($control, $context)) { continue; }

                yield $this->issueFactory->create($context, $control, $this->getRuleId(), $this->getDefaultSeverity(), 'Rendered form control has no accessible label.', 'Add a visible <label>, aria-label, or valid aria-labelledby reference.');
            }
        }
    }

    private function hasFormControlLabel(\DOMElement $control, RenderedHtmlContext $context): bool
    {
        if ($this->hasAssociatedLabel($control, $context)) {
            return true;
        }

        if (trim($control->getAttribute('aria-label')) !== '') {
            return true;
        }

        if ($this->resolveAriaLabelledByText($control, $context->xpath) !== '') {
            return true;
        }

        return trim($control->getAttribute('title')) !== '';
    }

    private function labelHasText(\DOMElement $label): bool
    {
        $clone = $label->cloneNode(true);
        if (!$clone instanceof \DOMElement) {
            return false;
        }

        foreach (['input', 'select', 'textarea', 'button'] as $tagName) {
            while (($controls = $clone->getElementsByTagName($tagName))->length > 0) {
                $control = $controls->item(0);
                if ($control?->parentNode !== null) {
                    $control->parentNode->removeChild($control);
                } else {
                    break;
                }
            }
        }

        return $this->normalizedText($clone->textContent) !== '';
    }

    private function hasAssociatedLabel(\DOMElement $control, RenderedHtmlContext $context): bool
    {
        $id = trim($control->getAttribute('id'));
        if ($id !== '') {
            $labels = $context->xpath->query('//label[@for=' . $this->xpathLiteral($id) . ']');
            if ($labels !== false) {
                foreach ($labels as $label) {
                    if ($label instanceof \DOMElement && $this->labelHasText($label)) { return true; }
                }
            }
        }

        $node = $control->parentNode;
        while ($node instanceof \DOMElement) {
            if (strtolower($node->tagName) === 'label' && $this->labelHasText($node)) { return true; }
            $node = $node->parentNode;
        }

        return false;
    }
}
