<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\FormDefinitionResolver;

final class FormPlaceholderAsLabelRule implements RuleInterface
{
    public function __construct(
        private readonly FormDefinitionResolver $formDefinitionResolver,
    ) {
    }

    public function getRuleId(): string
    {
        return 'structured.form_placeholder_as_label';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Form field uses placeholder text instead of a label.';
    }

    public function getHint(): string
    {
        return 'Add a visible label to the form field. Do not rely on placeholder text as the only identification.';
    }

    public function supports(CheckContext $context): bool
    {
        return $context->sourceTable === Tables::TT_CONTENT
            && $context->sourceField === 'pi_flexform'
            && $this->isFormFrameworkContentElement($context)
            && is_string($context->content)
            && trim($context->content) !== '';
    }

    private function isFormFrameworkContentElement(CheckContext $context): bool
    {
        return strtolower(trim($context->cType)) === 'form_formframework';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $definition = $this->formDefinitionResolver->resolveFromFlexForm((string)$context->content);
        if ($definition === null) {
            return [];
        }

        $violations = [];
        foreach ($this->formDefinitionResolver->flattenFormElements($definition) as $element) {
            $label = trim((string)($element['label'] ?? ''));
            $properties = isset($element['properties']) && is_array($element['properties']) ? $element['properties'] : [];
            $placeholder = trim((string)($properties['placeholder'] ?? ''));

            if ($label !== '' || $placeholder === '') {
                continue;
            }

            $identifier = (string)($element['identifier'] ?? 'unknown');
            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Form field "%s" uses placeholder text without a visible label.', $identifier),
                hint: $this->getHint(),
                contextSnippet: sprintf('form element: %s, placeholder: "%s"', $identifier, mb_substr($placeholder, 0, 120)),
                contextPath: $context->contextPath . ' > form:' . $identifier,
            );
        }

        return $violations;
    }
}
