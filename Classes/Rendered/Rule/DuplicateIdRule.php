<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered\Rule;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;

final class DuplicateIdRule extends AbstractRenderedHtmlRule
{
    public function getRuleId(): string { return 'rendered.duplicate_id'; }
    public function getDefaultSeverity(): Severity { return Severity::Warning; }

    public function evaluate(RenderedHtmlContext $context): iterable
    {
        $elementsById = [];
        $nodes = $context->xpath->query('//*[@id]');
        if ($nodes === false) { return; }

        foreach ($nodes as $element) {
            if (!$element instanceof \DOMElement || $this->isInsideTemplate($element)) { continue; }
            $id = trim($element->getAttribute('id'));
            if ($id === '') { continue; }
            $elementsById[$id][] = $element;
        }

        foreach ($elementsById as $id => $elements) {
            if (count($elements) < 2) { continue; }
            foreach ($elements as $element) {
                yield $this->issueFactory->create($context, $element, $this->getRuleId(), $this->getDefaultSeverity(), sprintf('Rendered HTML contains duplicate id "%s".', $id), 'IDs must be unique in the final HTML document.');
            }
        }
    }
}
