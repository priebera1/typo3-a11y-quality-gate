<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

abstract class AbstractBackendModuleController
{
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly UriBuilder $uriBuilder,
        protected readonly IconFactory $iconFactory,
        protected readonly BackendContextService $backendContextService,
        protected readonly SiteResolutionService $siteResolutionService,
        protected readonly RequestParameterService $requestParameterService,
    ) {
    }

    protected function createModuleTemplate(ServerRequestInterface $request): ModuleTemplate
    {
        return $this->moduleTemplateFactory->create($request);
    }

    protected function translate(string $key, string $file = 'locallang.xlf'): string
    {
        return $this->backendContextService->translate($key, $file);
    }

    protected function addFlashMessage(
        string $message,
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK,
        string $title = ''
    ): void {
        $this->backendContextService->addFlashMessage($message, $severity, $title);
    }

    protected function getBackendUserUid(): int
    {
        return $this->backendContextService->getBackendUserUid();
    }

    protected function getA11yModuleReturnParameters(ServerRequestInterface $request): array
    {
        return $this->requestParameterService->getA11yModuleReturnParameters($request);
    }

    protected function resolveSiteFromRequest(ServerRequestInterface $request): ?Site
    {
        return $this->siteResolutionService->resolveSiteForBackendRequest($request);
    }

    protected function resolveSiteForPage(ServerRequestInterface $request, int $pageUid): ?Site
    {
        return $this->siteResolutionService->resolveSiteForBackendRequest($request, $pageUid);
    }

    protected function buildRouteUrl(string $route, array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters);
    }

    protected function setModuleTitle(ModuleTemplate $moduleTemplate, string $mainLabelKey, string $subLabelKey): void
    {
        $moduleTemplate->setTitle(
            $this->translate($mainLabelKey),
            $this->translate($subLabelKey)
        );
    }
}