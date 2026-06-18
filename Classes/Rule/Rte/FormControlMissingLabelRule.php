<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

final class FormControlMissingLabelRule extends AbstractRteRule
{
    public function __construct(
        private readonly RuleMetadataPresentationService $ruleMetadataPresentationService,
    ) {
    }
    private const SKIPPED_INPUT_TYPES = ['hidden', 'button', 'submit', 'reset', 'image'];

    public function getRuleId(): string
    {
        return 'rte.form_control_missing_label';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return $this->ruleMetadataPresentationService->friendlyTitleForRule($this->getRuleId(), '', 'en');
    }

    public function getHint(): string
    {
        $metadata = $this->ruleMetadataPresentationService->present(['rule_id' => $this->getRuleId()], 'en');
        return (string)($metadata['howToFix'] ?? '');
    }

    /** @return RuleViolation[] */
    public function check(CheckContext $context): array
    {
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $controls = $xpath->query('//input | //select | //textarea');
        if ($controls === false) {
            return [];
        }

        $violations = [];
        foreach ($controls as $control) {
            if (!$control instanceof \DOMElement) {
                continue;
            }

            if ($control->hasAttribute('hidden') || strtolower(trim($control->getAttribute('aria-hidden'))) === 'true') {
                continue;
            }

            if (strtolower($control->tagName) === 'input') {
                $type = strtolower(trim($control->getAttribute('type')));
                if (in_array($type, self::SKIPPED_INPUT_TYPES, true)) {
                    continue;
                }
            }

            if ($this->hasAssociatedLabel($control, $xpath)
                || $this->hasNonEmptyAttribute($control, 'aria-label')
                || $this->hasValidAriaLabelledBy($control, $xpath)
                || $this->hasNonEmptyAttribute($control, 'title')
            ) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($control),
                contextPath: $this->buildXPath($control),
            );
        }

        return $violations;
    }

    private function hasAssociatedLabel(\DOMElement $control, \DOMXPath $xpath): bool
    {
        $id = trim($control->getAttribute('id'));
        if ($id !== '') {
            $labels = $xpath->query('//label[@for=' . $this->xpathLiteral($id) . ']');
            if ($labels !== false) {
                foreach ($labels as $label) {
                    if ($label instanceof \DOMElement && $this->labelHasText($label)) {
                        return true;
                    }
                }
            }
        }

        $node = $control->parentNode;
        while ($node instanceof \DOMElement) {
            if (strtolower($node->tagName) === 'label' && $this->labelHasText($node)) {
                return true;
            }
            $node = $node->parentNode;
        }

        return false;
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
                if ($control?->parentNode === null) {
                    break;
                }
                $control->parentNode->removeChild($control);
            }
        }

        return $this->normalizedText($clone->textContent) !== '';
    }
}
