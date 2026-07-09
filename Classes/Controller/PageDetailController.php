<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\ExportUrlBuilderService;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPreviewService;
use Priebera\A11yQualityGate\Service\LocalIssueGuidanceService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Priebera\A11yQualityGate\Service\SiteLanguageService;
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
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

#[AsController]
final class PageDetailController extends AbstractBackendModuleController
{
    private const PER_PAGE = 10;
    private const MAX_VISIBLE_PAGINATION_ITEMS = 5;

    private int $activeLanguageUidForUrls = -1;

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly IssueRepository $issueRepository,
        private readonly ScanRepository $scanRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
        private readonly SiteLanguageService $siteLanguageService,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly ProStatusResolverService $proStatusResolverService,
        private readonly AiFeatureAccessServiceInterface $aiFeatureAccessService,
        private readonly ExportUrlBuilderService $exportUrlBuilderService,
        private readonly ImageFindingContextResolver $imageFindingContextResolver,
        private readonly ImageFindingVersionTokenService $imageFindingVersionTokenService,
        private readonly ImageAltTextValidator $imageAltTextValidator,
        private readonly ImageRemediationPreviewService $imageRemediationPreviewService,
        private readonly LocalIssueGuidanceService $localIssueGuidanceService,
        private readonly AiConfigurationRepositoryInterface $aiConfigurationRepository,
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
        $this->pageRenderer->loadJavaScriptModule('@priebera/a11y-quality-gate/image-remediation.js');
        $this->pageRenderer->loadJavaScriptModule('@priebera/a11y-quality-gate/ai-link-text-suggestion.js');
        $this->pageRenderer->loadJavaScriptModule('@priebera/a11y-quality-gate/ai-iframe-title-suggestion.js');

        $siteIdentifier = $site?->getIdentifier() ?? '';
        $activeStatus = $this->requestParameterService->getStatus($request, 'open');
        $activeSeverity = $this->requestParameterService->getSeverity($request, 'all');
        $currentPage = $this->requestParameterService->getPageNumber($request, 1);
        $returnParameters = $this->getA11yModuleReturnParameters($request);
        $queryParams = $request->getQueryParams();
        $availableLanguages = $site !== null ? $this->siteLanguageService->getLanguagesForSiteObject($site) : [];
        if ($site !== null && $pageUid > 0) {
            $availableLanguages = $this->siteLanguageService->filterLanguagesAvailableForPage($pageUid, $availableLanguages);
        }
        $currentLanguageUid = $this->resolveCurrentLanguageUid($request, $availableLanguages);
        $this->activeLanguageUidForUrls = $currentLanguageUid;
        $languageOptions = $this->buildPageDetailLanguageOptions($request, $this->issueRepository, $siteIdentifier, $pageUid, $availableLanguages, $currentLanguageUid);
        $currentLanguageOption = $this->resolveCurrentLanguageOption($languageOptions);

        $proStatus = $this->proStatusResolverService->resolveForSite($site);
        $aiAltSuggestionAvailable = $this->aiFeatureAccessService->isAvailable($siteIdentifier);
        $aiLinkTextSuggestionAvailable = $aiAltSuggestionAvailable
            && $this->aiConfigurationRepository->isLinkTextSuggestionsEnabled($siteIdentifier);
        $aiIframeTitleSuggestionAvailable = $aiLinkTextSuggestionAvailable;

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

        if ($siteIdentifier !== '') {
            $this->issueRepository->reopenExpiredIgnoredIssues($siteIdentifier);
        }

        $allIssues = ($pageUid > 0 && $siteIdentifier !== '')
            ? $this->issueRepository->findAllForPage($siteIdentifier, $pageUid, $currentLanguageUid)
            : [];

        $openRuleCountsOnPage = ($pageUid > 0 && $siteIdentifier !== '')
            ? $this->issueRepository->countOpenByRuleOnPage($siteIdentifier, $pageUid, $currentLanguageUid)
            : [];
        $openRuleCountsOnSite = $siteIdentifier !== ''
            ? $this->issueRepository->countOpenByRuleOnSite($siteIdentifier, $currentLanguageUid)
            : [];

        $allIssues = array_map(function (array $row) use (
            $pageUid,
            $siteIdentifier,
            $activeStatus,
            $activeSeverity,
            $currentPage,
            $openRuleCountsOnPage,
            $openRuleCountsOnSite,
            $aiAltSuggestionAvailable,
            $aiLinkTextSuggestionAvailable,
            $aiIframeTitleSuggestionAvailable
        ): array {
            $sourceTable = (string)($row['source_table'] ?? '');
            $sourceUid = (int)($row['source_uid'] ?? 0);

            $ruleId = (string)($row['rule_id'] ?? '');
            $row['severityEnum'] = Severity::fromInt((int)$row['severity']);
            $row['statusEnum'] = IssueStatus::fromInt((int)$row['status']);
            $row['openRuleCountOnPage'] = (int)($openRuleCountsOnPage[$ruleId] ?? 0);
            $row['openRuleCountOnSite'] = (int)($openRuleCountsOnSite[$ruleId] ?? 0);
            $ignoredUntil = (int)($row['ignored_until'] ?? 0);
            $ignoredReopenedAt = (int)($row['ignored_reopened_at'] ?? 0);
            $row['ignoredUntilLabel'] = $ignoredUntil > 0 ? date('d M Y', $ignoredUntil) : '';
            $row['ignoredUntilRelative'] = $ignoredUntil > 0 ? $this->formatExpiryRelative($ignoredUntil) : '';
            $row['ignoreIsTemporary'] = $ignoredUntil > 0;
            $row['ignoreReopensSoon'] = $ignoredUntil > 0 && $ignoredUntil <= strtotime('+7 days');
            $row['ignoreWasReopened'] = (int)$row['status'] === IssueStatus::Open->value && $ignoredReopenedAt > 0;
            $row['ignoredReopenedAtLabel'] = $ignoredReopenedAt > 0 ? date('d M Y', $ignoredReopenedAt) : '';
            $row['hasEditAccess'] = false;
            $row['editLink'] = '';
            $sourceType = trim((string)($row['source_type'] ?? ''));
            if ($sourceType === '') {
                $sourceType = str_starts_with($ruleId, 'structured.') ? 'structured' : (str_starts_with($ruleId, 'rendered.') ? 'rendered' : 'rte');
            }
            $row['sourceType'] = $sourceType;
            $row['sourceTypeLabel'] = match ($sourceType) {
                'rendered' => 'Rendered HTML',
                'structured' => 'Structured content',
                'remote' => 'Remote crawler',
                default => 'Content source',
            };
            $row['sourceHint'] = $sourceType === 'rendered' && $sourceTable === 'pages'
                ? 'Likely: template, layout or plugin output'
                : '';
            $row['guidance'] = $this->localIssueGuidanceService->present($row);
            $row['aiLinkTextSuggestionAvailable'] = false;
            $row['aiIframeTitleSuggestionAvailable'] = false;

            if (
                $sourceTable !== ''
                && $sourceUid > 0
                && $this->backendRecordAccessService->canEditRecord($sourceTable, $sourceUid)
            ) {
                $row['hasEditAccess'] = true;
                $row['aiLinkTextSuggestionAvailable'] = $aiLinkTextSuggestionAvailable
                    && in_array($ruleId, ['rte.non_descriptive_link', 'rte.empty_link', 'rendered.empty_link'], true);
                $row['aiIframeTitleSuggestionAvailable'] = $aiIframeTitleSuggestionAvailable
                    && $ruleId === 'rendered.iframe_missing_title';
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

            if (!$row['aiLinkTextSuggestionAvailable']
                && $aiLinkTextSuggestionAvailable
                && $ruleId === 'rendered.empty_link'
                && $pageUid > 0
                && $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid)) {
                $row['aiLinkTextSuggestionAvailable'] = true;
            }

            if (!$row['aiIframeTitleSuggestionAvailable']
                && $aiIframeTitleSuggestionAvailable
                && $ruleId === 'rendered.iframe_missing_title'
                && $pageUid > 0
                && $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid)) {
                $row['aiIframeTitleSuggestionAvailable'] = true;
            }

            $row['imageRemediationAvailable'] = false;
            $row['imageRemediationVersion'] = '';
            $row['imageIsDecorative'] = false;
            $row['imageAlternative'] = '';
            $row['aiAltSuggestionAvailable'] = false;
            $row['imageAlternativeMaxLength'] = $this->imageAltTextValidator->storageLimit();
            $row['imagePreviewAvailable'] = false;
            $row['imagePreviewUrl'] = '';
            $row['imagePreviewPath'] = '';
            if ($row['hasEditAccess'] && $this->imageFindingContextResolver->supportsIssueRow($row)) {
                try {
                    $imageContext = $this->imageFindingContextResolver->resolve((int)$row['uid']);
                    $row['imageRemediationAvailable'] = true;
                    $row['imageRemediationVersion'] = $this->imageFindingVersionTokenService->create($imageContext);
                    $row['imageIsDecorative'] = (int)($imageContext->fileReference['tx_a11y_is_decorative'] ?? 0) === 1;
                    $row['imageAlternative'] = trim((string)($imageContext->fileReference['alternative'] ?? ''));
                    $preview = $this->imageRemediationPreviewService->build($imageContext);
                    $row['imagePreviewAvailable'] = $preview['available'];
                    $row['imagePreviewUrl'] = $preview['url'];
                    $row['imagePreviewPath'] = $preview['displayPath'];
                    $row['aiAltSuggestionAvailable'] = $aiAltSuggestionAvailable;
                } catch (\Throwable) {
                }
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
            'needs_review' => count(array_filter(
                $issuesForStatus,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::NeedsReview->value
            )),
            'total' => count($issuesForStatus),
        ];

        $visibleIssues = IssueFilterUtility::filterBySeverity($issuesForStatus, $activeSeverity);
        $hasRenderedIssues = count(array_filter($issuesForStatus, static fn(array $issue): bool => (string)($issue['sourceType'] ?? '') === 'rendered')) > 0;

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
            'needs_review' => array_values(array_filter(
                $paginatedIssues,
                static fn(array $issue): bool => (int)$issue['severity'] === Severity::NeedsReview->value
            )),
        ];

        $backUrl = $this->buildOverviewUrl($pageUid, $siteIdentifier, $request);

        $ignoreUrl = $this->buildRouteUrl('web_a11y.ignore');
        $batchIgnoreUrl = $this->buildRouteUrl('web_a11y.batchIgnore');
        $ignoreRuleOnPageUrl = $this->buildRouteUrl('web_a11y.ignoreRuleOnPage');
        $ignoreRuleOnSiteUrl = $this->buildRouteUrl('web_a11y.ignoreRuleOnSite');
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
            'needs_review' => $this->buildPageDetailUrl($pageUid, $siteIdentifier, $activeStatus, 'needs_review', 1),
        ];
        $resetFilterUrl = $this->buildPageDetailUrl($pageUid, $siteIdentifier, 'open', 'all', 1);

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
        $siteRootPid = $site !== null ? (int)$site->getRootPageId() : 0;
        $lastPageScan = $siteIdentifier !== '' && $pageUid > 0
            ? $this->scanRepository->findLastCompletedPageScan($siteIdentifier, $pageUid)
            : null;
        $lastScan = $lastPageScan;
        $localDetail = [
            'lastScanAt' => is_array($lastScan) ? (int)($lastScan['finished_at'] ?? 0) : 0,
            'lastScanAtFormatted' => is_array($lastScan) ? BackendTimeUtility::formatDateTime((int)($lastScan['finished_at'] ?? 0), 'd.m.Y · H:i') : '—',
            'hasScan' => is_array($lastScan),
            'isRelevantScanRunning' => $this->isRelevantScanRunning($scanStatus, $siteRootPid, $pageUid),
        ];

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
            'visibleIssuesCount' => count($visibleIssues),
            'hasRenderedIssues' => $hasRenderedIssues,
            'statusFilterUrls' => $statusFilterUrls,
            'severityFilterUrls' => $severityFilterUrls,
            'resetFilterUrl' => $resetFilterUrl,
            'filterSummary' => $filterSummary,
            'backUrl' => $backUrl,
            'ignoreUrl' => $ignoreUrl,
            'batchIgnoreUrl' => $batchIgnoreUrl,
            'ignoreRuleOnPageUrl' => $ignoreRuleOnPageUrl,
            'ignoreRuleOnSiteUrl' => $ignoreRuleOnSiteUrl,
            'canIgnoreRuleOnSite' => $canShowSettings,
            'unignoreUrl' => $unignoreUrl,
            'exportCsvUrl' => $exportCsvUrl,
            'exportPdfUrl' => $exportPdfUrl,
            'pagination' => $pagination,
            'paginationItems' => $paginationItems,
            'canScanNow' => $canScanNow,
            'canShowSettings' => $canShowSettings,
            'scanStatus' => $scanStatus,
            'localDetail' => $localDetail,
            'settingsUrl' => $this->buildRouteUrl('web_a11y.settings', $returnParameters),
            'pageDoktype' => $pageDoktype,
            'isFolder' => $isFolder,
            'proStatus' => $proStatus,
            'currentLanguageUid' => $currentLanguageUid,
            'languageOptions' => $languageOptions,
            'currentLanguageOption' => $currentLanguageOption,
            'hasLanguageOptions' => $languageOptions !== [],
            'languageOptionCount' => count($languageOptions),
            'availableLanguageCount' => count($languageOptions),
            'imageMarkDecorativeUrl' => $this->buildRouteUrl('ajax_a11y_image_mark_decorative'),
            'imageMarkInformativeUrl' => $this->buildRouteUrl('ajax_a11y_image_mark_informative'),
            'imageApplyAltUrl' => $this->buildRouteUrl('ajax_a11y_image_apply_alt'),
            'imageSuggestAltUrl' => $this->buildRouteUrl('ajax_a11y_ai_suggest_alt'),
            'aiSuggestLinkTextUrl' => $this->buildRouteUrl('ajax_a11y_ai_suggest_link_text'),
            'aiSuggestIframeTitleUrl' => $this->buildRouteUrl('ajax_a11y_ai_suggest_iframe_title'),
        ]);

        return $moduleTemplate->renderResponse('PageDetail/Show');
    }

    private function isRelevantScanRunning(array $scanStatus, int $siteRootPid, int $pageUid): bool
    {
        if (empty($scanStatus['running'])) {
            return false;
        }

        $runningPageUid = (int)($scanStatus['pageUid'] ?? 0);
        if ($pageUid > 0 && $runningPageUid === $pageUid) {
            return true;
        }

        $runningRootPid = (int)($scanStatus['rootPid'] ?? 0);

        return $siteRootPid > 0 && $runningRootPid === $siteRootPid;
    }

    public function ignoreAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess(
            $this->accessControlService,
            'editRecord',
            $request
        );
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $issueUid = (int)($body['issueUid'] ?? 0);
        $reason = trim((string)($body['reason'] ?? ''));
        $pageUid = (int)($body['pageUid'] ?? 0);
        $siteIdentifier = (string)($body['siteIdentifier'] ?? '');
        $status = FilterValueUtility::normalizeStatus((string)($body['status'] ?? 'open'));
        $severity = FilterValueUtility::normalizeSeverity((string)($body['severity'] ?? 'all'));
        $page = max(1, (int)($body['page'] ?? 1));
        $this->activeLanguageUidForUrls = $this->requestParameterService->getLanguageUidFromParameters(
            is_array($body) ? $body : [],
            [],
            -1,
            true,
        );

        $expiry = $this->resolveIgnoreExpiry($body);

        if (!$expiry['valid']) {
            $this->addFlashMessage($expiry['error'], ContextualFeedbackSeverity::WARNING, 'Accessibility');
        } elseif ($issueUid > 0 && !$this->canEditIssue($issueUid)) {
            $this->addFlashMessage('Access denied.', ContextualFeedbackSeverity::ERROR, 'Accessibility');
        } elseif ($issueUid > 0 && $reason !== '') {
            $user = $this->getBackendUserSnapshot();
            $this->issueRepository->ignore($issueUid, $reason, $user['uid'], $user['name'], $user['username'], $expiry['ignoredUntil']);
        }

        return new RedirectResponse(
            $this->buildPageDetailUrl($pageUid, $siteIdentifier, $status, $severity, $page),
            302
        );
    }

    public function batchIgnoreAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess(
            $this->accessControlService,
            'editRecord',
            $request
        );
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $issueUids = $body['issueUids'] ?? [];
        $issueUids = is_array($issueUids) ? $issueUids : [];
        $reason = trim((string)($body['reason'] ?? ''));
        $pageUid = (int)($body['pageUid'] ?? 0);
        $siteIdentifier = (string)($body['siteIdentifier'] ?? '');
        $status = FilterValueUtility::normalizeStatus((string)($body['status'] ?? 'open'));
        $severity = FilterValueUtility::normalizeSeverity((string)($body['severity'] ?? 'all'));
        $page = max(1, (int)($body['page'] ?? 1));
        $languageUid = $this->requestParameterService->getLanguageUidFromParameters(
            is_array($body) ? $body : [],
            [],
            -1,
            true,
        );
        $this->activeLanguageUidForUrls = $languageUid;

        $expiry = $this->resolveIgnoreExpiry($body);

        if (!$expiry['valid']) {
            $this->addFlashMessage($expiry['error'], ContextualFeedbackSeverity::WARNING, 'Accessibility');
        } elseif (!$this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid)) {
            $this->addFlashMessage('Access denied.', ContextualFeedbackSeverity::ERROR, 'Accessibility');
        } elseif ($issueUids !== [] && $reason !== '') {
            $user = $this->getBackendUserSnapshot();
            $ignored = $this->issueRepository->ignoreManyOpenOnPage(
                $issueUids,
                $siteIdentifier,
                $pageUid,
                $languageUid,
                $reason,
                $user['uid'],
                $user['name'],
                $user['username'],
                $expiry['ignoredUntil']
            );
            $this->addFlashMessage($ignored . ' issues ignored.', ContextualFeedbackSeverity::OK, 'Accessibility');
        } else {
            $this->addFlashMessage('No issues were ignored. Select issues and enter a reason.', ContextualFeedbackSeverity::WARNING, 'Accessibility');
        }

        return new RedirectResponse(
            $this->buildPageDetailUrl($pageUid, $siteIdentifier, $status, $severity, $page),
            302
        );
    }

    public function ignoreRuleOnPageAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess(
            $this->accessControlService,
            'editRecord',
            $request
        );
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $ruleId = trim((string)($body['ruleId'] ?? ''));
        $reason = trim((string)($body['reason'] ?? ''));
        $pageUid = (int)($body['pageUid'] ?? 0);
        $siteIdentifier = (string)($body['siteIdentifier'] ?? '');
        $status = FilterValueUtility::normalizeStatus((string)($body['status'] ?? 'open'));
        $severity = FilterValueUtility::normalizeSeverity((string)($body['severity'] ?? 'all'));
        $page = max(1, (int)($body['page'] ?? 1));
        $languageUid = $this->requestParameterService->getLanguageUidFromParameters(
            is_array($body) ? $body : [],
            [],
            -1,
            true,
        );
        $this->activeLanguageUidForUrls = $languageUid;

        $expiry = $this->resolveIgnoreExpiry($body);

        if (!$expiry['valid']) {
            $this->addFlashMessage($expiry['error'], ContextualFeedbackSeverity::WARNING, 'Accessibility');
        } elseif (!$this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid)) {
            $this->addFlashMessage('Access denied.', ContextualFeedbackSeverity::ERROR, 'Accessibility');
        } elseif ($ruleId !== '' && $reason !== '') {
            $user = $this->getBackendUserSnapshot();
            $ignored = $this->issueRepository->ignoreAllByRuleOnPage(
                $siteIdentifier,
                $pageUid,
                $languageUid,
                $ruleId,
                $reason,
                $user['uid'],
                $user['name'],
                $user['username'],
                $expiry['ignoredUntil']
            );
            $this->addFlashMessage($ignored . ' issues ignored for rule ' . $ruleId . ' on this page.', ContextualFeedbackSeverity::OK, 'Accessibility');
        } else {
            $this->addFlashMessage('Rule ignore was not applied. Select a rule and enter a reason.', ContextualFeedbackSeverity::WARNING, 'Accessibility');
        }

        return new RedirectResponse(
            $this->buildPageDetailUrl($pageUid, $siteIdentifier, $status, $severity, $page),
            302
        );
    }

    public function ignoreRuleOnSiteAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess(
            $this->accessControlService,
            'editRecord',
            $request
        );
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $settingsAccessResponse = $this->ensureBackendUserAccess(
            $this->accessControlService,
            'settings',
            $request
        );
        if ($settingsAccessResponse !== null) {
            return $settingsAccessResponse;
        }

        $body = $request->getParsedBody();
        $ruleId = trim((string)($body['ruleId'] ?? ''));
        $reason = trim((string)($body['reason'] ?? ''));
        $pageUid = (int)($body['pageUid'] ?? 0);
        $siteIdentifier = (string)($body['siteIdentifier'] ?? '');
        $status = FilterValueUtility::normalizeStatus((string)($body['status'] ?? 'open'));
        $severity = FilterValueUtility::normalizeSeverity((string)($body['severity'] ?? 'all'));
        $page = max(1, (int)($body['page'] ?? 1));
        $languageUid = $this->requestParameterService->getLanguageUidFromParameters(
            is_array($body) ? $body : [],
            [],
            -1,
            true,
        );
        $this->activeLanguageUidForUrls = $languageUid;

        $expiry = $this->resolveIgnoreExpiry($body);

        if (!$expiry['valid']) {
            $this->addFlashMessage($expiry['error'], ContextualFeedbackSeverity::WARNING, 'Accessibility');
        } elseif ($ruleId !== '' && $reason !== '') {
            $user = $this->getBackendUserSnapshot();
            $ignored = $this->issueRepository->ignoreAllByRuleOnSite(
                $siteIdentifier,
                $languageUid,
                $ruleId,
                $reason,
                $user['uid'],
                $user['name'],
                $user['username'],
                $expiry['ignoredUntil']
            );
            $this->addFlashMessage($ignored . ' issues ignored for rule ' . $ruleId . ' on this site.', ContextualFeedbackSeverity::OK, 'Accessibility');
        } else {
            $this->addFlashMessage('Site-wide rule ignore was not applied. Select a rule and enter a reason.', ContextualFeedbackSeverity::WARNING, 'Accessibility');
        }

        return new RedirectResponse(
            $this->buildPageDetailUrl($pageUid, $siteIdentifier, $status, $severity, $page),
            302
        );
    }

    public function unignoreAction(ServerRequestInterface $request): ResponseInterface
    {
        $accessResponse = $this->ensureBackendUserAccess(
            $this->accessControlService,
            'editRecord',
            $request
        );
        if ($accessResponse !== null) {
            return $accessResponse;
        }

        $body = $request->getParsedBody();
        $issueUid = (int)($body['issueUid'] ?? 0);
        $pageUid = (int)($body['pageUid'] ?? 0);
        $siteIdentifier = (string)($body['siteIdentifier'] ?? '');
        $status = FilterValueUtility::normalizeStatus((string)($body['status'] ?? 'ignored'));
        $severity = FilterValueUtility::normalizeSeverity((string)($body['severity'] ?? 'all'));
        $page = max(1, (int)($body['page'] ?? 1));
        $this->activeLanguageUidForUrls = $this->requestParameterService->getLanguageUidFromParameters(
            is_array($body) ? $body : [],
            [],
            -1,
            true,
        );

        if ($issueUid > 0 && !$this->canEditIssue($issueUid)) {
            $this->addFlashMessage('Access denied.', ContextualFeedbackSeverity::ERROR, 'Accessibility');
        } elseif ($issueUid > 0) {
            $user = $this->getBackendUserSnapshot();
            $this->issueRepository->unignore($issueUid, $user['uid'], $user['name'], $user['username']);
        }

        return new RedirectResponse(
            $this->buildPageDetailUrl($pageUid, $siteIdentifier, $status, $severity, $page),
            302
        );
    }


    private function canEditIssue(int $issueUid): bool
    {
        $context = $this->issueRepository->findAccessContextByUid($issueUid);
        if (!is_array($context)) {
            return false;
        }

        $sourceTable = trim((string)($context['source_table'] ?? ''));
        $sourceUid = (int)($context['source_uid'] ?? 0);
        if ($sourceTable !== '' && $sourceUid > 0) {
            return $this->backendRecordAccessService->canEditRecord($sourceTable, $sourceUid);
        }

        $pageUid = (int)($context['page_uid'] ?? 0);
        return $pageUid > 0 && $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid);
    }

    private function resolveIgnoreExpiry(array $body): array
    {
        $mode = trim((string)($body['ignoreExpiry'] ?? 'never'));
        $today = strtotime('today');

        if ($mode === '7d') {
            return ['valid' => true, 'ignoredUntil' => strtotime('+7 days', $today), 'error' => ''];
        }

        if ($mode === '30d') {
            return ['valid' => true, 'ignoredUntil' => strtotime('+30 days', $today), 'error' => ''];
        }

        if ($mode === '90d') {
            return ['valid' => true, 'ignoredUntil' => strtotime('+90 days', $today), 'error' => ''];
        }

        if ($mode === 'custom') {
            $date = trim((string)($body['ignoreUntilDate'] ?? ''));
            if ($date === '') {
                return ['valid' => false, 'ignoredUntil' => 0, 'error' => 'Select a future reopen date.'];
            }

            $timestamp = strtotime($date . ' 00:00:00');
            if ($timestamp === false || $timestamp <= $today) {
                return ['valid' => false, 'ignoredUntil' => 0, 'error' => 'The reopen date must be at least one day in the future.'];
            }

            return ['valid' => true, 'ignoredUntil' => $timestamp, 'error' => ''];
        }

        return ['valid' => true, 'ignoredUntil' => 0, 'error' => ''];
    }

    private function formatExpiryRelative(int $timestamp): string
    {
        $days = (int)ceil(($timestamp - strtotime('today')) / 86400);
        if ($days <= 0) {
            return 'today';
        }

        return 'in ' . $days . ' day' . ($days === 1 ? '' : 's');
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


    private function currentLanguageUidForRoute(ServerRequestInterface $request): int
    {
        return $this->requestParameterService->getLanguageUidFromParameters(
            $request->getQueryParams(),
            [],
            $this->activeLanguageUidForUrls,
            true,
        );
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
            'language' => $this->currentLanguageUidForRoute($request),
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
            'language' => $this->activeLanguageUidForUrls,
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
