<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Rendered\RenderedPageFetcher;
use Priebera\A11yQualityGate\Scan\RenderedCoverage;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\ExportUrlBuilderService;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationPageSummaryService;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService;
use Priebera\A11yQualityGate\Service\RemotePageScanTargetResolver;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPreviewService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationService;
use Priebera\A11yQualityGate\Service\IssueLocationService;
use Priebera\A11yQualityGate\Service\LocalIssueGuidanceService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScanPermissionService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Priebera\A11yQualityGate\Service\SiteLanguageService;
use Priebera\A11yQualityGate\Utility\FilterValueUtility;
use Priebera\A11yQualityGate\Utility\IssueFilterUtility;
use Priebera\A11yQualityGate\Utility\IssueRuleGroupingUtility;
use Priebera\A11yQualityGate\Utility\OccurrencePresentationUtility;
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
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;

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
        private readonly ScopeAccessService $scopeAccessService,
        private readonly ?ScanPermissionService $scanPermissionService = null,
        private readonly ?IssueLocationService $issueLocationService = null,
        private readonly ?AiSiteSettingsService $aiSiteSettingsService = null,
        private readonly ?RemediationPageSummaryService $remediationSummary = null,
        private readonly ?RemediationAccessService $remediationAccess = null,
        private readonly ?EditorAiAccessService $editorAiAccessService = null,
        private readonly ?RemotePageScanTargetResolver $remotePageScanTargetResolver = null,
        private readonly ?RemoteScanAccessSettingsService $remoteScanAccessSettingsService = null,
        private readonly ?\Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator $aiCostEstimator = null,
        private readonly ?\Priebera\A11yQualityGate\Assignment\AssignmentService $assignmentService = null,
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
        // Page findings are scoped by the page: its own site, never one named by the request, and only
        // for a page the user may read.
        $site = $pageUid > 0
            ? $this->siteResolutionService->resolveSiteByPageId($pageUid)
            : $this->resolveSiteForPage($request, $pageUid);
        if ($pageUid > 0 && !$this->scopeAccessService->canReadPage($pageUid)) {
            return $this->resourceAccessDeniedResponse();
        }

        $this->backendJavaScriptModuleService->loadBackendModule(
            $this->pageRenderer,
            $site
        );
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/image-remediation.js');
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/assignment.js');
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/ai-link-text-suggestion.js');
        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/ai-iframe-title-suggestion.js');
        if ($this->remediationSummary !== null) {
            $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/remediation/page-detail-remediation.js');
            $this->pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/remediation.css');
        }

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
        // The same readiness policy as the server-side check (AiReadinessPolicy via EditorAiAccessService): AI actions
        // appear only when a request would be accepted; otherwise the finding keeps its manual path.
        $aiAvailable = $this->editorAiAccessService !== null
            ? $this->editorAiAccessService->siteState($siteIdentifier) === EditorAiAccessService::STATE_READY
            : $this->aiFeatureAccessService->isAvailable($siteIdentifier);
        // A site that does not allow sending images offers no AI for image findings; editors keep the manual path.
        $aiAltSuggestionAvailable = $aiAvailable
            && ($this->aiSiteSettingsService?->get($siteIdentifier)->allowImageInput ?? true)
            // The user's ai.images switch, which the suggestion endpoint enforces too.
            && ($this->editorAiAccessService?->userAllows(EditorAiAccessService::FLAG_IMAGES) ?? true);
        $aiLinkTextSuggestionAvailable = $aiAvailable
            && $this->aiConfigurationRepository->isLinkTextSuggestionsEnabled($siteIdentifier)
            // N-08: the user's ai.editor switch, which the suggestion services enforce too.
            && ($this->editorAiAccessService?->userAllows(EditorAiAccessService::FLAG_EDITOR) ?? true);
        $aiIframeTitleSuggestionAvailable = $aiLinkTextSuggestionAvailable;

        $pageRecord = $pageUid > 0
            ? (BackendUtility::getRecord('pages', $pageUid, 'uid,title,slug,doktype') ?: [])
            : [];

        $pageTitle = trim((string)($pageRecord['title'] ?? ''));
        $pagePath = trim((string)($pageRecord['slug'] ?? ''));
        $pageDoktype = (int)($pageRecord['doktype'] ?? 0);
        $isFolder = $pageDoktype === 254;

        $backendUser = $this->backendContextService->getBackendUser();
        // The same rule as the scan endpoint: the TSconfig flag and edit access to this page.
        $canScanNow = $this->scanPermissionService !== null
            ? $this->scanPermissionService->canScanPage($pageUid)
            : $this->accessControlService->canShowScanNow($backendUser);
        $canShowSettings = $this->accessControlService->canShowSettings($backendUser);
        $canRemediateImages = $this->accessControlService->canRemediateImages($backendUser);
        // N-03: Ignore/Unignore only for people the server lets ignore (options.a11y_quality_gate.editRecord).
        $canIgnore = $this->accessControlService->canEditRecord($backendUser);

        if ($siteIdentifier !== '') {
            $this->issueRepository->reopenExpiredIgnoredIssues($siteIdentifier);
        }

        $allIssues = ($pageUid > 0 && $siteIdentifier !== '')
            ? $this->issueRepository->findAllForPage($siteIdentifier, $pageUid, $currentLanguageUid, true)
            : [];

        $openRuleCountsOnPage = ($pageUid > 0 && $siteIdentifier !== '')
            ? $this->issueRepository->countOpenByRuleOnPage($siteIdentifier, $pageUid, $currentLanguageUid, true)
            : [];
        $openRuleCountsOnSite = $siteIdentifier !== ''
            ? $this->issueRepository->countOpenByRuleOnSite($siteIdentifier, $currentLanguageUid, true)
            : [];

        $backendLanguageCode = $this->getBackendLanguageCode();
        // The newest completed scan that covered this page (a page scan or a site/subtree scan), for "Last scan" and for
        // rendered findings that scan did not re-check (U-08).
        $coveringScan = RenderedCoverage::newerScan(
            $siteIdentifier !== '' && $pageUid > 0 ? $this->scanRepository->findLastCompletedPageScan($siteIdentifier, $pageUid) : null,
            $siteIdentifier !== '' && $pageUid > 0 ? $this->scanRepository->findLastCompletedSubtreeScanCoveringPage($siteIdentifier, $pageUid) : null
        );
        $notRecheckedLabel = is_array($coveringScan) ? sprintf(
            $this->translateWithFallback('pageDetail.rendered.notRechecked', 'Not re-checked by the last scan (%s): it did not run the rendered check. Scan this page to check it again.'),
            BackendTimeUtility::formatDateTime((int)($coveringScan['finished_at'] ?? 0), 'd.m.Y · H:i')
        ) : '';
        $allIssues = array_map(function (array $row) use (
            $coveringScan,
            $notRecheckedLabel,
            $backendLanguageCode,
            $pageUid,
            $siteIdentifier,
            $activeStatus,
            $activeSeverity,
            $currentPage,
            $openRuleCountsOnPage,
            $openRuleCountsOnSite,
            $canRemediateImages,
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
            $row['ignoredUntilLabel'] = $ignoredUntil > 0 ? date('d.m.Y', $ignoredUntil) : '';
            $row['ignoredUntilRelative'] = $ignoredUntil > 0 ? $this->formatExpiryRelative($ignoredUntil) : '';
            $row['ignoreIsTemporary'] = $ignoredUntil > 0;
            $row['ignoreReopensSoon'] = $ignoredUntil > 0 && $ignoredUntil <= strtotime('+7 days');
            $row['ignoreWasReopened'] = (int)$row['status'] === IssueStatus::Open->value && $ignoredReopenedAt > 0;
            $row['ignoredReopenedAtLabel'] = $ignoredReopenedAt > 0 ? date('d.m.Y', $ignoredReopenedAt) : '';
            $row['hasEditAccess'] = false;
            $row['editLink'] = '';
            $sourceType = trim((string)($row['source_type'] ?? ''));
            if ($sourceType === '') {
                $sourceType = str_starts_with($ruleId, 'structured.') ? 'structured' : (str_starts_with($ruleId, 'rendered.') ? 'rendered' : 'rte');
            }
            $row['sourceType'] = $sourceType;
            $row['renderedNotRechecked'] = RenderedCoverage::isNotRechecked(['source_type' => $sourceType] + $row, $coveringScan) ? $notRecheckedLabel : '';
            $row['sourceTypeLabel'] = match ($sourceType) {
                'rendered' => $this->translateWithFallback('pageDetail.sourceType.rendered', 'Rendered HTML'),
                'structured' => $this->translateWithFallback('pageDetail.sourceType.structured', 'Structured content'),
                'remote' => $this->translateWithFallback('pageDetail.sourceType.remote', 'Frontend crawler'),
                default => $this->translateWithFallback('pageDetail.sourceType.content', 'Content source'),
            };
            $row['sourceHint'] = $sourceType === 'rendered' && $sourceTable === 'pages'
                ? $this->translateWithFallback('pageDetail.sourceHint.rendered', 'Likely: template, layout or plugin output')
                : '';
            $row['guidance'] = $this->localIssueGuidanceService->present($row, $backendLanguageCode);
            // The stored rule message is English; other backend languages show the translated rule title when one exists.
            $localizedTitle = (string)($row['guidance']['localizedTitle'] ?? '');
            $row['displayTitle'] = $backendLanguageCode !== 'en' && $localizedTitle !== ''
                ? $localizedTitle
                : (string)($row['message'] ?? '');
            // Audit D3: the card is titled with the plain rule title in every language; an occurrence keeps its stored
            // message as its "what" only when that adds information.
            $row['ruleTitle'] = $localizedTitle !== '' ? $localizedTitle : (string)($row['message'] ?? '');
            $row['occurrenceWhat'] = OccurrencePresentationUtility::occurrenceWhat(
                (string)($row['message'] ?? ''),
                $row['ruleTitle'],
                $backendLanguageCode
            );
            $row['aiLinkTextSuggestionAvailable'] = false;
            $row['aiIframeTitleSuggestionAvailable'] = false;
            $row = $this->addOccurrencePresentation($row, $sourceTable, $sourceUid, $sourceType);

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
            $row['imageFileName'] = '';
            if ($this->offersImageRemediation($row, $canRemediateImages)) {
                try {
                    $imageContext = $this->imageFindingContextResolver->resolve((int)$row['uid']);
                    $row['imageRemediationAvailable'] = $this->canEditImageReference($imageContext);
                    $row['imageRemediationVersion'] = $this->imageFindingVersionTokenService->create($imageContext);
                    $row['imageIsDecorative'] = (int)($imageContext->fileReference['tx_a11y_is_decorative'] ?? 0) === 1;
                    $row['imageAlternative'] = trim((string)($imageContext->fileReference['alternative'] ?? ''));
                    $preview = $this->imageRemediationPreviewService->build($imageContext);
                    $row['imagePreviewAvailable'] = $preview['available'];
                    $row['imagePreviewUrl'] = $preview['url'];
                    $row['imagePreviewPath'] = $preview['displayPath'];
                    $row['imageFileName'] = $preview['fileName'];
                    $row['aiAltSuggestionAvailable'] = $aiAltSuggestionAvailable;
                } catch (\Throwable) {
                }
            }

            return $row;
        }, $allIssues);

        // Remediation workflow (Package 4): owner, fix path and queue state per finding, from stored data only.
        $remediation = $this->buildRemediationContext($siteIdentifier, $pageUid, $allIssues);
        // AQG-300-FEAT-02/03: the most one request of each AI action can cost, shown inside its button. Empty when no
        // reliable price exists; the button then keeps its plain label.
        $aiCost = $aiAvailable ? $this->aiCostLabels($siteIdentifier, $backendLanguageCode) : [];
        $explainTextAllowed = $aiLinkTextSuggestionAvailable;
        $allIssues = array_map(
            function (array $row) use ($remediation): array {
                // "Edit record" needs the record; an AI action changes or explains one field, which can be read-only
                // (TCA, TSconfig, inline parent): the same field boundary the AI routes enforce decides what is offered.
                if (($remediation['active'] ?? false) && ($row['hasEditAccess'] ?? false) && (string)($row['source_type'] ?? '') === 'rte') {
                    $row['aiFieldEditable'] = $this->backendRecordAccessService->canEditRecordFields(
                        (string)($row['source_table'] ?? ''),
                        (int)($row['source_uid'] ?? 0),
                        [(string)($row['source_field'] ?? '')],
                    );
                }

                return $row;
            },
            $allIssues
        );
        $allIssues = array_map(
            static fn (array $row): array => self::withAiActions($row, $remediation, $aiCost, $explainTextAllowed),
            $allIssues
        );

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

        // List pages hold whole rule groups (audit T3): a rule is never cut across two list pages.
        $listPages = IssueRuleGroupingUtility::paginateByRule($visibleIssues, self::PER_PAGE);
        $pagination = $this->buildPagination(
            totalItems: count($visibleIssues),
            currentPage: $currentPage,
            perPage: self::PER_PAGE,
            totalPages: count($listPages),
        );

        $paginatedIssues = $listPages[$pagination['currentPage'] - 1] ?? [];

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

        // One card per rule within each severity, for the issues of this list page only.
        $ruleGroups = IssueRuleGroupingUtility::group($paginatedIssues, $visibleIssues);
        // My issues: who works on each issue type of this page, and whether this person may (re)assign it.
        $assignmentChips = $this->assignmentService?->chipsForPage($siteIdentifier, $pageUid, $currentLanguageUid) ?? [];
        $canAssign = $this->assignmentService?->canAssignOn($pageUid, max(0, $currentLanguageUid)) ?? false;
        // N-01: a template card is one shared task of the site; assigning it needs edit access to the site root.
        $canAssignTemplate = $this->assignmentService?->canAssignTemplateOn($siteIdentifier, $pageUid) ?? false;
        foreach ($ruleGroups as $groupKey => $group) {
            foreach (($group['rules'] ?? []) as $ruleKey => $rule) {
                $ruleGroups[$groupKey]['rules'][$ruleKey]['assignment'] = $assignmentChips[(string)($rule['issueKey'] ?? '')] ?? null;
                $ruleGroups[$groupKey]['rules'][$ruleKey]['canAssign'] = !empty($rule['sharedTemplate']) ? $canAssignTemplate : $canAssign;
            }
        }

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
            $activeSeverity,
            $currentLanguageUid,
        );

        $exportPdfUrl = $this->exportUrlBuilderService->buildLocalPagePdfUrl(
            $siteIdentifier,
            $pageUid,
            $activeStatus,
            $activeSeverity,
            $currentLanguageUid,
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
        // "Last scan" is the newest scan that covered this page, a site scan included (2026-11-05 company acceptance:
        // Page Detail showed the last single-page scan, or "—", while the Page module showed the later site scan).
        // Audit C13: when that scan could not check its rendered HTML, say so and why.
        $lastScan = $coveringScan;
        $renderedSkip = is_array($coveringScan) ? RenderedCoverage::skippedEntryFor($coveringScan, $pageUid) : null;
        if ($renderedSkip !== null) {
            $renderedSkip['message'] = $this->localizedRenderedSkipMessage($renderedSkip['message'], $backendLanguageCode);
        }
        $localDetail = [
            'lastScanAt' => is_array($lastScan) ? (int)($lastScan['finished_at'] ?? 0) : 0,
            'lastScanAtFormatted' => is_array($lastScan) ? BackendTimeUtility::formatDateTime((int)($lastScan['finished_at'] ?? 0), 'd.m.Y · H:i') : '—',
            'renderedSkippedNote' => $renderedSkip === null ? '' : sprintf(
                $this->translateWithFallback('pageDetail.renderedSkipped', 'Rendered HTML not checked in the last scan: %s'),
                RenderedCoverage::describe(
                    $renderedSkip,
                    fn (string $key, string $fallback): string => $this->translateWithFallback($key, $fallback)
                )
            ),
            // A page visitors do not get cannot be opened by the rendered check; say which scan can check it.
            'renderedSkippedHint' => $renderedSkip !== null && $renderedSkip['reason'] !== ''
                ? $this->translateWithFallback(
                    'pageDetail.renderedSkipped.notPublicHint',
                    'The rendered check requests the page as a visitor, without the Scanner Token. Its content checks ran; a Frontend scan with a Scanner Token can check hidden pages.'
                )
                : '',
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
            'ruleGroups' => $ruleGroups,
            'canAssign' => $canAssign,
            'assignmentUrls' => $canAssign ? [
                'assign' => $this->buildRouteUrl('ajax_a11y_assignment_assign'),
                'candidates' => $this->buildRouteUrl('ajax_a11y_assignment_candidates'),
            ] : null,
            'severityCounts' => $severityCounts,
            'statusCounts' => $statusCounts,
            // M-01: results of a language the user may not use are filtered out — the page is not "clean" for them.
            'languageUnavailable' => !$this->scopeAccessService->canUseLanguage($currentLanguageUid),
            // STATE-01: "This page is clean" only for a verified zero; otherwise say what is missing.
            'localEvidence' => $this->localEvidenceView($siteIdentifier, $pageUid, $currentLanguageUid),
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
            'canIgnoreRuleOnSite' => $canShowSettings && $canIgnore,
            'canIgnore' => $canIgnore,
            'unignoreUrl' => $unignoreUrl,
            'exportCsvUrl' => $exportCsvUrl,
            'exportPdfUrl' => $exportPdfUrl,
            'pagination' => $pagination,
            'paginationItems' => $paginationItems,
            'canScanNow' => $canScanNow,
            'canCancelLocalScan' => (bool)($scanStatus['running'] ?? false) && $this->scanPermissionService instanceof ScanPermissionService
                ? $this->scanPermissionService->canCancelLocalScan($scanStatus)
                : $canScanNow,
            'canShowSettings' => $canShowSettings,
            'scanStatus' => $this->scopeAccessService->restrictLocalScanStatus($scanStatus),
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
            'remediation' => $remediation,
            'previewScan' => $this->buildPreviewScanAccess($proStatus, $siteIdentifier, $pageUid, $currentLanguageUid, $canScanNow, $canShowSettings, $returnParameters),
        ]);

        return $moduleTemplate->renderResponse('PageDetail/Show');
    }

    /**
     * Alt text, decorative marking and AI alt suggestions need the image remediation capability (administrators, or
     * TSconfig options.a11y_quality_gate.allowImageRemediation) as well as edit access; the endpoints refuse anyone
     * else, so the actions are offered only then.
     *
     * @param array<string, mixed> $row
     */
    /**
     * The page's remediation context for the template and page-detail-remediation.js. Without the services (tests,
     * older wiring) or without a licence the block is inactive and the page renders exactly as before.
     *
     * @param list<array<string,mixed>> $issues
     * @return array<string,mixed>
     */
    private function buildRemediationContext(string $siteIdentifier, int $pageUid, array $issues): array
    {
        $inactive = ['active' => false, 'findings' => [], 'aiReady' => false, 'images' => false, 'canChangeOwner' => false];
        if ($this->remediationSummary === null || $this->remediationAccess === null || $siteIdentifier === '' || $pageUid <= 0
            || !$this->remediationAccess->hasLicence($siteIdentifier)) {
            return $inactive;
        }
        $summary = $this->remediationSummary->summarize($siteIdentifier, $pageUid, $issues);
        $aiState = $this->remediationAccess->aiState($siteIdentifier);
        $aiReady = $aiState === 'ready';
        $canBulk = $aiReady && $this->remediationAccess->mayUseBulk();
        $config = [
            'pageUid' => $pageUid,
            'canBulk' => $canBulk,
            'maxBatch' => \Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService::MAX_BATCH,
            'urls' => [
                'prepare' => $this->buildRouteUrl('ajax_a11y_remediation_prepare'),
                'explain' => $this->buildRouteUrl('ajax_a11y_remediation_explain'),
                'generate' => $this->buildRouteUrl('ajax_a11y_remediation_generate'),
                'item' => $this->buildRouteUrl('ajax_a11y_remediation_item'),
                'owner' => $this->buildRouteUrl('ajax_a11y_remediation_owner'),
                'plan' => $this->buildRouteUrl('ajax_a11y_remediation_plan'),
            ],
            'queueUrl' => $this->buildRouteUrl('web_a11y.remediation', ['id' => $pageUid, 'pageUid' => $pageUid]),
            // The summary's queue line is updated in place after "Fix with AI" (F-32).
            'queue' => $summary['queue'],
            'labels' => $this->remediationLabels(),
        ];

        return [
            'active' => true,
            'findings' => $summary['findings'],
            'owners' => $summary['owners'],
            'queue' => $summary['queue'],
            'aiReady' => $aiReady,
            // M-04: why the AI actions are missing (budget used up, site paused) instead of buttons that just vanish.
            'aiState' => $aiState,
            // Image AI needs the user's ai.images right and the site's privacy switch: never offer what must fail.
            'images' => $this->remediationAccess->mayUseImages()
                && ($this->aiSiteSettingsService?->get($siteIdentifier)->allowImageInput ?? true),
            'canBulk' => $canBulk,
            'canChangeOwner' => $this->remediationAccess->canEditPage($pageUid),
            'queueUrl' => $config['queueUrl'],
            'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * The cost hints of the AI actions on this page ("<$0.02"), keyed explain / fixText / fixImage / linkText /
     * iframeTitle, plus 'hint' for the screen-reader description. Empty without a reliable price.
     *
     * @return array<string,string>
     */
    private function aiCostLabels(string $siteIdentifier, string $backendLanguageCode): array
    {
        if ($this->aiCostEstimator === null || $siteIdentifier === '') {
            return [];
        }
        $separator = $backendLanguageCode === 'de' ? ',' : '.';
        $labels = [];
        foreach ([
            'explain' => \Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator::ACTION_EXPLAIN,
            'fixText' => \Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator::ACTION_FIX_TEXT,
            'fixImage' => \Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator::ACTION_FIX_IMAGE,
            'linkText' => \Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator::ACTION_LINK_TEXT,
            'iframeTitle' => \Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator::ACTION_IFRAME_TITLE,
        ] as $key => $action) {
            $micro = $this->aiCostEstimator->maxCostMicro($siteIdentifier, $action);
            if ($micro !== null) {
                $labels[$key] = sprintf(
                    $this->translateWithFallback('ai.cost.max', '<$%s'),
                    \Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator::formatUpperBound($micro, $separator)
                );
            }
        }
        if ($labels !== []) {
            $labels['hint'] = $this->translateWithFallback('ai.cost.hint', 'Estimated maximum for one AI request. Your AI provider bills the actual usage, which is usually lower.');
        }

        return $labels;
    }

    /** @return array<string,string> */
    private function remediationLabels(): array
    {
        $labels = [];
        foreach ([
            'pd.preparing', 'pd.ready', 'pd.failed', 'pd.error', 'pd.bulkPrepare', 'pd.bulkPrepareConfirm', 'pd.bulkProgress', 'pd.bulkWaiting',
            'pd.bulkDone', 'pd.bulkDoneOne', 'pd.bulkCancel', 'pd.bulkNone', 'pd.review', 'pd.queued', 'pd.copied', 'owner.saved', 'plan.title', 'plan.intro',
            'plan.empty', 'plan.copy', 'plan.copied', 'plan.close', 'plan.occurrences', 'plan.queued', 'pd.summaryQueue',
            'plan.occurrence', 'plan.goTo', 'plan.sendEditor', 'plan.copyTicket', 'plan.step', 'plan.steps',
            'pd.explaining', 'pd.explanation', 'pd.explainHowToFix', 'pd.explainNote', 'pd.explainNeedsReview', 'pd.explainSent',
            'pd.explainSentField.elementSummary', 'pd.explainSentField.surroundingText', 'pd.explainSentField.pageTitle', 'pd.explainModel',
            'pd.result.explainTitle', 'pd.result.explains', 'pd.result.problem', 'pd.result.howToFix', 'pd.result.nothingChanged',
            'pd.result.explainFailedTitle', 'pd.result.preparingText', 'pd.result.readyTitle', 'pd.result.readyText', 'pd.result.reviewAction',
            'pd.result.noneTitle', 'pd.result.noneText', 'pd.result.failedTitle', 'pd.result.failedText', 'pd.result.unavailableTitle',
            'pd.result.unavailableText', 'pd.result.queuedTitle', 'pd.result.queuedText', 'pd.result.openItem', 'pd.result.editRecord',
            'pd.result.retry', 'pd.result.waiting', 'pd.usage.notSent', 'pd.usage.reported', 'pd.usage.bound', 'pd.usage.none',
            'pd.usage.unknown', 'pd.usage.mayBill', 'pd.usage.unconfirmed',
        ] as $key) {
            $labels[$key] = $this->translate('remediation.' . $key);
        }
        foreach (['session_expired', 'permission_denied', 'rate_limited', 'generic', 'no_licence', 'invalid_state', 'in_progress', 'not_found',
            'stale', 'finding_closed', 'unsupported_rule', 'provider_unavailable', 'provider_timeout', 'provider_rate_limited', 'provider_credit', 'refused',
            'paused', 'budget_reached', 'not_configured', 'no_permission', 'link_text_disabled', 'invalid_response'] as $code) {
            $labels['error.' . $code] = $this->translate('remediation.js.error.' . $code);
        }
        foreach (['editor', 'developer', 'mixed', 'unassigned'] as $owner) {
            $labels['owner.' . $owner] = $this->translate('remediation.owner.' . $owner);
        }
        // Why "Fix with AI" prepared nothing for an occurrence (prepare skip reasons, Failed / No suggestion reasons).
        foreach (['image_missing', 'image_external', 'image_not_in_storage', 'image_not_image', 'image_no_access', 'image_unreadable',
            'image_unavailable', 'no_edit_access', 'privacy_images', 'link_text_disabled', 'manual_only', 'no_safe_action', 'rendered_output',
            'rule_based_in_editor', 'finding_closed', 'target_changed', 'target_missing', 'budget_reached', 'ai_paused', 'provider_not_configured',
            'provider_refused', 'provider_timeout', 'provider_rate_limited', 'provider_credit', 'provider_paused', 'provider_unavailable',
            'invalid_response', 'needs_review', 'decorative', 'default_fits', 'duplicate', 'expired'] as $reason) {
            $labels['reason.' . $reason] = $this->translate('remediation.reason.' . $reason);
        }

        return $labels;
    }

    /**
     * F-18: the scan stores the fetch error as text. Known errors are shown from their label in the backend language
     * (the stored text stays for logs and older scans); an unknown text in a non-English backend gets a generic label.
     */
    private function localizedRenderedSkipMessage(string $message, string $backendLanguageCode): string
    {
        if ($message === '') {
            return '';
        }
        $key = RenderedPageFetcher::errorKeyForMessage($message);
        if ($key !== '') {
            $label = $this->translateWithFallback('renderedCheck.error.' . $key, $message);

            return str_contains($label, '%d') && preg_match('/\d+/', $message, $status) === 1
                ? sprintf($label, (int)$status[0])
                : $label;
        }

        return $backendLanguageCode === 'en'
            ? $message
            : $this->translateWithFallback('renderedCoverage.reason.fetchFailed', 'The rendered page could not be checked.');
    }

    /**
     * Atlas 09·A: a page visitors cannot open gets a "Page not public" card. A paid frontend scan of such a page is a
     * preview scan (ProCrawlerAjaxController, decided server-side from the stored page), so the card starts the same
     * single-page scan as the Overview's "Scan this page" — only when that scan can work: crawler entitlement, the scan
     * permission, a Scanner Token for the site and a restriction the token can open (hidden, not scheduled or
     * group-restricted). Otherwise it says why, without a button.
     *
     * @param array<string,mixed> $returnParameters
     * @return array<string,mixed>|null
     */
    private function buildPreviewScanAccess(
        object $proStatus,
        string $siteIdentifier,
        int $pageUid,
        int $languageUid,
        bool $canScanNow,
        bool $canShowSettings,
        array $returnParameters,
    ): ?array {
        if ($this->remotePageScanTargetResolver === null || $siteIdentifier === '' || $pageUid <= 0) {
            return null;
        }
        $languageUid = max(0, $languageUid);
        $restriction = $this->remotePageScanTargetResolver->visitorRestriction($pageUid, $languageUid);
        if ($restriction === '') {
            return null;
        }
        $hasCrawler = (bool)($proStatus->valid ?? false) && (bool)($proStatus->hasCrawler ?? false);
        $supported = $this->remotePageScanTargetResolver->unsupportedPreviewRestriction($pageUid, $languageUid) === '';
        $hasToken = $this->remoteScanAccessSettingsService?->hasScannerTokenForSite($siteIdentifier) ?? false;
        $state = match (true) {
            !$hasCrawler => 'free',
            !$supported => 'unsupported',
            !$hasToken => 'noToken',
            !$canScanNow => 'noPermission',
            default => 'available',
        };

        return [
            'state' => $state,
            'restriction' => $restriction,
            'languageUid' => $languageUid,
            'settingsUrl' => $state === 'noToken' && $canShowSettings
                ? $this->buildRouteUrl('web_a11y.settings', array_replace($returnParameters, ['tab' => 'remote_access', 'rulesetSite' => $siteIdentifier]))
                : '',
        ];
    }

    /**
     * The AI actions of one occurrence: "Fix with AI" (prepares an AI fixes item), "Explain with AI" (explanation only)
     * and their cost hints. The server checks every gate again; this only decides what Page Detail offers.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $remediation buildRemediationContext()
     * @param array<string,string> $aiCost aiCostLabels()
     * @return array<string,mixed>
     */
    public static function withAiActions(array $row, array $remediation, array $aiCost, bool $textSuggestionsAllowed): array
    {
        $row['aiCost'] = $aiCost;
        $row['remediation'] = ($remediation['findings'][(int)$row['uid']] ?? null);
        if (is_array($row['remediation'])) {
            $ruleId = (string)($row['rule_id'] ?? '');
            $kind = (string)(\Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog::entry($ruleId)['kind'] ?? '');
            // AQG-300-UX-04: "Explain with AI" for the rich-text rules the editors explain, with the editors' own
            // gates (licence, AI ready, edit access to the field; link and iframe findings need the site's text
            // suggestion switch). The server checks every one of them again.
            $row['remediation']['canExplain'] = $remediation['aiReady']
                && (int)$row['status'] === IssueStatus::Open->value
                && (string)($row['source_type'] ?? '') === 'rte'
                && $kind !== ''
                && (bool)($row['hasEditAccess'] ?? false)
                && (bool)($row['aiFieldEditable'] ?? true)
                && (!in_array($kind, [\Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog::KIND_LINK_TEXT, \Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog::KIND_IFRAME_TITLE], true) || $textSuggestionsAllowed);
            $row['remediation']['explainCost'] = $aiCost['explain'] ?? '';
            // Image alt texts send the image, also for alt texts in rich text (FIX_AI_TEXT path, image_alt kind).
            $sendsImage = ($row['remediation']['fixPath'] ?? '') === RemediationPageSummaryService::FIX_AI_IMAGE
                || $kind === \Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog::KIND_IMAGE_ALT;
            $row['remediation']['fixCost'] = $sendsImage ? ($aiCost['fixImage'] ?? '') : ($aiCost['fixText'] ?? '');
            $row['remediation']['canPrepare'] = $remediation['aiReady']
                && (int)$row['status'] === IssueStatus::Open->value
                && in_array($row['remediation']['fixPath'], [RemediationPageSummaryService::FIX_AI_IMAGE, RemediationPageSummaryService::FIX_AI_TEXT], true)
                && $row['remediation']['item'] === null
                && (bool)($row['hasEditAccess'] ?? false)
                && (bool)($row['aiFieldEditable'] ?? true)
                && (!$sendsImage || $remediation['images'])
                // Link text and iframe titles need the site's AI link/iframe switch (prepareRefusal checks it too).
                && (!in_array($kind, [\Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog::KIND_LINK_TEXT, \Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog::KIND_IFRAME_TITLE], true) || $textSuggestionsAllowed);
            // Atlas 07·A: one AI entry per occurrence. An occurrence whose card already has the inline suggestion
            // (alt text, link text, iframe title) shows no per-occurrence queue button; it stays eligible for the
            // bulk "Prepare AI suggestions" path (07·B).
            $row['remediation']['inlineAi'] = self::hasInlineAiFlow($row);
            $row['remediation']['canChangeOwner'] = $remediation['canChangeOwner'];
        }

        return $row;
    }

    /**
     * Whether the occurrence card offers its own inline AI suggestion (Suggest → review → apply or copy).
     *
     * @param array<string,mixed> $row
     */
    public static function hasInlineAiFlow(array $row): bool
    {
        return ((bool)($row['imageRemediationAvailable'] ?? false)
                && (bool)($row['aiAltSuggestionAvailable'] ?? false)
                && !(bool)($row['imageIsDecorative'] ?? false))
            || (bool)($row['aiLinkTextSuggestionAvailable'] ?? false)
            || (bool)($row['aiIframeTitleSuggestionAvailable'] ?? false);
    }

    private function offersImageRemediation(array $row, bool $canRemediateImages): bool
    {
        return $canRemediateImages
            && (bool)($row['hasEditAccess'] ?? false)
            && $this->imageFindingContextResolver->supportsIssueRow($row);
    }

    /**
     * The field permissions the endpoints check (ImageRemediationPermissionService::assertCanModify()): an editor whose
     * groups may not edit the content element or the image reference's alternative text gets no actions. In a workspace
     * the endpoints refuse every write (M-1), so the actions are not offered there either.
     */
    private function canEditImageReference(ImageFindingContext $context): bool
    {
        $backendUser = $this->backendContextService->getBackendUser();
        if ($backendUser instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
            && (int)($backendUser->workspace ?? 0) !== 0) {
            return false;
        }

        return $this->backendRecordAccessService->canEditRecordFields($context->sourceTable, $context->sourceUid)
            && $this->backendRecordAccessService->canEditRecordFields(
                Tables::SYS_FILE_REFERENCE,
                $context->fileReferenceUid,
                ImageRemediationService::ALT_TEXT_FIELDS,
            );
    }

    /**
     * What an editor reads first in an occurrence row: the content element (title, type, column), the offending markup
     * in one line, and a checkbox name that says which finding it selects. Table, uid, field, selector and full snippet
     * stay in the occurrence's technical details.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function addOccurrencePresentation(array $row, string $sourceTable, int $sourceUid, string $sourceType): array
    {
        $sourceField = (string)($row['source_field'] ?? '');
        $location = $this->issueLocationService?->describe($sourceTable, $sourceUid, $sourceField) ?? [
            'title' => $sourceTable === 'tt_content'
                ? sprintf($this->translateWithFallback('pageDetail.contentElement', 'Content element #%s'), (string)$sourceUid)
                : trim($sourceTable . ' #' . $sourceUid),
            'context' => '',
            'fieldLabel' => $sourceField,
            'isContentElement' => $sourceTable === 'tt_content',
            'isPageLevel' => $sourceTable === 'pages',
        ];
        $row['location'] = $location;

        // Structured findings store a description ("sys_file_reference uid:…, file: …"), not markup: it stays technical.
        $snippet = (string)($row['context_snippet'] ?? '');
        $row['snippetPreview'] = $sourceType !== 'structured'
            ? OccurrencePresentationUtility::snippetPreview($snippet)
            : '';

        $cssSelector = trim((string)($row['css_selector'] ?? ''));
        $contextPath = trim((string)($row['context_path'] ?? ''));
        $row['technicalPath'] = $contextPath !== '' && $contextPath !== $cssSelector ? $contextPath : '';

        $row['selectLabel'] = sprintf(
            $this->translateWithFallback('pageDetail.bulk.selectOccurrenceIn', 'Select “%1$s” in %2$s'),
            (string)($row['displayTitle'] ?? $row['message'] ?? ''),
            (string)$location['title']
        );

        return $row;
    }

    /**
     * STATE-01: whether the page's zero of readable findings is a verified clean result (ResultEvidenceState): a completed
     * scan covered this page in this language (a restricted site scan does not cover the pages it left out), its rendered
     * check could fetch the page, and the user sees every result (administrators; other users see the findings they may
     * read, never proof that none exist).
     *
     * @return array{state: string, clean: bool, limited: bool, title: string, text: string}
     */
    private function localEvidenceView(string $siteIdentifier, int $pageUid, int $languageUid): array
    {
        $covering = $siteIdentifier !== '' && $pageUid > 0
            ? RenderedCoverage::newerScan(
                $this->scanRepository->findLastCompletedPageScan($siteIdentifier, $pageUid, $languageUid),
                $this->scanRepository->findLastCompletedSubtreeScanCoveringPage($siteIdentifier, $pageUid, $languageUid)
            )
            : null;
        $skip = is_array($covering) ? RenderedCoverage::skippedEntryFor($covering, $pageUid) : null;
        $backendUser = $this->backendContextService->getBackendUser();
        $state = \Priebera\A11yQualityGate\Service\ResultEvidenceState::resolve(
            running: false,
            hasEvidence: true,
            visibleOpen: 0,
            restricted: !($backendUser instanceof \TYPO3\CMS\Core\Authentication\BackendUserAuthentication && $backendUser->isAdmin()),
            complete: is_array($covering) && !(is_array($skip) && (string)($skip['reason'] ?? '') === ''),
        );

        return [
            'state' => $state,
            'clean' => $state === \Priebera\A11yQualityGate\Service\ResultEvidenceState::CLEAN,
            'limited' => $state !== \Priebera\A11yQualityGate\Service\ResultEvidenceState::CLEAN,
            'title' => $state === \Priebera\A11yQualityGate\Service\ResultEvidenceState::INCOMPLETE
                ? $this->translateWithFallback('pageDetail.limited.incomplete.title', 'This page was not fully checked')
                : $this->translateWithFallback('pageDetail.limited.restricted.title', 'No open issues in the results you can access'),
            'text' => $state === \Priebera\A11yQualityGate\Service\ResultEvidenceState::INCOMPLETE
                ? $this->translateWithFallback('pageDetail.limited.incomplete.text', 'The last scan in this language did not cover this page completely (it was left out, or its rendered HTML could not be fetched). Scan this page to check it.')
                : $this->translateWithFallback('pageDetail.limited.restricted.text', 'You see the results of the records and pages you may access. This does not show that the page is free of issues.'),
        ];
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
            $this->addFlashMessage($expiry['error'], ContextualFeedbackSeverity::WARNING, $this->flashTitle());
        } elseif ($issueUid > 0 && !$this->canEditIssue($issueUid)) {
            $this->addFlashMessage($this->translateWithFallback('pageDetail.flash.accessDenied', 'Access denied.'), ContextualFeedbackSeverity::ERROR, $this->flashTitle());
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
            $this->addFlashMessage($expiry['error'], ContextualFeedbackSeverity::WARNING, $this->flashTitle());
        } elseif (!$this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid)) {
            $this->addFlashMessage($this->translateWithFallback('pageDetail.flash.accessDenied', 'Access denied.'), ContextualFeedbackSeverity::ERROR, $this->flashTitle());
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
            $this->addFlashMessage($this->ignoreCount('pageDetail.flash.ignored', $ignored, '%d issue ignored.', '%d issues ignored.'), ContextualFeedbackSeverity::OK, $this->flashTitle());
        } else {
            $this->addFlashMessage($this->translateWithFallback('pageDetail.flash.nothingIgnored', 'No issues were ignored. Select issues and enter a reason.'), ContextualFeedbackSeverity::WARNING, $this->flashTitle());
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
            $this->addFlashMessage($expiry['error'], ContextualFeedbackSeverity::WARNING, $this->flashTitle());
        } elseif (!$this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid)) {
            $this->addFlashMessage($this->translateWithFallback('pageDetail.flash.accessDenied', 'Access denied.'), ContextualFeedbackSeverity::ERROR, $this->flashTitle());
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
            $this->addFlashMessage(sprintf($this->ignoreCount('pageDetail.flash.ignoredRulePage', $ignored, '%1$d issue of “%2$s” ignored on this page.', '%1$d issues of “%2$s” ignored on this page.', false), $ignored, $this->ruleTitle($ruleId)), ContextualFeedbackSeverity::OK, $this->flashTitle());
        } else {
            $this->addFlashMessage($this->translateWithFallback('pageDetail.flash.ruleNotIgnored', 'Rule ignore was not applied. Select a rule and enter a reason.'), ContextualFeedbackSeverity::WARNING, $this->flashTitle());
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

        // A site-wide ignore changes findings on every page of the site, so it needs the site root.
        if (!$this->scopeAccessService->canEditSite($this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier))) {
            return $this->resourceAccessDeniedResponse();
        }

        if (!$expiry['valid']) {
            $this->addFlashMessage($expiry['error'], ContextualFeedbackSeverity::WARNING, $this->flashTitle());
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
                $expiry['ignoredUntil'],
                $this->scopeAccessService->contentEditablePagesClause(),
            );
            $this->addFlashMessage(sprintf($this->ignoreCount('pageDetail.flash.ignoredRuleSite', $ignored, '%1$d issue of “%2$s” ignored on this site.', '%1$d issues of “%2$s” ignored on this site.', false), $ignored, $this->ruleTitle($ruleId)), ContextualFeedbackSeverity::OK, $this->flashTitle());
        } else {
            $this->addFlashMessage($this->translateWithFallback('pageDetail.flash.siteRuleNotIgnored', 'Site-wide rule ignore was not applied. Select a rule and enter a reason.'), ContextualFeedbackSeverity::WARNING, $this->flashTitle());
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
            $this->addFlashMessage($this->translateWithFallback('pageDetail.flash.accessDenied', 'Access denied.'), ContextualFeedbackSeverity::ERROR, $this->flashTitle());
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

    /** Ignore feedback in the backend language with the right singular or plural (product acceptance: "1 issues ignored"). */
    private function ignoreCount(string $key, int $count, string $singular, string $plural, bool $format = true): string
    {
        $text = $count === 1
            ? $this->translateWithFallback($key . '.singular', $singular)
            : $this->translateWithFallback($key . '.plural', $plural);

        return $format ? sprintf($text, $count) : $text;
    }

    private function ruleTitle(string $ruleId): string
    {
        return $this->translateWithFallback('rule.' . $ruleId . '.title', $ruleId);
    }

    private function flashTitle(): string
    {
        return $this->translateWithFallback('pageDetail.flash.title', 'Accessibility');
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
                return ['valid' => false, 'ignoredUntil' => 0, 'error' => $this->translateWithFallback('pageDetail.flash.expiryRequired', 'Select a future reopen date.')];
            }

            $timestamp = strtotime($date . ' 00:00:00');
            if ($timestamp === false || $timestamp <= $today) {
                return ['valid' => false, 'ignoredUntil' => 0, 'error' => $this->translateWithFallback('pageDetail.flash.expiryTooSoon', 'The reopen date must be at least one day in the future.')];
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
    private function buildPagination(int $totalItems, int $currentPage, int $perPage, ?int $totalPages = null): array
    {
        $totalPages = max(1, $totalPages ?? (int)ceil($totalItems / $perPage));
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
        $summary = sprintf(
            $this->translate($visibleCount === 1
                ? 'pageDetail.filterSummary.template.singular'
                : 'pageDetail.filterSummary.template.plural'),
            $visibleCount
        );

        // The active filters are appended as their own labels, so no language has to inflect them in a sentence.
        $filterKeys = [
            match ($activeStatus) {
                'ignored' => 'issue.status.ignored',
                'resolved' => 'issue.status.resolved',
                'all' => null,
                default => 'issue.status.open',
            },
            match ($activeSeverity) {
                'critical' => 'severity.critical',
                'warning' => 'severity.warning',
                'info' => 'severity.info',
                'needs_review' => 'severity.needsReview',
                default => null,
            },
        ];

        foreach (array_filter($filterKeys) as $filterKey) {
            $summary .= ' · ' . $this->translate($filterKey);
        }

        return $summary;
    }
}
