<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule;

final class RuleRegistry
{
    /** @var array<string, RuleInterface> */
    private array $rules = [];

    /**
     * @param iterable<RuleInterface> $rules
     */
    public function __construct(iterable $rules)
    {
        foreach ($rules as $rule) {
            $ruleId = $rule->getRuleId();

            if (isset($this->rules[$ruleId])) {
                throw new \RuntimeException(
                    sprintf('Duplicate accessibility rule ID detected: "%s".', $ruleId),
                    1741431001
                );
            }

            $this->rules[$ruleId] = $rule;
        }
    }

    /**
     * @return RuleInterface[]
     */
    public function getRulesFor(CheckContext $context): array
    {
        return array_values(
            array_filter(
                $this->rules,
                static fn(RuleInterface $rule): bool => $rule->supports($context)
            )
        );
    }

    public function getById(string $ruleId): ?RuleInterface
    {
        return $this->rules[$ruleId] ?? null;
    }

    /**
     * @return RuleInterface[]
     */
    public function getAll(): array
    {
        return array_values($this->rules);
    }

    /**
     * @return string[]
     */
    public function getAllIds(): array
    {
        return array_keys($this->rules);
    }
}
