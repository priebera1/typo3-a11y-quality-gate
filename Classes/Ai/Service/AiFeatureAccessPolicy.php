<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

final class AiFeatureAccessPolicy
{
    public function isAllowed(object $status): bool
    {
        $plan = strtolower(trim((string)($status->plan ?? '')));

        return (bool)($status->valid ?? false)
            && !(bool)($status->isTrial ?? false)
            && in_array($plan, ['pro', 'agency'], true);
    }
}
