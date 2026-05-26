<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\FormDefinitionResolver;

final class FormFieldLabelMissingRule implements RuleInterface
{
    private const NON_INPUT_TYPES = [
        'hidden',
        'honeypot',
        'statictext',
        'contentelement',
        'fieldset',
        'gridrow',
        'page',
        'summarypage',
        'button',
        'submitbutton',
        'resetbutton',
        'imagebutton',
    ];

    private const USER_INPUT_TYPES = [
        'text',
        'textfield',
        'textarea',
        'email',
        'emailfield',
        'telephone',
        'telephonefield',
        'number',
        'numberfield',
        'password',
        'date',
        'datefield',
        'singleselect',
        'singleSelect',
        'multiselect',
        'multiSelect',
        'checkbox',
        'multicheckbox',
        'multiCheckbox',
        'radiobutton',
        'radioButton',
        'fileupload',
        'fileUpload',
    ];

    public function __construct(
        private readonly FormDefinitionResolver $formDefinitionResolver,
    ) {
    }

    public function getRuleId(): string
    {
        return 'structured.form_field_label_missing';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Form field has no label.';
    }

    public function getHint(): string
    {
        return 'Add a visible label to the form field in the EXT:form editor. Every user input field must have a label to be accessible.';
    }

    public function supports(CheckContext $context): bool
    {
        return $context->sourceTable === Tables::TT_CONTENT
            && $context->sourceField === 'pi_flexform'
            && strtolower(trim($context->cType)) === 'form_formframework'
            && is_string($context->content)
            && trim($context->content) !== '';
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
            if (!$this->isUserInputElement($element)) {
                continue;
            }

            if ($this->hasLabel($element)) {
                continue;
            }

            $identifier = (string)($element['identifier'] ?? 'unknown');
            $type = (string)($element['type'] ?? 'unknown');

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Form field "%s" (type: %s) has no label.', $identifier, $type),
                hint: $this->getHint(),
                contextSnippet: sprintf('form element: %s, type: %s', $identifier, $type),
                contextPath: $context->contextPath . ' > form:' . $identifier,
            );
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function isUserInputElement(array $element): bool
    {
        $type = strtolower((string)($element['type'] ?? ''));

        if (in_array($type, self::NON_INPUT_TYPES, true)) {
            return false;
        }

        return in_array($type, array_map('strtolower', self::USER_INPUT_TYPES), true);
    }

    /**
     * @param array<string, mixed> $element
     */
    private function hasLabel(array $element): bool
    {
        if (trim((string)($element['label'] ?? '')) !== '') {
            return true;
        }

        $properties = is_array($element['properties'] ?? null) ? $element['properties'] : [];
        return trim((string)($properties['label'] ?? '')) !== '';
    }
}
