<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\EventListener;

use Priebera\A11yQualityGate\Service\PageModuleIndicatorService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Core\Page\PageRenderer;

final class ModifyPageLayoutContentListener
{
    public function __construct(
        private readonly RequestParameterService $requestParameterService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly PageModuleIndicatorService $pageModuleIndicatorService,
        private readonly PageRenderer $pageRenderer,
    ) {
    }

    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $request = $event->getRequest();
        $pageUid = $this->requestParameterService->getPageUidOrZero($request);
        if ($pageUid <= 0) {
            return;
        }

        $site = $this->siteResolutionService->resolveSiteForBackendRequest($request, $pageUid);
        if ($site === null) {
            return;
        }

        $languageUid = $this->requestParameterService->getLanguageUid($request);

        $content = $this->pageModuleIndicatorService->buildForPage($pageUid, $site, $languageUid);
        if ($content === '') {
            return;
        }

        $this->pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/page-module-indicator.css');
        $this->pageRenderer->loadJavaScriptModule('@priebera/a11y-quality-gate/backend/page-module-indicator.js');

        $event->addHeaderContent($content);
    }
}
