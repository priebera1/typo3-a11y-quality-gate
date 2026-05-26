<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\FormDefinitionResolver;

final class FormAutocompleteMissingRule implements RuleInterface
{
    private const PERSONAL_FIELD_CONTEXT_BLACKLIST = [
        'company',
        'organization',
        'organisation',
        'product',
        'event',
        'item',
        'search',
        'subject',
        'title',
        'topic',
        'category',
    ];

    /**
     * @param list<string> $personalFieldPatterns
     */
    public function __construct(
        private readonly FormDefinitionResolver $formDefinitionResolver,
        private readonly array $personalFieldPatterns = ['/name/i', '/email/i', '/phone/i', '/tel/i', '/address/i', '/zip/i', '/postal/i', '/country/i', '/birth/i', '/city/i'],
    ) {
    }

    public function getRuleId(): string
    {
        return 'structured.form_autocomplete_missing';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Personal data field is missing an autocomplete attribute.';
    }

    public function getHint(): string
    {
        return 'Add the appropriate autocomplete attribute to personal information fields, for example email, given-name or tel.';
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
            if (!$this->isSupportedInputElement($element)) {
                continue;
            }

            $properties = isset($element['properties']) && is_array($element['properties']) ? $element['properties'] : [];
            if (trim((string)($properties['autocomplete'] ?? '')) !== '') {
                continue;
            }

            $identifier = (string)($element['identifier'] ?? '');
            $label = (string)($element['label'] ?? '');
            $fieldContext = $identifier . ' ' . $label;
            if ($this->isBlacklistedFieldContext($fieldContext) || !$this->isPersonalField($fieldContext)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Form field "%s" looks like a personal data field but has no autocomplete attribute.', $identifier !== '' ? $identifier : 'unknown'),
                hint: $this->getHint(),
                contextSnippet: sprintf('form element: %s, type: %s', $identifier !== '' ? $identifier : 'unknown', (string)($element['type'] ?? 'unknown')),
                contextPath: $context->contextPath . ' > form:' . ($identifier !== '' ? $identifier : 'unknown'),
            );
        }

        return $violations;
    }

    /**
     * @param array<string, mixed> $element
     */
    private function isSupportedInputElement(array $element): bool
    {
        $type = strtolower((string)($element['type'] ?? ''));

        return in_array($type, [
            'textfield',
            'email',
            'emailfield',
            'telephone',
            'telephonefield',
        ], true);
    }


    private function isBlacklistedFieldContext(string $value): bool
    {
        $value = mb_strtolower($value);
        foreach (self::PERSONAL_FIELD_CONTEXT_BLACKLIST as $blacklistedTerm) {
            if (str_contains($value, $blacklistedTerm)) {
                return true;
            }
        }

        return false;
    }

    private function isPersonalField(string $value): bool
    {
        foreach ($this->personalFieldPatterns as $pattern) {
            if (@preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }
}
