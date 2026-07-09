<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service\Contract;

interface RuleMetadataPresentationServiceInterface
{
    /** @return array<string, mixed> */
    public function present(array $issue, string $language = 'en'): array;
}
