<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\DictionaryRegistry;
use Priebera\A11yQualityGate\Service\PhraseMatcher;

final class NonDescriptiveLinkRule extends AbstractRteRule
{
    public function __construct(
        private readonly DictionaryRegistry $dictionaryRegistry,
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.non_descriptive_link';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Link text is not descriptive.';
    }

    public function getHint(): string
    {
        return 'Replace generic link text with text that describes the destination or action.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $phrases = $this->dictionaryRegistry->resolveForContext($this->getRuleId(), $context);
        if ($phrases === []) {
            return [];
        }

        $violations = [];
        $dom = $this->loadDom($context->content);

        foreach ($dom->getElementsByTagName('a') as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            if (!$link->hasAttribute('href')) {
                continue;
            }

            $visibleText = PhraseMatcher::normalize($link->textContent);
            if ($visibleText === '') {
                continue;
            }

            $ariaLabel = PhraseMatcher::normalize($link->getAttribute('aria-label'));
            $textToCheck = $ariaLabel !== '' ? $ariaLabel : $visibleText;

            if (!PhraseMatcher::isExactMatch($textToCheck, $phrases)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Link text "%s" is not descriptive.', $this->truncate($link->textContent, 80)),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($link),
                contextPath: $this->buildXPath($link),
            );
        }

        return $violations;
    }
}
