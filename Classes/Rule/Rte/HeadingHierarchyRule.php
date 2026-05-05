<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class HeadingHierarchyRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.heading_hierarchy_jump';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Heading levels are not sequential.';
    }

    public function getHint(): string
    {
        return 'Heading levels should increase by one at a time, for example H2 to H3, not H2 to H4.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom($context->content);
        $xpath = $this->createXPath($dom);
        $headingNodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');

        if ($headingNodes === false || $headingNodes->length < 2) {
            return [];
        }

        $previousLevel = null;

        foreach ($headingNodes as $headingNode) {
            if (!$headingNode instanceof \DOMElement) {
                continue;
            }

            $level = (int)substr(strtolower($headingNode->tagName), 1);

            if ($previousLevel !== null && $level > $previousLevel + 1) {
                $violations[] = new RuleViolation(
                    ruleId: $this->getRuleId(),
                    severity: $this->getDefaultSeverity(),
                    message: sprintf(
                        'Heading jumps from H%d to H%d. H%d was skipped.',
                        $previousLevel,
                        $level,
                        $previousLevel + 1
                    ),
                    hint: $this->getHint(),
                    contextSnippet: $this->elementSnippet($headingNode),
                    contextPath: $this->buildXPath($headingNode),
                );
            }

            $previousLevel = $level;
        }

        return $violations;
    }
}