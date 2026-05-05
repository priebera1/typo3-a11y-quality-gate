<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueNodeRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\ExportUrlBuilderService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
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
final class RemotePageDetailController extends AbstractBackendModuleController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteIssueNodeRepository $remoteIssueNodeRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly RemoteScanRecoveryService $remoteScanRecoveryService,
        private readonly ExportUrlBuilderService $exportUrlBuilderService,
        private readonly ProStatusResolverService $proStatusResolverService,
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

        $remotePageUid = (int)$this->requestParameterService->getString($request, 'remotePageUid');
        $siteIdentifier = $this->requestParameterService->getSiteIdentifier($request);

        $backUrl = $this->buildRouteUrl('web_a11y', [
            'site' => $siteIdentifier,
        ]);

        if ($remotePageUid <= 0) {
            $site = $siteIdentifier !== ''
                ? $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier)
                : null;

            $this->backendJavaScriptModuleService->loadBackendModule(
                $this->pageRenderer,
                $site
            );

            $moduleTemplate->assignMultiple([
                'errorMessage' => $this->translate('module.remotePageDetail.error.missingRemotePageUid'),
                'remotePage' => null,
                'remoteScan' => null,
                'activeRemoteScan' => null,
                'issues' => [],
                'backUrl' => $backUrl,
                'remotePageDebugUrl' => '',
                'exportCsvUrl' => '',
                'exportPdfUrl' => '',
                'exportPdfAllowed' => false,
                'resolvedTypo3PageUid' => 0,
                'proStatus' => null,
            ]);

            return $moduleTemplate->renderResponse('RemotePageDetail/Show');
        }

        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
        if (!is_array($remotePage)) {
            $site = $siteIdentifier !== ''
                ? $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier)
                : null;

            $this->backendJavaScriptModuleService->loadBackendModule(
                $this->pageRenderer,
                $site
            );

            $moduleTemplate->assignMultiple([
                'errorMessage' => $this->translate('module.remotePageDetail.error.remotePageNotFound'),
                'remotePage' => null,
                'remoteScan' => null,
                'activeRemoteScan' => null,
                'issues' => [],
                'backUrl' => $backUrl,
                'remotePageDebugUrl' => '',
                'exportCsvUrl' => '',
                'exportPdfUrl' => '',
                'exportPdfAllowed' => false,
                'resolvedTypo3PageUid' => 0,
                'proStatus' => null,
            ]);

            return $moduleTemplate->renderResponse('RemotePageDetail/Show');
        }

        $remoteScanUid = (int)($remotePage['remote_scan'] ?? 0);
        $remoteScan = $remoteScanUid > 0
            ? $this->remoteScanRepository->findScanByUid($remoteScanUid)
            : null;

        $resolvedSiteIdentifier = is_array($remoteScan)
            ? (string)($remoteScan['site_identifier'] ?? $siteIdentifier)
            : $siteIdentifier;

        $latestRemotePage = $this->remoteScanRepository->findLatestPageByUrl(
            (string)($remotePage['url'] ?? ''),
            $resolvedSiteIdentifier
        );

        if (is_array($latestRemotePage) && (int)$latestRemotePage['uid'] !== $remotePageUid) {
            return new RedirectResponse(
                $this->buildRouteUrl('web_a11y.remotePageDetail', [
                    'remotePageUid' => (int)$latestRemotePage['uid'],
                    'site' => $resolvedSiteIdentifier,
                ])
            );
        }

        $resolvedSite = $resolvedSiteIdentifier !== ''
            ? $this->siteResolutionService->resolveSiteByIdentifier($resolvedSiteIdentifier)
            : null;

        $this->backendJavaScriptModuleService->loadBackendModule(
            $this->pageRenderer,
            $resolvedSite
        );

        $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier($resolvedSiteIdentifier);

        $remotePageDebugUrl = $this->buildRemotePageDebugUrl((string)($remotePage['url'] ?? ''));

        $remoteScreenshotProxyUrl = '';
        if (!empty($remotePage['screenshot_path']) && $remotePageUid > 0) {
            $remoteScreenshotProxyUrl = $this->buildRemoteScreenshotProxyUrl(
                $resolvedSiteIdentifier,
                $remotePageUid
            );
        }

        $issues = $this->remoteIssueRepository->findByRemoteScanPage($remotePageUid);

        $issuesWithNodes = array_map(function (array $issue) use ($resolvedSiteIdentifier, $remotePageUid): array {
            $issueUid = (int)($issue['uid'] ?? 0);
            $nodes = $issueUid > 0
                ? $this->remoteIssueNodeRepository->findByRemoteIssue($issueUid)
                : [];

            $nodes = array_map(function (array $node) use ($resolvedSiteIdentifier, $remotePageUid): array {
                $mappedTable = trim((string)($node['mapped_table'] ?? ''));
                $mappedUid = (int)($node['mapped_uid'] ?? 0);

                $node['editRecordUrl'] = '';
                $node['hasRecordMapping'] = false;
                $node['hasEditAccess'] = false;

                if ($mappedTable !== '' && $mappedUid > 0) {
                    $node['hasRecordMapping'] = true;
                    $node['hasEditAccess'] = $this->backendRecordAccessService->canEditRecord(
                        $mappedTable,
                        $mappedUid
                    );

                    if ($node['hasEditAccess']) {
                        $node['editRecordUrl'] = $this->buildEditRecordUrl(
                            $mappedTable,
                            $mappedUid,
                            $resolvedSiteIdentifier,
                            $remotePageUid
                        );
                    }
                }

                return $node;
            }, $nodes);

            return $issue + [
                    'nodes' => $nodes,
                ];
        }, $issues);

        $resolvedTypo3PageUid = $this->resolveTypo3PageUid($remoteScan, $issuesWithNodes);

        $activeRemoteScan = null;
        if ($resolvedSiteIdentifier !== '') {
            if ($resolvedTypo3PageUid > 0) {
                $activeRemoteScan = $this->remoteScanRepository->findLatestRelevantActiveScan(
                    $resolvedSiteIdentifier,
                    $resolvedTypo3PageUid
                );
            }

            if (!is_array($activeRemoteScan)) {
                $activeRemoteScan = $this->remoteScanRepository->findLatestActiveSiteScanBySite(
                    $resolvedSiteIdentifier
                );
            }
        }

        if (is_array($activeRemoteScan) && $resolvedSite !== null) {
            $activeRemoteScan = $this->remoteScanRecoveryService->recoverScanIfNeeded(
                $activeRemoteScan,
                (string)$resolvedSite->getBase(),
            );
        }

        $groupedIssues = $this->groupIssuesByRule($issuesWithNodes);

        $exportCsvUrl = $this->exportUrlBuilderService->buildRemotePageCsvUrl(
            $resolvedSiteIdentifier,
            $remotePageUid
        );

        $exportPdfUrl = $this->exportUrlBuilderService->buildRemotePagePdfUrl(
            $resolvedSiteIdentifier,
            $remotePageUid
        );

        $this->configureDocHeader($moduleTemplate, $backUrl, $remotePageDebugUrl);

        $moduleTemplate->assignMultiple([
            'errorMessage' => null,
            'remotePage' => $remotePage + [
                    'screenshot_proxy_url' => $remoteScreenshotProxyUrl,
                ],
            'remoteScan' => $remoteScan,
            'activeRemoteScan' => $activeRemoteScan,
            'issues' => $groupedIssues,
            'backUrl' => $backUrl,
            'remotePageDebugUrl' => $remotePageDebugUrl,
            'resolvedSiteIdentifier' => $resolvedSiteIdentifier,
            'exportCsvUrl' => $exportCsvUrl,
            'exportPdfUrl' => $exportPdfUrl,
            'exportPdfAllowed' => $proStatus->valid && !$proStatus->isTrial && $proStatus->hasExportPdf,
            'resolvedTypo3PageUid' => $resolvedTypo3PageUid,
            'proStatus' => $proStatus,
        ]);

        return $moduleTemplate->renderResponse('RemotePageDetail/Show');
    }

    private function buildEditRecordUrl(
        string $table,
        int $uid,
        string $siteIdentifier,
        int $remotePageUid,
    ): string {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [
                $table => [
                    $uid => 'edit',
                ],
            ],
            'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remotePageDetail', [
                'site' => $siteIdentifier,
                'remotePageUid' => $remotePageUid,
            ]),
        ]);
    }

    private function buildRemotePageDebugUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        if (str_contains($url, 'aqgDebug=')) {
            return $url;
        }

        return $url . $separator . 'aqgDebug=1';
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function groupIssuesByRule(array $issues): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            $ruleId = (string)($issue['rule_id'] ?? 'unknown');

            if (!isset($groups[$ruleId])) {
                $groups[$ruleId] = [
                    'rule_id' => $ruleId,
                    'impact' => (string)($issue['impact'] ?? ''),
                    'help' => (string)($issue['help'] ?? ''),
                    'help_url' => (string)($issue['help_url'] ?? ''),
                    'count' => 0,
                    'nodes' => [],
                    'mappedUids' => [],
                ];
            }

            $groups[$ruleId]['count'] += (int)($issue['nodes_count'] ?? 1);

            if (!empty($issue['nodes']) && is_array($issue['nodes'])) {
                foreach ($issue['nodes'] as $node) {
                    if (!is_array($node)) {
                        continue;
                    }

                    $groups[$ruleId]['nodes'][] = $node;

                    $mappedUid = (int)($node['mapped_uid'] ?? 0);
                    if ($mappedUid > 0) {
                        $groups[$ruleId]['mappedUids'][$mappedUid] = $mappedUid;
                    }
                }
            }
        }

        foreach ($groups as &$group) {
            $uids = array_values($group['mappedUids']);
            $group['highlightUids'] = $uids !== [] ? implode(',', $uids) : '';
            unset($group['mappedUids']);
        }
        unset($group);

        return array_values($groups);
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @param array<int, array<string, mixed>> $issuesWithNodes
     */
    private function resolveTypo3PageUid(?array $remoteScan, array $issuesWithNodes): int
    {
        $scanPageUid = (int)($remoteScan['page_uid'] ?? 0);
        if ($scanPageUid > 0) {
            return $scanPageUid;
        }

        foreach ($issuesWithNodes as $issue) {
            $nodes = is_array($issue['nodes'] ?? null) ? $issue['nodes'] : [];

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $mappedTable = trim((string)($node['mapped_table'] ?? ''));
                $mappedUid = (int)($node['mapped_uid'] ?? 0);

                if ($mappedTable === '' || $mappedUid <= 0) {
                    continue;
                }

                if ($mappedTable === 'pages') {
                    return $mappedUid;
                }

                $record = BackendUtility::getRecord($mappedTable, $mappedUid, 'pid');
                if (is_array($record) && (int)($record['pid'] ?? 0) > 0) {
                    return (int)$record['pid'];
                }
            }
        }

        return 0;
    }

    private function configureDocHeader(
        ModuleTemplate $moduleTemplate,
        string $backUrl,
        string $remotePageDebugUrl,
    ): void {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $this->setModuleTitle(
            $moduleTemplate,
            'module.title',
            'module.remotePageDetail.title'
        );

        $backButton = $buttonBar->makeLinkButton()
            ->setHref($backUrl)
            ->setTitle($this->translate('settings.backToOverview'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));

        $buttonBar->addButton($backButton, ButtonBar::BUTTON_POSITION_LEFT);

        if ($remotePageDebugUrl !== '') {
            $openFrontendButton = $buttonBar->makeLinkButton()
                ->setHref($remotePageDebugUrl)
                ->setTitle($this->translate('module.remotePageDetail.openFrontendDebug'))
                ->setShowLabelText(true)
                ->setDataAttributes(['open-new-tab' => '1'])
                ->setIcon($this->iconFactory->getIcon('actions-view-page', IconSize::SMALL));

            $buttonBar->addButton($openFrontendButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
        }
    }

    private function buildRemoteScreenshotProxyUrl(
        string $siteIdentifier,
        int $remotePageUid,
    ): string {
        return (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remoteScreenshot', [
            'site' => $siteIdentifier,
            'remotePageUid' => $remotePageUid,
        ]);
    }
}