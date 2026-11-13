<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationChangeLogService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationOwner;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationSiteOptions;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use Priebera\A11yQualityGate\Utility\BackendLocaleFormatter;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Accessibility › AI fixes: the remediation queue of the selected site (or of every site the person can read when no
 * page is selected). The server renders the frame, the filters and the states that do not depend on items; the list,
 * the review drawer and the bulk review are loaded and decided through RemediationAjaxController.
 */
#[AsController]
final class RemediationQueueController extends AbstractBackendModuleController
{
    /** Queue views (Atlas 08: All open · My queue · Done · Change log); ?view= opens one directly. */
    private const VIEWS = ['open', 'mine', 'done', 'log'];

    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly RemediationAccessService $access,
        private readonly SiteFinder $siteFinder,
        private readonly AiBudgetService $budgetService,
        private readonly ?\Priebera\A11yQualityGate\Domain\Repository\IssueRepository $issueRepository = null,
        private readonly ?EditorAiAccessService $editorAiAccess = null,
        private readonly ?\Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService $aiSiteSettings = null,
    ) {
        parent::__construct($moduleTemplateFactory, $uriBuilder, $iconFactory, $backendContextService, $siteResolutionService, $requestParameterService);
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);
        $this->setModuleTitle($moduleTemplate, 'module.title', 'remediation.title');
        $queryParams = $request->getQueryParams();
        $pageUid = max(0, (int)($queryParams['pageUid'] ?? 0));
        $selectedId = max(0, (int)($queryParams['id'] ?? 0));
        if ($pageUid > 0 && !$this->access->canReadPage($pageUid)) {
            return $this->resourceAccessDeniedResponse();
        }

        $selectedSite = $this->siteForPage($pageUid > 0 ? $pageUid : $selectedId);
        // N-09 (review F-02): like the queue AJAX, a site counts when the person can read its root or a mount inside it;
        // items are still filtered per page, record, language, workspace and creator.
        $readableSites = array_values(array_filter(
            $this->siteFinder->getAllSites(),
            fn (Site $site): bool => $this->access->canListSiteIdentifier($site->getIdentifier()),
        ));
        $scopeSites = $selectedSite !== null ? [$selectedSite] : $readableSites;
        $licensedSites = array_values(array_filter($scopeSites, fn (Site $site): bool => $this->access->hasLicence($site->getIdentifier())));
        // The site filter offers every readable site with the licence, by title (Atlas 08·A); the page's site is preselected.
        $filterSites = array_values(array_filter($readableSites, fn (Site $site): bool => $this->access->hasLicence($site->getIdentifier())));
        $aiStates = [];
        foreach ($licensedSites as $site) {
            $aiStates[$site->getIdentifier()] = $this->access->aiState($site->getIdentifier());
        }
        $anyReady = in_array(EditorAiAccessService::STATE_READY, $aiStates, true);
        // M-02/M-04: say why AI is not available — a missing licence, the person's AI permission, a paused site or an
        // exhausted budget are different next steps (and only the first is a matter of the plan).
        $lockedReason = $scopeSites === [] ? 'no_site' : 'no_licence';
        $notReadyReason = 'not_configured';
        foreach ([EditorAiAccessService::STATE_BUDGET_REACHED, EditorAiAccessService::STATE_PAUSED, EditorAiAccessService::STATE_NO_PERMISSION] as $candidate) {
            if ($aiStates !== [] && in_array($candidate, $aiStates, true)) {
                $notReadyReason = $candidate;
                break;
            }
        }
        $view = in_array($queryParams['view'] ?? '', self::VIEWS, true) ? (string)$queryParams['view'] : 'open';

        $returnParameters = array_filter([
            'id' => $selectedId > 0 ? $selectedId : null,
            'pageUid' => $pageUid > 0 ? $pageUid : null,
        ]);
        $returnUrl = $this->buildRouteUrl('web_a11y.remediation', $returnParameters);
        $overviewUrl = $this->buildRouteUrl('web_a11y', array_filter(['id' => $selectedId > 0 ? $selectedId : null]));
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
        $buttonBar->addButton(
            $buttonBar->makeLinkButton()
                ->setHref($overviewUrl)
                ->setTitle($this->translate('settings.backToOverview') ?: 'Back to overview')
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL)),
            ButtonBar::BUTTON_POSITION_LEFT,
            1,
        );

        $this->backendJavaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/remediation/remediation-queue.js');
        $this->pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/backend.css');
        $this->pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/remediation.css');

        $pageFilter = $pageUid > 0 ? ['uid' => $pageUid, 'title' => $this->pageTitle($pageUid)] : null;
        $config = [
            'sites' => array_map(static fn (Site $site): string => $site->getIdentifier(), $licensedSites),
            'allSites' => array_map(static fn (Site $site): string => $site->getIdentifier(), $filterSites),
            'pageUid' => $pageUid,
            // "Review suggestion" from Page Detail opens exactly this item. Only a number passes; the item route checks
            // that this person may see it, like every other drawer request.
            'openItem' => max(0, (int)($queryParams['item'] ?? 0)),
            'view' => $view,
            'returnUrl' => $returnUrl,
            'isAdmin' => $this->access->isAdmin(),
            'canBulk' => $this->access->mayUseBulk(),
            // Atlas 08·A: the sub-navigation count is hidden without AI or the licence.
            'showCount' => $licensedSites !== [] && $anyReady,
            'maxBatch' => RemediationWorkflowService::MAX_BATCH,
            'pollSeconds' => 20,
            // Dates and numbers follow the backend language, not the browser locale (Atlas audit F-16).
            'locale' => BackendLocaleFormatter::forBackendUser()->jsLocale(),
            'urls' => $this->ajaxUrls(['list', 'item', 'generate', 'view', 'edit', 'approve', 'reject', 'reopen', 'refresh', 'owner', 'changelog']),
            // AQG-300-UX-02/03: the empty queue links to a page where "Fix with AI" is actually offered to this person.
            'firstEligible' => $anyReady ? $this->firstEligiblePage($licensedSites, $aiStates) : null,
            'overviewUrl' => $overviewUrl,
            'labels' => $this->labels(),
        ];

        $moduleTemplate->assignMultiple([
            'scopeTitle' => $selectedSite !== null ? RemediationSiteOptions::title($selectedSite) : $this->translate('remediation.scope.allSites'),
            // "Provider · model" only where one site is in scope: several sites can use different models.
            'aiLine' => count($licensedSites) === 1 && $anyReady ? $this->access->aiModelLine($licensedSites[0]->getIdentifier()) : '',
            'budgetLine' => count($licensedSites) === 1 && $anyReady ? $this->budgetLine($licensedSites[0]->getIdentifier()) : '',
            'hasLicence' => $licensedSites !== [],
            'lockedReason' => $lockedReason,
            'notReadyReason' => $notReadyReason,
            'anyAiReady' => $anyReady,
            'isAdmin' => $this->access->isAdmin(),
            'canBulk' => $this->access->mayUseBulk(),
            'view' => $view,
            'settingsUrl' => $this->access->isAdmin() ? $this->buildRouteUrl('web_a11y.settings', ['tab' => 'ai']) : '',
            'overviewUrl' => $overviewUrl,
            'selectedId' => $selectedId > 0 ? $selectedId : null,
            'statuses' => array_map(fn (string $status): array => ['value' => $status, 'label' => $this->translate('remediation.status.' . $status)], array_values(array_diff(RemediationStatus::ALL, [RemediationStatus::APPLYING]))),
            'owners' => array_map(fn (string $owner): array => ['value' => $owner, 'label' => $this->translate('remediation.owner.' . $owner)], RemediationOwner::ALL),
            'origins' => array_map(fn (string $origin): array => ['value' => $origin, 'label' => $this->translate('remediation.origin.' . $origin)], [RemediationAccessService::ORIGIN_EDITOR_REQUEST, RemediationAccessService::ORIGIN_BULK]),
            'siteOptions' => RemediationSiteOptions::options($filterSites, $selectedSite?->getIdentifier() ?? ''),
            'logActions' => array_map(fn (string $action): array => ['value' => $action, 'label' => $this->translate('remediation.history.' . $action)], RemediationChangeLogService::ACTIONS),
            'logScopeNote' => $this->access->isAdmin() ? '' : ($this->access->mayUseBulk() ? 'pages' : 'own'),
            'pageFilter' => $pageFilter,
            'pageUid' => $pageUid,
            'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE),
        ]);

        return $moduleTemplate->renderResponse('Remediation/Index');
    }

    /**
     * The first page (most severe first) with an open content-scan finding that Page Detail offers "Fix with AI" for,
     * on a site whose AI is ready and that this person may edit. Only the rules that get the queue button: rich-text
     * headings and button labels always; image alt texts when this person and the site allow sending images; link
     * text written as a URL and iframe titles when the site allows AI link and iframe suggestions. Empty links and
     * non-descriptive links use the inline suggestion on their card instead (Atlas 07·A).
     *
     * @param list<Site> $sites
     * @param array<string,string> $aiStates
     * @return array{url:string,title:string,findings:int}|null
     */
    private function firstEligiblePage(array $sites, array $aiStates): ?array
    {
        if ($this->issueRepository === null) {
            return null;
        }
        foreach ($sites as $site) {
            $identifier = $site->getIdentifier();
            if (($aiStates[$identifier] ?? '') !== EditorAiAccessService::STATE_READY) {
                continue;
            }
            $rules = ['rte.empty_heading', 'rte.button_label_missing'];
            if ($this->access->mayUseImages() && ($this->aiSiteSettings?->get($identifier)->allowImageInput ?? false)) {
                array_push($rules, 'rte.img_alt_missing', 'rte.img_alt_is_filename', 'rte.img_alt_redundant_phrase', 'rte.img_alt_too_long', 'rte.image_in_link_missing_alt');
            }
            if ((bool)($this->editorAiAccess?->editorConfiguration($identifier)['linkText'] ?? false)) {
                array_push($rules, 'rte.link_text_is_url_or_filename', 'rte.iframe_missing_title');
            }
            foreach ($this->issueRepository->findPagesWithOpenRichTextFindings([$identifier], $rules, 200, true) as $row) {
                if ($this->access->canReadPage($row['page_uid']) && $this->access->canEditPage($row['page_uid'])) {
                    return [
                        'url' => $this->buildRouteUrl('web_a11y.pageDetail', ['id' => $row['page_uid'], 'pageUid' => $row['page_uid'], 'site' => $identifier, 'language' => $row['language_uid']]),
                        'title' => $this->pageTitle($row['page_uid']),
                        'findings' => $row['findings'],
                    ];
                }
            }
        }

        return null;
    }

    private function siteForPage(int $pageUid): ?Site
    {
        if ($pageUid <= 0) {
            return null;
        }
        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);

        // M-02: an editor with a page mount (no site root access) works on that page's site; the queue shows only the
        // items of pages they may read (RemediationAccessService::canSee).
        return $site instanceof Site
            && ($this->access->canReadSiteIdentifier($site->getIdentifier()) || $this->access->canReadPage($pageUid))
            ? $site : null;
    }

    /**
     * "This month: $3.12 of $50.00 budget (estimated)" (Atlas 08·A), read only from the site's budget status. Shown to
     * administrators only: whether AI cost may be shown to other users is an open owner decision (ai.budgets).
     */
    private function budgetLine(string $siteIdentifier): string
    {
        if (!$this->access->isAdmin()) {
            return '';
        }
        $configuration = $this->access->readyAiConfiguration($siteIdentifier);
        if ($configuration === null) {
            return '';
        }
        try {
            $status = $this->budgetService->status($siteIdentifier, $configuration['provider'], $configuration['model']);
        } catch (\Throwable) {
            return '';
        }
        if (!in_array($status->state, [AiBudgetStatus::STATE_UNDER, AiBudgetStatus::STATE_WARNING, AiBudgetStatus::STATE_REACHED], true)) {
            return '';
        }

        // Amounts in the backend language, like Accessibility › Settings › AI suggestions ("5,95 $" in German; audit F-16).
        $formatter = BackendLocaleFormatter::forBackendUser();

        return sprintf(
            $this->translateWithFallback('remediation.budget.line', 'This month: %1$s of %2$s budget (estimated)'),
            $formatter->usd($status->committedMicroUsd()),
            $formatter->usd($status->budgetMicroUsd),
        );
    }

    /** The page as the page tree names it (the person may read it: checked before). */
    private function pageTitle(int $pageUid): string
    {
        $page = BackendUtility::getRecordWSOL('pages', $pageUid);

        return is_array($page) ? BackendUtility::getRecordTitle('pages', $page) : '';
    }

    /**
     * @param list<string> $actions
     * @return array<string,string>
     */
    private function ajaxUrls(array $actions): array
    {
        $urls = [];
        foreach ($actions as $action) {
            $urls[$action] = (string)$this->uriBuilder->buildUriFromRoute('ajax_a11y_remediation_' . $action);
        }

        return $urls;
    }

    /** @return array<string,string> every string the queue script shows, in the backend user's language */
    private function labels(): array
    {
        $keys = [
            'loading', 'empty.title', 'empty.text', 'empty.firstAction', 'empty.firstNote', 'empty.noEligible', 'empty.overview', 'noMatch.title', 'noMatch.text', 'clearFilters', 'error.load', 'resultCount',
            'col.select', 'change.to', 'col.page', 'col.finding', 'col.field', 'col.change', 'col.owner', 'col.source', 'col.status', 'col.age',
            'drawer.close', 'drawer.discardEdit', 'drawer.page', 'drawer.record', 'drawer.field', 'drawer.owner', 'drawer.before', 'drawer.after', 'drawer.empty',
            'drawer.why', 'drawer.edited', 'drawer.suggestion', 'drawer.noSuggestion', 'drawer.whatWasSent', 'drawer.notSent', 'drawer.tokens',
            'drawer.provenance', 'drawer.history', 'drawer.model', 'drawer.prompt', 'drawer.rule', 'drawer.finding', 'drawer.createdBy',
            'drawer.reviewedBy', 'drawer.appliedBy', 'drawer.value', 'drawer.saveValue', 'drawer.cancelEdit', 'drawer.editValue',
            'drawer.serverWrite', 'drawer.editorApply', 'drawer.noEditAccess', 'drawer.bulkLost', 'drawer.canApply', 'drawer.agentDisabled', 'drawer.noLicence', 'drawer.workspaceApply', 'error.workspace_apply_in_record',
            'drawer.prev', 'drawer.next', 'drawer.position', 'drawer.openRecord', 'drawer.openPageDetail',
            'action.approveApply', 'action.approveEditor', 'action.openEditor', 'action.reject', 'action.regenerate', 'action.generate',
            'action.reopen', 'action.verify', 'action.retry', 'action.confirmReject', 'action.cancel',
            'reject.title', 'reject.reason', 'reject.none', 'reject.factually_wrong', 'reject.decorative', 'reject.solved_differently', 'reject.other', 'reject.note',
            'outcome.saving', 'outcome.savedChecking', 'outcome.verified', 'outcome.notVerified', 'outcome.rejected', 'outcome.conflict', 'outcome.inEditor', 'outcome.failed',
            'announce.applying', 'announce.saved', 'announce.verified', 'announce.notVerified', 'announce.rejected', 'announce.conflict', 'announce.inEditor', 'announce.failed', 'announce.edited',
            'error.session_expired', 'error.permission_denied', 'error.no_edit_access', 'error.changed_concurrently', 'error.not_reviewed', 'error.invalid_value',
            'error.in_progress', 'error.already_done', 'error.workspace_mismatch', 'error.conflict', 'error.rate_limited', 'error.no_licence', 'error.generic',
            'error.not_found', 'error.invalid_state', 'error.agent_approval_disabled', 'error.editor_only', 'error.stale_view',
            'bulk.selected', 'bulk.notViewed', 'bulk.allViewed', 'bulk.richTextExcluded', 'bulk.review', 'bulk.applyN', 'bulk.clear', 'bulk.dialogTitle',
            'bulk.dialogText', 'bulk.openFirst', 'bulk.approveItem', 'bulk.skipItem', 'bulk.stop', 'bulk.applyingN', 'bulk.summary', 'bulk.retryFailed',
            'bulk.result.saved', 'bulk.result.verified', 'bulk.result.notVerified', 'bulk.result.conflict', 'bulk.result.failed', 'bulk.result.skipped',
            'bulk.notYours', 'bulk.cap', 'bulk.selectRow', 'bulk.finished', 'generate.queued', 'generate.preparing', 'generate.waiting',
            'age.now', 'age.minutes', 'age.hours', 'age.days', 'mine.text', 'pagination.previous', 'pagination.next', 'pagination.of',
            'ladder.pending_review', 'ladder.in_editor', 'ladder.saved', 'ladder.verified', 'outcome.approved', 'drawer.outcome',
            'bulk.stopped', 'bulk.conflictsNote',
            'resultCountOf', 'pagination.range', 'conflict.title', 'conflict.text', 'conflict.base', 'conflict.current', 'conflict.proposed',
            'conflict.unknown', 'conflict.decorative', 'action.regenerateCurrent', 'action.compare', 'action.discard',
            'log.label', 'log.col.time', 'log.col.person', 'log.col.page', 'log.col.change', 'log.col.model', 'log.col.outcome',
            'log.count', 'log.range', 'log.empty.title', 'log.empty.text', 'log.hash',
            'resultCountOne', 'log.countOne', 'log.details', 'ladder.not_verified', 'drawer.modelId', 'drawer.preview',
            'bulk.dialogTitleOne', 'bulk.reviewOne', 'bulk.richTextExcludedOne',
            // Owner UX pass 3.0.0: list columns, row action and the state-driven drawer.
            'col.where', 'col.issue', 'col.actions', 'row.review', 'row.open', 'change.none',
            'drawer.target', 'drawer.status', 'drawer.proposal', 'drawer.prepFailed', 'drawer.prepFailedNote', 'drawer.applyFailed',
            'action.fixInRecord', 'action.prepareAgain', 'action.dismiss', 'action.confirmDismiss', 'reject.dismissTitle', 'reject.dismissNote',
        ];
        $labels = [];
        foreach ($keys as $key) {
            $labels[$key] = $this->translate('remediation.js.' . $key);
        }
        // "What was sent?" uses the Smart Editor's and AI media's own wording for the same data.
        $sent = [
            'linkText' => 'rte.aiSentLinkText', 'linkTarget' => 'rte.aiSentLinkTarget', 'linkTargetTitle' => 'rte.aiSentLinkTargetTitle',
            'surroundingText' => 'rte.aiSentSurroundingText', 'pageTitle' => 'rte.aiSentPageTitle', 'currentAlt' => 'rte.aiSentCurrentAlt',
            'caption' => 'rte.aiSentCaption', 'buttonMarkup' => 'rte.aiSentButtonMarkup', 'formContext' => 'rte.aiSentFormContext',
            'iframeSource' => 'rte.aiSentIframeSource', 'headingLevel' => 'rte.aiSentHeadingLevel', 'previousHeading' => 'rte.aiSentPreviousHeading',
            'followingText' => 'rte.aiSentFollowingText', 'elementSummary' => 'rte.aiSentElementSummary', 'image' => 'mediaAi.sentImage',
            'elementHeader' => 'mediaAi.sentHeader', 'elementText' => 'mediaAi.sentText', 'fileDefault' => 'mediaAi.sentFileDefault',
            'language' => 'mediaAi.sentLanguage',
        ];
        foreach ($sent as $code => $key) {
            $labels['sent.' . $code] = $this->translate($key);
        }

        return $labels;
    }
}
