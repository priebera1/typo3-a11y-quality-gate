<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
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
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsController]
final class OverviewController extends AbstractBackendModuleController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        SiteFinder $siteFinder,
        BackendContextService $backendContextService,
        private readonly ScanRepository $scanRepository,
        private readonly IssueRepository $issueRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
        private readonly FieldConfigRepository $fieldConfigRepository,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $uriBuilder,
            $iconFactory,
            $siteFinder,
            $backendContextService
        );
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);
        $this->pageRenderer->loadJavaScriptModule(
            '@priebera/a11y-quality-gate/backend/module.js'
        );

        $site = $this->resolveSiteFromRequest($request);
        $siteIdentifier = $site?->getIdentifier() ?? '';
        $returnParameters = $this->getA11yModuleReturnParameters($request);

        $lastScan = $siteIdentifier !== ''
            ? $this->scanRepository->findLastCompletedScan($siteIdentifier)
            : null;

        $pageStats = $siteIdentifier !== ''
            ? $this->issueRepository->findOpenPageStatsForSite($siteIdentifier)
            : [];

        $totalCounts = $this->sumCounts($pageStats);
        $scanStatus = $this->scanStatusService->getStatus();

        $exportCsvUrl = $this->buildRouteUrl('web_a11y.exportCsv', [
            'site' => $siteIdentifier,
        ]);

        $this->configureDocHeader($moduleTemplate, $exportCsvUrl, $returnParameters);

        $pageStats = array_map(
            fn(array $stat): array => $stat + [
                    'detailUrl' => $this->buildRouteUrl('web_a11y.pageDetail', [
                        'pageUid' => $stat['pageUid'],
                        'id' => $stat['pageUid'],
                        'site' => $siteIdentifier,
                    ]),
                ],
            $pageStats
        );

        $backendUser = $GLOBALS['BE_USER'] ?? null;
        $canScanAll = $this->accessControlService->canShowScanAll(
            $backendUser instanceof BackendUserAuthentication ? $backendUser : null
        );

        $siteRootPid = $site !== null ? (int)$site->getRootPageId() : 0;
        $hasEnabledFields = $this->fieldConfigRepository->hasEnabledFields();

        $moduleTemplate->assignMultiple([
            'siteIdentifier' => $siteIdentifier,
            'lastScan' => $lastScan,
            'pageStats' => $pageStats,
            'totalCounts' => $totalCounts,
            'canScanAll' => $canScanAll,
            'siteRootPid' => $siteRootPid,
            'scanStatus' => $scanStatus,
            'hasScanResults' => $lastScan !== null,
            'hasEnabledFields' => $hasEnabledFields,
        ]);

        return $moduleTemplate->renderResponse('Overview/Index');
    }

    private function configureDocHeader(
        ModuleTemplate $moduleTemplate,
        string $exportCsvUrl,
        array $returnParameters = []
    ): void {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $this->setModuleTitle(
            $moduleTemplate,
            'module.title',
            'module.overview.title'
        );

        $exportButton = $buttonBar->makeLinkButton()
            ->setHref($exportCsvUrl)
            ->setTitle($this->translate('action.export'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-document-export-csv', IconSize::SMALL));

        $buttonBar->addButton($exportButton, ButtonBar::BUTTON_POSITION_RIGHT);

        $settingsButton = $buttonBar->makeLinkButton()
            ->setHref($this->buildRouteUrl('web_a11y.settings', $returnParameters))
            ->setTitle($this->translate('settings.title'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-cog', IconSize::SMALL));

        $buttonBar->addButton($settingsButton, ButtonBar::BUTTON_POSITION_RIGHT, 2);
    }

    /**
     * @param array<int, array{critical:int,warning:int,info:int,total:int}> $pageStats
     * @return array{critical:int,warning:int,info:int,total:int}
     */
    private function sumCounts(array $pageStats): array
    {
        $totals = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'total' => 0,
        ];

        foreach ($pageStats as $stat) {
            $totals['critical'] += $stat['critical'];
            $totals['warning'] += $stat['warning'];
            $totals['info'] += $stat['info'];
            $totals['total'] += $stat['total'];
        }

        return $totals;
    }
}
