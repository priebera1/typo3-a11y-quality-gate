<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsController]
final class PageDetailController extends AbstractBackendModuleController
{
    private const PER_PAGE = 10;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        SiteFinder $siteFinder,
        BackendContextService $backendContextService,
        private readonly IssueRepository $issueRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $uriBuilder,
            $iconFactory,
            $siteFinder,
            $backendContextService
        );
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);

        $this->pageRenderer->loadJavaScriptModule(
            '@priebera/a11y-quality-gate/backend/module.js'
        );

        $routing = $request->getAttribute('routing');
        $pageUid = (int)($routing?->getArguments()['pageUid'] ?? 0);

        if ($pageUid === 0) {
            $queryParams = $request->getQueryParams();
            $pageUid = (int)($queryParams['pageUid'] ?? $queryParams['id'] ?? 0);
        }

        $site = $this->resolveSiteForPage($request, $pageUid);
        $siteIdentifier = $site?->getIdentifier() ?? '';
        $activeStatus = $this->resolveActiveStatus($request);
        $activeSeverity = $this->resolveActiveSeverity($request);
        $currentPage = $this->resolveCurrentPage($request);
        $returnParameters = $this->getA11yModuleReturnParameters($request);

        $allIssues = ($pageUid > 0 && $siteIdentifier !== '')
            ? $this->issueRepository->findAllForPage($siteIdentifier, $pageUid)
            : [];

        $allIssues = array_map(function (array $row) use ($pageUid, $siteIdentifier, $activeStatus, $activeSeverity, $currentPage): array {
            $row['severityEnum'] = Severity::fromInt((int)$row['severity']);
            $row['statusEnum'] = IssueStatus::fromInt((int)$row['status']);
            $row['editLink'] = $this->buildEditLink(
                (string)$row['source_table'],
                (int)$row['source_uid'],
                $pageUid,
                $siteIdentifier,
                $activeStatus,
                $activeSeverity,
                $currentPage,
            );

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

        $issuesForStatus = $this->filterIssuesByStatus($allIssues, $activeStatus);

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

        $visibleIssues = $this->filterIssuesBySeverity($issuesForStatus, $activeSeverity);

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

        $backUrl = $this->buildOverviewUrl($pageUid, $siteIdentifier);
        $detailUrl = $this->buildPageDetailUrl($pageUid, $siteIdentifier, $activeStatus, $activeSeverity, $currentPage);

        $ignoreUrl = $this->buildRouteUrl('web_a11y.ignore');
        $unignoreUrl = $this->buildRouteUrl('web_a11y.unignore');
        $exportCsvUrl = $this->buildRouteUrl('web_a11y.exportCsv', [
            'site' => $siteIdentifier,
            'pageUid' => $pageUid,
        ]);

        $rescanEndpoint = $this->buildRescanEndpoint();

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
            ? $this->buildPageDetailUrl($pageUid, $siteIdentifier, $activeStatus, $activeSeverity, $pagination['currentPage'] - 1)
            : null;

        $pagination['nextUrl'] = $pagination['hasNext']
            ? $this->buildPageDetailUrl($pageUid, $siteIdentifier, $activeStatus, $activeSeverity, $pagination['currentPage'] + 1)
            : null;

        $this->configureDocHeader($moduleTemplate, $backUrl, $exportCsvUrl, $returnParameters);

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $canScanNow = $this->accessControlService->canShowScanNow(
            $backendUser instanceof BackendUserAuthentication ? $backendUser : null
        );

        $scanStatus = $this->scanStatusService->getStatus();

        $moduleTemplate->assignMultiple([
            'pageUid' => $pageUid,
            'siteIdentifier' => $siteIdentifier,
            'activeStatus' => $activeStatus,
            'activeSeverity' => $activeSeverity,
            'issues' => $paginatedIssues,
            'grouped' => $grouped,
            'severityCounts' => $severityCounts,
            'statusCounts' => $statusCounts,
            'statusFilterUrls' => $statusFilterUrls,
            'severityFilterUrls' => $severityFilterUrls,
            'backUrl' => $backUrl,
            'detailUrl' => $detailUrl,
            'ignoreUrl' => $ignoreUrl,
            'unignoreUrl' => $unignoreUrl,
            'exportCsvUrl' => $exportCsvUrl,
            'rescanEndpoint' => $rescanEndpoint,
            'pagination' => $pagination,
            'canScanNow' => $canScanNow,
            'scanStatus' => $scanStatus,
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
        $status = $this->normalizeStatus((string)($body['status'] ?? 'open'));
        $severity = $this->normalizeSeverity((string)($body['severity'] ?? 'all'));
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
        $status = $this->normalizeStatus((string)($body['status'] ?? 'ignored'));
        $severity = $this->normalizeSeverity((string)($body['severity'] ?? 'all'));
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
        string $exportCsvUrl,
        array $returnParameters,
    ): void {
        $this->setModuleTitle(
            $moduleTemplate,
            'module.title',
            'module.pageDetail.title'
        );

        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $backButton = $buttonBar->makeLinkButton()
            ->setHref($backUrl)
            ->setTitle($this->translate('action.back'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));
        $buttonBar->addButton($backButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $exportButton = $buttonBar->makeLinkButton()
            ->setHref($exportCsvUrl)
            ->setTitle($this->translate('action.export'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-document-export-csv', IconSize::SMALL));
        $buttonBar->addButton($exportButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);

        $settingsButton = $buttonBar->makeLinkButton()
            ->setHref($this->buildRouteUrl('web_a11y.settings', $returnParameters))
            ->setTitle($this->translate('settings.title'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-cog', IconSize::SMALL));

        $buttonBar->addButton($settingsButton, ButtonBar::BUTTON_POSITION_RIGHT, 2);
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

    private function buildOverviewUrl(int $pageUid, string $siteIdentifier): string
    {
        return $this->buildRouteUrl('web_a11y', [
            'id' => $pageUid,
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

    private function buildRescanEndpoint(): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute('ajax_a11y_scan_page');
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function filterIssuesByStatus(array $issues, string $activeStatus): array
    {
        return match ($activeStatus) {
            'ignored' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['status'] === IssueStatus::Ignored->value
            )),
            'resolved' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['status'] === IssueStatus::Resolved->value
            )),
            'all' => $issues,
            default => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['status'] === IssueStatus::Open->value
            )),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function filterIssuesBySeverity(array $issues, string $activeSeverity): array
    {
        return match ($activeSeverity) {
            'critical' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Critical->value
            )),
            'warning' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Warning->value
            )),
            'info' => array_values(array_filter(
                $issues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::Info->value
            )),
            default => $issues,
        };
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

    private function resolveActiveStatus(ServerRequestInterface $request): string
    {
        return $this->normalizeStatus((string)($request->getQueryParams()['status'] ?? 'open'));
    }

    private function resolveActiveSeverity(ServerRequestInterface $request): string
    {
        return $this->normalizeSeverity((string)($request->getQueryParams()['severity'] ?? 'all'));
    }

    private function resolveCurrentPage(ServerRequestInterface $request): int
    {
        return max(1, (int)($request->getQueryParams()['page'] ?? 1));
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['open', 'ignored', 'resolved', 'all'], true)
            ? $status
            : 'open';
    }

    private function normalizeSeverity(string $severity): string
    {
        return in_array($severity, ['all', 'critical', 'warning', 'info'], true)
            ? $severity
            : 'all';
    }
}
