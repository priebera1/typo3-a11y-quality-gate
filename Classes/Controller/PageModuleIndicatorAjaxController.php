<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Service\PageModuleIndicatorService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * The Page module indicator's refresh. A scan started elsewhere (for example in the Accessibility module) can still
 * be running when the Page module opens; the indicator then asks here until it has ended. Each answer is the panel
 * rendered for the page and language, as reloading the Page module would show it, so a running frontend scan is read
 * back from the AQG service and its results are stored once it has completed. The site comes from the page, never
 * from the request.
 */
#[AsController]
final class PageModuleIndicatorAjaxController
{
    public function __construct(
        private readonly PageModuleIndicatorService $pageModuleIndicatorService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly RequestParameterService $requestParameterService,
    ) {
    }

    public function stateAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = $this->requestParameterService->getPageUidOrZero($request);
        if ($pageUid <= 0 || !$this->scopeAccessService->canReadPage($pageUid)) {
            return $this->json(['success' => false, 'code' => 'access_denied'], 403);
        }

        $state = $this->pageModuleIndicatorService->buildState(
            $pageUid,
            $this->siteResolutionService->resolveSiteByPageId($pageUid),
            $this->requestParameterService->getLanguageUid($request),
        );
        if ($state === null) {
            return $this->json(['success' => false, 'code' => 'not_found'], 404);
        }

        return $this->json(['success' => true, 'running' => $state['running'], 'html' => $state['html']]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status = 200): ResponseInterface
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }
}
