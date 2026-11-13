<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

/**
 * The one element a live finding is about, identified so an editor can find exactly this element again in its own
 * content: tag name, its index among all elements with that tag in document order ($occurrence, 0-based) and the
 * total number of such elements, plus the element's attributes and text as a signature. Identical duplicates differ
 * by $occurrence; an editor that counts a different $total parsed the content differently and must not apply.
 *
 * $unavailableReason is set when the element was found but no safe change can be offered (template placeholders,
 * nested markup inside a text target, oversized values); the finding then keeps its manual guidance only.
 */
final readonly class EditorFindingTarget
{
    /** @param array<string,string> $attributes */
    public function __construct(
        public string $ruleId,
        public string $kind,
        public string $operation,
        public string $attribute,
        public string $tag,
        public int $occurrence,
        public int $total,
        public string $contextPath,
        public array $attributes,
        public string $text,
        public bool $hasChildElements,
        public string $currentValue,
        public int $headingLevel = 0,
        public int $targetHeadingLevel = 0,
        public string $unavailableReason = '',
        public ?\DOMElement $element = null,
    ) {}

    public function isActionable(): bool
    {
        return $this->unavailableReason === '';
    }

    /** @return array<string,mixed> the editor-side contract; never contains the DOM element */
    public function toArray(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'kind' => $this->kind,
            'operation' => $this->operation,
            'attribute' => $this->attribute,
            'tag' => $this->tag,
            'occurrence' => $this->occurrence,
            'total' => $this->total,
            'contextPath' => $this->contextPath,
            'element' => [
                'tag' => $this->tag,
                'attributes' => (object)$this->attributes,
                'text' => $this->text,
                'hasChildElements' => $this->hasChildElements,
            ],
            'currentValue' => $this->currentValue,
            'headingLevel' => $this->headingLevel,
            'targetHeadingLevel' => $this->targetHeadingLevel,
            'unavailableReason' => $this->unavailableReason,
        ];
    }
}
