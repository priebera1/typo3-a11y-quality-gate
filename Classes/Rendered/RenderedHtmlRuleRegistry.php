<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

final class RenderedHtmlRuleRegistry
{
    /** @var RenderedHtmlRuleInterface[] */
    private array $rules;

    /**
     * @param iterable<RenderedHtmlRuleInterface> $rules
     */
    public function __construct(iterable $rules)
    {
        $this->rules = is_array($rules) ? $rules : iterator_to_array($rules);
    }

    /**
     * @return RenderedHtmlRuleInterface[]
     */
    public function getRulesFor(RenderedHtmlContext $context): array
    {
        return array_values(array_filter(
            $this->rules,
            static fn(RenderedHtmlRuleInterface $rule): bool => $rule->supports($context)
        ));
    }
}
