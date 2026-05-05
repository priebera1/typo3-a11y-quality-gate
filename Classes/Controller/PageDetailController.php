<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\ExportUrlBuilderService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\FilterValueUtility;
use Priebera\A11yQualityGate\Utility\IssueFilterUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsController]
final class PageDetailController extends AbstractBackendModuleController
{
    private const PER_PAGE = 10;
    private const MAX_VISIBLE_PAGINATION_ITEMS = 5;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly IssueRepository $issueRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly ExportUrlBuilderService $exportUrlBuilderService,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $uriBuilder,
            $iconFactory,
            $backendContextService,
            $siteResolutionService,
            $requestParameterService
        );
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);
        $pageUid = $this->requestParameterService->getPageUidOrZero($request);
        $site = $this->resolveSiteForPage($request, $pageUid);

        $this->backendJavaScriptModuleService->loadBackendModule(
            $this->pageRenderer,
            $site
        );

        $siteIdentifier = $site?->getIdentifier() ?? '';
        $activeStatus = $this->requestParameterService->getStatus($request, 'open');
        $activeSeverity = $this->requestParameterService->getSeverity($request, 'all');
        $currentPage = $this->requestParameterService->getPageNumber($request, 1);
        $returnParameters = $this->getA11yModuleReturnParameters($request);

        $proStatus = $this->proStatusResolverService->resolveForSite($site);

        $pageRecord = $pageUid > 0
            ? (BackendUtility::getRecord('pages', $pageUid, 'uid,title,slug,doktype') ?: [])
            : [];

        $pageTitle = trim((string)($pageRecord['title'] ?? ''));
        $pagePath = trim((string)($pageRecord['slug'] ?? ''));
        $pageDoktype = (int)($pageRecord['doktype'] ?? 0);
        $isFolder = $pageDoktype === 254;

        $backendUser = $this->backendContextService->getBackendUser();
        $canScanNow = $this->accessControlService->canShowScanNow($backendUser);
        $canShowSettings = $this->accessControlService->canShowSettings($backendUser);

        $allIssues = ($pageUid > 0 && $siteIdentifier !== '')
            ? $this->issueRepository->findAllForPage($siteIdentifier, $pageUid)
            : [];

        $allIssues = array_map(function (array $row) use (
            $pageUid,
            $siteIdentifier,
            $activeStatus,
            $activeSeverity,
            $currentPage
        ): array {
            $sourceTable = (string)($row['source_table'] ?? '');
            $sourceUid = (int)($row['source_uid'] ?? 0);

            $row['severityEnum'] = Severity::fromInt((int)$row['severity']);
            $row['statusEnum'] = IssueStatus::fromInt((int)$row['status']);
            $row['hasEditAccess'] = false;
            $row['editLink'] = '';

            if (
                $sourceTable !== ''
                && $sourceUid > 0
                && $this->backendRecordAccessService->canEditRecord($sourceTable, $sourceUid)
            ) {
                $row['hasEditAccess'] = true;
                $row['editLink'] = $this->buildEditLink(
                    $sourceTable,
                    $sourceUid,
                    $pageUid,
                    $siteIdentifier,
                    $activeStatus,
                    $activeSeverity,
                    $currentPage,
                );
            }

            return $row;
        }, $allIssues);

        $statusCounts = [
            'open' => count(array_filter(
                $allIssues,
                static fn(array $issue): bool => (int)$issue['status'] === IssueStatus::Open->value
            )),
            'ignored' => count(array_filter(
                $allIssues,
                static fn(array $issue): bool => (int)$issue['status'] === IssueStatus::Ignored->value
            )),
            'resolved' => count(array_filter(
                $allIssues,
                static fn(array $issue): bool => (int)$issue['status'] === IssueStatus::Resolved->value
            )),
            'all' => count($allIssues),
        ];

        $issuesForStatus = IssueFilterUtility::filterByStatus($allIssues, $activeStatus);

        $severityCounts = [
            'critical' => count(array_filter(
                $issuesForStatus,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Critical->value
            )),
            'warning' => count(array_filter(
                $issuesForStatus,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Warning->value
            )),
            'info' => count(array_filter(
                $issuesForStatus,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Info->value
            )),
            'total' => count($issuesForStatus),
        ];

        $visibleIssues = IssueFilterUtility::filterBySeverity($issuesForStatus, $activeSeverity);

        $pagination = $this->buildPagination(
            totalItems: count($visibleIssues),
            currentPage: $currentPage,
            perPage: self::PER_PAGE,
        );

        $paginatedIssues = array_slice(
            $visibleIssues,
            $pagination['offset'],
            self::PER_PAGE
        );

        $grouped = [
            'critical' => array_values(array_filter(
                $paginatedIssues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Critical->value
            )),
            'warning' => array_values(array_filter(
                $paginatedIssues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Warning->value
            )),
            'info' => array_values(array_filter(
                $paginatedIssues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Info->value
            )),
        ];

        $backUrl = $this->buildOverviewUrl($pageUid, $siteIdentifier, $request);

        $ignoreUrl = $this->buildRouteUrl('web_a11y.ignore');
        $unignoreUrl = $this->buildRouteUrl('web_a11y.unignore');

        $exportCsvUrl = $this->exportUrlBuilderService->buildLocalPageCsvUrl(
            $siteIdentifier,
            $pageUid,
            $activeStatus,
            $activeSeverity
        );

        $exportPdfUrl = $this->exportUrlBuilderService->buildLocalPagePdfUrl(
            $siteIdentifier,
            $pageUid,
            $activeStatus,
            $activeSeverity
        );

        $statusFilterUrls = [
            'open' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, 'open'),
            'ignored' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, 'ignored'),
            'resolved' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, 'resolved'),
            'all' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, 'all'),
        ];

        $severityFilterUrls = [
            'all' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, $activeStatus, 'all', 1),
            'critical' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, $activeStatus, 'critical', 1),
            'warning' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, $activeStatus, 'warning', 1),
            'info' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, $activeStatus, 'info', 1),
        ];

        $pagination['pageUrls'] = [];
        for ($page = 1; $page <= $pagination['totalPages']; $page++) {
            $pagination['pageUrls'][$page] = $this->buildPageDetailUrl(
                $pageUid,
                $siteIdentifier,
                $activeStatus,
                $activeSeverity,
                $page
            );
        }

        $pagination['previousUrl'] = $pagination['hasPrevious']
            ? $this->buildPageDetailUrl(
                $pageUid,
                $siteIdentifier,
                $activeStatus,
                $activeSeverity,
                $pagination['currentPage'] - 1
            )
            : null;

        $pagination['nextUrl'] = $pagination['hasNext']
            ? $this->buildPageDetailUrl(
                $pageUid,
                $siteIdentifier,
                $activeStatus,
                $activeSeverity,
                $pagination['currentPage'] + 1
            )
            : null;

        $paginationItems = $this->buildPaginationItems(
            $pagination['currentPage'],
            $pagination['totalPages'],
            $pagination['pageUrls']
        );

        $filterSummary = $this->buildFilterSummary(
            $activeStatus,
            $activeSeverity,
            count($visibleIssues)
        );

        $this->configureDocHeader(
            $moduleTemplate,
            $backUrl,
            $returnParameters,
            $canShowSettings
        );

        $scanStatus = $this->scanStatusService->getStatus();

        $moduleTemplate->assignMultiple([
            'pageUid' => $pageUid,
            'pageTitle' => $pageTitle,
            'pagePath' => $pagePath,
            'siteIdentifier' => $siteIdentifier,
            'activeStatus' => $activeStatus,
            'activeSeverity' => $activeSeverity,
            'grouped' => $grouped,
            'severityCounts' => $severityCounts,
            'statusCounts' => $statusCounts,
            'statusFilterUrls' => $statusFilterUrls,
            'severityFilterUrls' => $severityFilterUrls,
            'filterSummary' => $filterSummary,
            'backUrl' => $backUrl,
            'ignoreUrl' => $ignoreUrl,
            'unignoreUrl' => $unignoreUrl,
            'exportCsvUrl' => $exportCsvUrl,
            'exportPdfUrl' => $exportPdfUrl,
            'pagination' => $pagination,
            'paginationItems' => $paginationItems,
            'canScanNow' => $canScanNow,
            'scanStatus' => $scanStatus,
            'pageDoktype' => $pageDoktype,
            'isFolder' => $isFolder,
            'proStatus' => $proStatus,
        ]);

        return $moduleTemplate->renderResponse('PageDetail/Show');
    }

    public function ignoreAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $issueUid = (int)($body['issueUid'] ?? 0);
        $reason = trim((string)($body['reason'] ?? ''));
        $pageUid = (int)($body['pageUid'] ?? 0);
        $siteIdentifier = (string)($body['siteIdentifier'] ?? '');
        $status = FilterValueUtility::normalizeStatus((string)($body['status'] ?? 'open'));
        $severity = FilterValueUtility::normalizeSeverity((string)($body['severity'] ?? 'all'));
        $page = max(1, (int)($body['page'] ?? 1));

        if ($issueUid > 0 && $reason !== '') {
            $this->issueRepository->ignore($issueUid, $reason, $this->getBackendUserUid());
        }

        return new RedirectResponse(
            $this->buildPageDetailUrl($pageUid, $siteIdentifier, $status, $severity, $page),
            302
        );
    }

    public function unignoreAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        $issueUid = (int)($body['issueUid'] ?? 0);
        $pageUid = (int)($body['pageUid'] ?? 0);
        $siteIdentifier = (string)($body['siteIdentifier'] ?? '');
        $status = FilterValueUtility::normalizeStatus((string)($body['status'] ?? 'ignored'));
        $severity = FilterValueUtility::normalizeSeverity((string)($body['severity'] ?? 'all'));
        $page = max(1, (int)($body['page'] ?? 1));

        if ($issueUid > 0) {
            $this->issueRepository->unignore($issueUid);
        }

        return new RedirectResponse(
            $this->buildPageDetailUrl($pageUid, $siteIdentifier, $status, $severity, $page),
            302
        );
    }

    private function configureDocHeader(
        ModuleTemplate $moduleTemplate,
        string $backUrl,
        array $returnParameters,
        bool $canShowSettings,
    ): void {
        $this->setModuleTitle(
            $moduleTemplate,
            'module.title',
            'module.pageDetail.title'
        );

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $backButton = $buttonBar->makeLinkButton()
            ->setHref($backUrl)
            ->setTitle($this->translate('settings.backToOverview') ?: 'Back to overview')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));
        $buttonBar->addButton($backButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        if ($canShowSettings) {
            $settingsButton = $buttonBar->makeLinkButton()
                ->setHref($this->buildRouteUrl('web_a11y.settings', $returnParameters))
                ->setTitle($this->translate('settings.title') ?: 'Settings')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-cog', IconSize::SMALL));

            $buttonBar->addButton($settingsButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
        }
    }

    private function buildEditLink(
        string $table,
        int $uid,
        int $pageUid,
        string $siteIdentifier,
        string $status,
        string $severity,
        int $page,
    ): string {
        return (string)$this->uriBuilder->buildUriFromRoutePath('/record/edit', [
            'edit' => [
                $table => [
                    $uid => 'edit',
                ],
            ],
            'returnUrl' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, $status, $severity, $page),
        ]);
    }

    private function buildOverviewUrl(
        int $pageUid,
        string $siteIdentifier,
        ServerRequestInterface $request,
    ): string {
        $site = $this->resolveSiteForPage($request, $pageUid);
        $rootPid = $site !== null ? (int)$site->getRootPageId() : $pageUid;

        return $this->buildRouteUrl('web_a11y', [
            'id' => $rootPid,
            'site' => $siteIdentifier,
        ]);
    }

    private function buildPageDetailUrl(
        int $pageUid,
        string $siteIdentifier,
        string $status,
        string $severity = 'all',
        int $page = 1,
    ): string {
        return $this->buildRouteUrl('web_a11y.pageDetail', [
            'pageUid' => $pageUid,
            'id' => $pageUid,
            'site' => $siteIdentifier,
            'status' => $status,
            'severity' => $severity,
            'page' => $page,
        ]);
    }

    /**
     * @return array{
     *   currentPage:int,
     *   totalPages:int,
     *   totalItems:int,
     *   offset:int,
     *   hasPrevious:bool,
     *   hasNext:bool
     * }
     */
    private function buildPagination(int $totalItems, int $currentPage, int $perPage): array
    {
        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $currentPage = min(max(1, $currentPage), $totalPages);

        return [
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'offset' => ($currentPage - 1) * $perPage,
            'hasPrevious' => $currentPage > 1,
            'hasNext' => $currentPage < $totalPages,
        ];
    }

    /**
     * @param array<int, string> $pageUrls
     * @return array<int, array<string, mixed>>
     */
    private function buildPaginationItems(int $currentPage, int $totalPages, array $pageUrls): array
    {
        if ($totalPages <= self::MAX_VISIBLE_PAGINATION_ITEMS) {
            $items = [];

            for ($page = 1; $page <= $totalPages; $page++) {
                $items[] = [
                    'type' => 'page',
                    'label' => (string)$page,
                    'url' => $pageUrls[$page] ?? '#',
                    'active' => $page === $currentPage,
                ];
            }

            return $items;
        }

        $pages = [1, $totalPages, $currentPage - 1, $currentPage, $currentPage + 1];
        $pages = array_values(array_unique(array_filter(
            $pages,
            static fn(int $page): bool => $page >= 1 && $page <= $totalPages
        )));
        sort($pages);

        $items = [];
        $lastPage = null;

        foreach ($pages as $page) {
            if ($lastPage !== null && $page > $lastPage + 1) {
                $items[] = [
                    'type' => 'ellipsis',
                    'label' => '…',
                    'url' => '',
                    'active' => false,
                ];
            }

            $items[] = [
                'type' => 'page',
                'label' => (string)$page,
                'url' => $pageUrls[$page] ?? '#',
                'active' => $page === $currentPage,
            ];

            $lastPage = $page;
        }

        return $items;
    }

    private function buildFilterSummary(string $activeStatus, string $activeSeverity, int $visibleCount): string
    {
        $parts = [];

        if ($activeSeverity !== 'all') {
            $parts[] = $activeSeverity;
        }

        $statusLabel = match ($activeStatus) {
            'ignored' => 'ignored',
            'resolved' => 'resolved',
            'all' => null,
            default => 'open',
        };

        if ($statusLabel !== null) {
            $parts[] = $statusLabel;
        }

        $label = implode(' ', $parts);

        return sprintf(
            'Showing %d %sissue%s',
            $visibleCount,
            $label !== '' ? $label . ' ' : '',
            $visibleCount === 1 ? '' : 's'
        );
    }
}