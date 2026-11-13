<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

/** The evaluated readiness line of one site: item => ok | warn | missing, in AiReadinessPolicy::ITEMS order. */
final readonly class AiReadiness
{
    /** @param array<string,string> $items */
    public function __construct(
        public array $items,
    ) {}

    public function isReady(): bool
    {
        return $this->firstMissing() === null;
    }

    /** The first item that keeps AI from editors, or null. Warnings never block. */
    public function firstMissing(): ?string
    {
        foreach ($this->items as $item => $state) {
            if ($state === AiReadinessPolicy::MISSING) {
                return $item;
            }
        }

        return null;
    }

    public function state(string $item): string
    {
        return $this->items[$item] ?? AiReadinessPolicy::MISSING;
    }
}
