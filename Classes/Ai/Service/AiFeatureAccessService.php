<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;

final class AiFeatureAccessService implements AiFeatureAccessServiceInterface
{
    public function __construct(
        private readonly ProStatusResolverService $proStatusResolver,
        private readonly AiFeatureAccessPolicy $policy,
    ) {}

    public function isAvailable(string $siteIdentifier): bool
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            return false;
        }

        try {
            return $this->policy->isAllowed(
                $this->proStatusResolver->resolveForSiteIdentifier($siteIdentifier),
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
