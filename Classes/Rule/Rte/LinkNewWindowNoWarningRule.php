<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\DictionaryRegistry;
use Priebera\A11yQualityGate\Service\PhraseMatcher;

final class LinkNewWindowNoWarningRule extends AbstractRteRule
{
    public function __construct(
        private readonly DictionaryRegistry $dictionaryRegistry,
    ) {
    }

    public function getRuleId(): string
    {
        return 'rte.link_new_window_no_warning';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Link opens in a new window or tab without warning the user.';
    }

    public function getHint(): string
    {
        return 'Add a visible or accessible hint such as "opens in a new window or tab" so users know what to expect.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $phrases = $this->dictionaryRegistry->resolveForContext($this->getRuleId(), $context);
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $links = $xpath->query('//a[@target="_blank"]');

        if ($links === false) {
            return [];
        }

        foreach ($links as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }

            if ($this->hasWindowWarning($link, $xpath, $phrases)) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($link),
                contextPath: $this->buildXPath($link),
            );
        }

        return $violations;
    }

    /**
     * @param list<string> $phrases
     */
    private function hasWindowWarning(\DOMElement $link, \DOMXPath $xpath, array $phrases): bool
    {
        if ($phrases === []) {
            return false;
        }

        $textParts = [
            $link->textContent,
            $link->getAttribute('aria-label'),
            $link->getAttribute('title'),
        ];

        $childrenWithAriaLabel = $xpath->query('.//*[@aria-label]', $link);
        if ($childrenWithAriaLabel !== false) {
            foreach ($childrenWithAriaLabel as $child) {
                if ($child instanceof \DOMElement) {
                    $textParts[] = $child->getAttribute('aria-label');
                }
            }
        }

        return PhraseMatcher::isWordBoundaryMatch(
            PhraseMatcher::normalize(implode(' ', array_filter($textParts))),
            $phrases
        );
    }
}
