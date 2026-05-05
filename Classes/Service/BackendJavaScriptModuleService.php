<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

final class BackendJavaScriptModuleService
{
    public function __construct(
        private readonly ProCapabilityService $proCapabilityService,
        private readonly ExtensionContextService $extensionContextService,
    ) {
    }

    public function loadBackendModule(PageRenderer $pageRenderer, ?Site $site): void
    {
        if ($site === null) {
            $pageRenderer->loadJavaScriptModule(
                '@priebera/a11y-quality-gate/backend/module.free.js'
            );
            return;
        }

        $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase(
            (string)$site->getBase()
        );

        $version = $this->extensionContextService->getExtensionVersion();
        $proStatus = $this->proCapabilityService->getStatus($domain, $version);

        $pageRenderer->loadJavaScriptModule(
            $proStatus->valid
                ? '@priebera/a11y-quality-gate/backend/module.pro.js'
                : '@priebera/a11y-quality-gate/backend/module.free.js'
        );
    }
}