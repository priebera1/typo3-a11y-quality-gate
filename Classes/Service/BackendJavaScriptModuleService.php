<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;

final class BackendJavaScriptModuleService
{
    public function __construct(
    ) {
    }

    public function loadBackendModule(PageRenderer $pageRenderer, ?Site $site): void
    {
        $pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/backend.css');

        if ($site === null) {
            $pageRenderer->loadJavaScriptModule(
                '@priebera/a11y-quality-gate/backend/module.free.js'
            );
            return;
        }

        $pageRenderer->loadJavaScriptModule(
            '@priebera/a11y-quality-gate/backend/module.pro.js'
        );
    }
}
