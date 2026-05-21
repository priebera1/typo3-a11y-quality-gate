<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\RedirectResponse;
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

    /**
     * @return array{uid:int,username:string,name:string}
     */
    protected function getBackendUserSnapshot(): array
    {
        return $this->backendContextService->getBackendUserSnapshot();
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

    protected function ensureBackendUserAccess(
        AccessControlService $accessControlService,
        ?string $permission,
        ServerRequestInterface $request,
    ): ?ResponseInterface {
        $backendUser = $this->backendContextService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication) {
            return $this->accessDeniedRedirect($request);
        }

        if ($permission === 'scanAll' && !$accessControlService->canShowScanAll($backendUser)) {
            return $this->accessDeniedRedirect($request);
        }

        if ($permission === 'scanNow' && !$accessControlService->canShowScanNow($backendUser)) {
            return $this->accessDeniedRedirect($request);
        }

        if ($permission === 'editRecord' && !$accessControlService->canEditRecord($backendUser)) {
            return $this->accessDeniedRedirect($request);
        }

        if ($permission === 'settings' && !$accessControlService->canShowSettings($backendUser)) {
            return $this->accessDeniedRedirect($request);
        }

        return null;
    }

    private function accessDeniedRedirect(ServerRequestInterface $request): ResponseInterface
    {
        $this->addFlashMessage('Access denied.', ContextualFeedbackSeverity::ERROR, 'Accessibility');

        $referer = trim($request->getHeaderLine('referer'));
        if ($referer !== '' && $this->isSameOriginUrl($referer, $request)) {
            return new RedirectResponse($referer, 302);
        }

        return new RedirectResponse($this->buildRouteUrl('web_a11y'), 302);
    }

    private function isSameOriginUrl(string $url, ServerRequestInterface $request): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $host = strtolower((string)($parts['host'] ?? ''));
        if ($host === '') {
            return str_starts_with($url, '/typo3/');
        }

        $uri = $request->getUri();
        if ($host !== strtolower($uri->getHost())) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== '' && $scheme !== strtolower($uri->getScheme())) {
            return false;
        }

        $port = (int)($parts['port'] ?? 0);
        return $port === 0 || $port === $uri->getPort();
    }

    protected function hasExplicitLanguageParameter(ServerRequestInterface $request): bool
    {
        return $this->requestParameterService->hasLanguageParameter($request->getQueryParams());
    }

    protected function resolveCurrentLanguageUid(ServerRequestInterface $request, array $availableLanguages): int
    {
        $queryParams = $request->getQueryParams();
        $backendUser = $this->backendContextService->getBackendUser();

        if ($this->requestParameterService->hasLanguageParameter($queryParams)) {
            $languageUid = $this->requestParameterService->getLanguageUidFromParameters(
                $queryParams,
                [],
                0,
                false,
            );

            if ($this->containsLanguage($availableLanguages, $languageUid)) {
                $backendUser?->setAndSaveSessionData('tx_a11y_quality_gate_language', $languageUid);
                return $languageUid;
            }
        }

        $sessionLanguage = $backendUser?->getSessionData('tx_a11y_quality_gate_language');
        $languageUid = $this->requestParameterService->getLanguageUidFromParameters(
            ['language' => $sessionLanguage],
            [],
            0,
            false,
        );

        if ($this->containsLanguage($availableLanguages, $languageUid)) {
            return $languageUid;
        }

        return 0;
    }

    protected function buildOverviewLanguageOptions(
        ServerRequestInterface $request,
        IssueRepository $issueRepository,
        string $siteIdentifier,
        array $availableLanguages,
        int $currentLanguageUid,
    ): array {
        $baseParameters = $this->buildLanguageUrlParameters($request, [
            'localPage',
            'remotePage',
            'remoteFailedPage',
        ]);
        if ($siteIdentifier !== '') {
            $baseParameters['site'] = $siteIdentifier;
        }

        $options = [];
        foreach ($availableLanguages as $language) {
            $parameters = $baseParameters;
            $languageUid = (int)$language['languageId'];
            $parameters['language'] = $languageUid;

            $pages = $siteIdentifier !== ''
                ? $issueRepository->countOpenPageStatsForSite($siteIdentifier, '', $languageUid)
                : 0;

            $options[] = $language + [
                'active' => $currentLanguageUid === $languageUid,
                'url' => $this->buildRouteUrl('web_a11y', $parameters),
                'pages' => $pages,
                'hasResults' => $pages > 0,
                'pageLabel' => $pages === 1 ? '1 page' : ($pages . ' pages'),
            ];
        }

        return $options;
    }

    protected function buildPageDetailLanguageOptions(
        ServerRequestInterface $request,
        IssueRepository $issueRepository,
        string $siteIdentifier,
        int $pageUid,
        array $availableLanguages,
        int $currentLanguageUid,
    ): array {
        $baseParameters = $this->buildLanguageUrlParameters($request, ['page']);
        $baseParameters['pageUid'] = $pageUid;
        $baseParameters['id'] = $pageUid;
        if ($siteIdentifier !== '') {
            $baseParameters['site'] = $siteIdentifier;
        }

        $options = [];
        foreach ($availableLanguages as $language) {
            $parameters = $baseParameters;
            $languageUid = (int)$language['languageId'];
            $parameters['language'] = $languageUid;

            $issueCount = 0;
            if ($siteIdentifier !== '' && $pageUid > 0) {
                $counts = $issueRepository->countOpenBySeverity($pageUid, $siteIdentifier, $languageUid);
                $issueCount = array_sum($counts);
            }

            $options[] = $language + [
                'active' => $currentLanguageUid === $languageUid,
                'url' => $this->buildRouteUrl('web_a11y.pageDetail', $parameters),
                'pages' => $issueCount,
                'hasResults' => $issueCount > 0,
                'pageLabel' => $issueCount === 1 ? '1 issue' : ($issueCount . ' issues'),
            ];
        }

        return $options;
    }

    protected function resolveCurrentLanguageOption(array $languageOptions): array
    {
        foreach ($languageOptions as $option) {
            if (!empty($option['active'])) {
                return $option;
            }
        }

        return $languageOptions[0] ?? [
            'languageId' => 0,
            'title' => 'Default language',
            'locale' => '',
            'flagIdentifier' => '',
            'base' => '',
            'sitemapUrl' => '',
            'isAll' => false,
            'active' => true,
            'url' => '',
            'pages' => 0,
            'hasResults' => false,
            'pageLabel' => '0 issues',
        ];
    }

    private function containsLanguage(array $availableLanguages, int $languageUid): bool
    {
        foreach ($availableLanguages as $language) {
            if ((int)($language['languageId'] ?? 0) === $languageUid) {
                return true;
            }
        }

        return false;
    }

    protected function buildLanguageUrlParameters(ServerRequestInterface $request, array $extraUnset = []): array
    {
        $parameters = $this->getA11yModuleReturnParameters($request);
        foreach (array_merge($extraUnset, ['language', 'languageUid', 'L', 'sys_language_uid']) as $key) {
            unset($parameters[$key]);
        }

        return $parameters;
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
