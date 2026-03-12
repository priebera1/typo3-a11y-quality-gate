<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class IframeMissingTitleRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.iframe_missing_title';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Iframe is missing a title attribute.';
    }

    public function getHint(): string
    {
        return 'Add a meaningful title attribute describing the embedded content.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom((string)$context->content);

        foreach ($dom->getElementsByTagName('iframe') as $iframe) {
            if (!$iframe instanceof \DOMElement) {
                continue;
            }

            if ($this->hasNonEmptyAttribute($iframe, 'title')) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: $this->getMessage(),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($iframe),
                contextPath: $this->buildXPath($iframe),
            );
        }

        return $violations;
    }
}
