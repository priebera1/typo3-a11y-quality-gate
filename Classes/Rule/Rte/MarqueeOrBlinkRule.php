<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Rte;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class MarqueeOrBlinkRule extends AbstractRteRule
{
    public function getRuleId(): string
    {
        return 'rte.marquee_or_blink';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Deprecated moving or blinking content element found.';
    }

    public function getHint(): string
    {
        return 'Remove marquee or blink elements and replace them with accessible static content.';
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];
        $dom = $this->loadDom((string)$context->content);
        $xpath = $this->createXPath($dom);

        $nodes = $xpath->query('//marquee | //blink');

        if ($nodes === false) {
            return [];
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Deprecated <%s> element found.', $node->tagName),
                hint: $this->getHint(),
                contextSnippet: $this->elementSnippet($node),
                contextPath: $this->buildXPath($node),
            );
        }

        return $violations;
    }
}
