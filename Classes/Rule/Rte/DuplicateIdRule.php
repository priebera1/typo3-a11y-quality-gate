<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class DuplicateIdRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.duplicate_id';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Duplicate id attribute found.';
    }

    public function getHint(): string
    {
        return 'Make sure each id value is unique within the edited HTML content.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $nodes = $xpath->query('//*[@id]');

        if ($nodes === false) {
            return [];
        }

        $seen = [];

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $id = trim($node->getAttribute('id'));
            if ($id === '') {
                continue;
            }

            if (!isset($seen[$id])) {
                $seen[$id] = 1;
                continue;
            }

            $seen[$id]++;

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Duplicate id "%s" found.', $id),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($node),
                contextPath: $this->buildXPath($node),
            );
        }

        return $violations;
    }
}
