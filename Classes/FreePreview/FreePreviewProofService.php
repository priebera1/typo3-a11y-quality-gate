<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;

final class FreePreviewProofService
{
    public function __construct(
        private readonly InstallationIdentityServiceInterface $installationIdentityService,
        private readonly DomainNormalizer $domainNormalizer,
    ) {
    }

    public function buildForSiteBase(string $siteBase): string
    {
        return $this->build(
            $this->installationIdentityService->getOrCreateInstallationId(),
            $this->domainNormalizer->normalizeFromSiteBase($siteBase),
        );
    }

    public function build(string $installationId, string $normalizedDomain): string
    {
        $input = "aqg-free-preview:v1\n"
            . trim($installationId)
            . "\n"
            . $this->domainNormalizer->normalizeHost($normalizedDomain);
        $digest = hash('sha256', $input, true);

        return rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }
}
