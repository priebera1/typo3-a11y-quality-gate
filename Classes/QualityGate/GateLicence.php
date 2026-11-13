<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\QualityGate;

use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\QualityGate\Contract\GateLicenceInterface;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use TYPO3\CMS\Core\Site\Entity\Site;

/** The licence state of the site's domain, as PublishHook reads it for the live gate. */
final class GateLicence implements GateLicenceInterface
{
    public function __construct(
        private readonly ProCapabilityService $proCapabilityService,
        private readonly ExtensionContextService $extensionContextService,
    ) {
    }

    public function canBlock(Site $site): bool
    {
        $status = $this->proCapabilityService->getStatus(
            $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$site->getBase()),
            $this->extensionContextService->getExtensionVersion(),
        );

        return (bool)($status->valid ?? false);
    }
}
