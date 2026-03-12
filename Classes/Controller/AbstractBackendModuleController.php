<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Service\BackendContextService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

abstract class AbstractBackendModuleController
{
    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly UriBuilder $uriBuilder,
        protected readonly IconFactory $iconFactory,
        protected readonly SiteFinder $siteFinder,
        protected readonly BackendContextService $backendContextService,
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
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $bodyParams = is_array($parsedBody) ? $parsedBody : [];

        $allowedKeys = [
            'id',
            'site',
            'pageUid',
            'status',
            'severity',
            'page',
        ];

        $parameters = [];

        foreach ($allowedKeys as $key) {
            $value = $queryParams[$key] ?? $bodyParams[$key] ?? null;

            if ($value === '' || $value === null) {
                continue;
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }

    protected function resolveSiteFromRequest(ServerRequestInterface $request): ?Site
    {
        $queryParams = $request->getQueryParams();

        $id = (int)($queryParams['id'] ?? 0);
        if ($id > 0) {
            try {
                return $this->siteFinder->getSiteByPageId($id);
            } catch (\Throwable) {
            }
        }

        $siteIdentifier = trim((string)($queryParams['site'] ?? ''));
        if ($siteIdentifier !== '') {
            try {
                return $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            } catch (\Throwable) {
            }
        }

        $sites = $this->siteFinder->getAllSites();

        return $sites !== [] ? reset($sites) : null;
    }

    protected function resolveSiteForPage(ServerRequestInterface $request, int $pageUid): ?Site
    {
        if ($pageUid > 0) {
            try {
                return $this->siteFinder->getSiteByPageId($pageUid);
            } catch (\Throwable) {
            }
        }

        return $this->resolveSiteFromRequest($request);
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
