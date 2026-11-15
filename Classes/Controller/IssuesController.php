<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Assignment\AssignmentService;
use Priebera\A11yQualityGate\Assignment\AssigneeEligibility;
use Priebera\A11yQualityGate\Assignment\AssignmentRepository;
use Priebera\A11yQualityGate\Assignment\IssuesListState;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Assignment\TemplateScope;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Priebera\A11yQualityGate\Utility\RuleAnchorUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Accessibility › My issues: the issue types on pages assigned to the person (and, for people who may assign, the
 * team's work: unassigned work, everyone's or one person's). Server-rendered; every row opens Page Detail at the rule,
 * where the record, the fix guidance, AI fixes and the assign control already are.
 */
#[AsController]
final class IssuesController extends AbstractBackendModuleController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly PageRenderer $pageRenderer,
        private readonly AssignmentService $assignmentService,
        private readonly AssignmentRepository $assignmentRepository,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly SiteFinder $siteFinder,
        private readonly ?BackendJavaScriptModuleService $backendJavaScriptModuleService = null,
        private readonly ?AccessControlService $accessControlService = null,
    ) {
        parent::__construct($moduleTemplateFactory, $uriBuilder, $iconFactory, $backendContextService, $siteResolutionService, $requestParameterService);
    }

    public function indexAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);
        $this->setModuleTitle($moduleTemplate, 'module.title', 'assignment.title');
        $this->pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/backend.css');
        $this->pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/remediation.css');
        // N-17: the task table scrolls inside a keyboard-focusable region on narrow screens.
        $this->backendJavaScriptModuleService?->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/backend/issues.js');

        $query = $request->getQueryParams();
        $selectedId = max(0, (int)($query['id'] ?? 0));
        $mayAssign = $this->assignmentService->mayAssign();
        // MI-02/MI-03: one sanitised list state for the query, the chips, the page links and the way back from a task.
        $listState = IssuesListState::fromArray($query);
        $readableSites = array_values(array_filter($this->siteFinder->getAllSites(), fn (Site $site): bool => $this->scopeAccessService->canReadSite($site)));
        if ($listState->site !== '' && !in_array($listState->site, array_map(static fn (Site $s): string => $s->getIdentifier(), $readableSites), true)) {
            $listState = $listState->withSite('');
        }

        $language = (string)($GLOBALS['BE_USER']->user['lang'] ?? '') === 'de' ? 'de' : 'en';
        // NEW-03: the list is paginated on the server (no silent cut-off) and can be searched; MI-03: 10 / 25 / 50 per page.
        $result = $this->assignmentService->listTasks($listState->assignee, $listState->state, $listState->site, $language, $listState->page, $listState->perPage, $listState->query);
        if ($result['filter'] !== $listState->assignee) {
            // An editor who may not assign always sees their own tasks; the chips and links must say so.
            $listState = $listState->withAssignee($result['filter']);
        }
        $listState = $listState->withPage($result['page']);
        $search = $listState->query;
        $state = $listState->state;
        $siteIdentifier = $listState->site;
        $base = $selectedId > 0 ? ['id' => $selectedId] : [];
        $listUrl = fn (IssuesListState $target): string => $this->buildRouteUrl('web_a11y.issues', $base + $target->toParameters());
        $returnValue = $listState->toReturnValue();

        $tasks = array_map(fn (array $task): array => $task + [
            'url' => $this->buildRouteUrl('web_a11y.pageDetail', array_filter([
                'id' => $task['pageUid'],
                'pageUid' => $task['pageUid'],
                'site' => $task['siteIdentifier'],
                'language' => $task['languageUid'],
                'rule' => $task['shared'] ? (string)$task['linkRule'] : $task['issueKey'],
                // MI-06: Page Detail offers "Back to My issues" with exactly these filters, rebuilt server-side.
                IssuesListState::RETURN_PARAMETER => $returnValue !== '' ? $returnValue : 'list',
            ], static fn ($value): bool => $value !== null)) . '#' . $this->cardAnchor($task),
            'severityKey' => match (true) {
                $task['severity'] <= 1 => 'critical',
                $task['severity'] === 2 => 'warning',
                $task['severity'] === 3 => 'info',
                $task['severity'] >= 99 => 'none',
                default => 'needs_review',
            },
            'languageLabel' => $task['shared'] && $task['languageCount'] > 1
                ? sprintf(BackendLabelUtility::translate('assignment.shared.languages', '%d languages'), $task['languageCount'])
                : $this->languageLabel($task['siteIdentifier'], $task['languageUid']),
            'assignedAtLabel' => $task['assignedAt'] > 0 ? BackendTimeUtility::formatDateTime($task['assignedAt'], 'd.m.Y') : '',
            'doneAtLabel' => $task['doneAt'] > 0 ? BackendTimeUtility::formatDateTime($task['doneAt'], 'd.m.Y') : '',
        ], $result['tasks']);

        $people = [];
        if ($mayAssign) {
            foreach ($this->assignmentRepository->findActiveBackendUsers() as $row) {
                $people[] = ['uid' => (string)$row['uid'], 'label' => AssigneeEligibility::label($row)];
            }
        }
        $siteOptions = array_map(static fn (Site $site): array => ['value' => $site->getIdentifier(), 'label' => (string)($site->getConfiguration()['websiteTitle'] ?? '') ?: $site->getIdentifier()], $readableSites);
        $pageUrl = fn (int $page): string => $listUrl($listState->withPage($page));

        $formAction = $this->buildRouteUrl('web_a11y.issues');
        $moduleTemplate->assignMultiple([
            'tasks' => $tasks,
            'taskCount' => $result['total'],
            'pagination' => [
                'page' => $result['page'],
                'pages' => $result['pages'],
                'total' => $result['total'],
                'rangeStart' => $result['rangeStart'],
                'rangeEnd' => $result['rangeEnd'],
                'previousUrl' => $result['page'] > 1 ? $pageUrl($result['page'] - 1) : '',
                'nextUrl' => $result['page'] < $result['pages'] ? $pageUrl($result['page'] + 1) : '',
                'items' => $this->paginationItems($result['page'], $result['pages'], $pageUrl),
            ],
            'search' => $search,
            'workload' => $result['workload'],
            'unassignedOpen' => $result['unassignedOpen'],
            'mayAssign' => $mayAssign,
            'filter' => $result['filter'],
            'state' => $state,
            'site' => $siteIdentifier,
            'perPage' => $listState->perPage,
            'perPageOptions' => IssuesListState::PER_PAGE_OPTIONS,
            'siteOptions' => $siteOptions,
            'people' => $people,
            'selectedId' => $selectedId > 0 ? $selectedId : null,
            'formAction' => $formAction,
            // MI-01: a GET form replaces the action's query string, so the route token travels as a hidden field.
            // Without it TYPO3 answered with its login redirect and the iframe rendered a second backend.
            'formToken' => $this->routeToken($formAction),
            'chips' => $this->activeFilterChips($listState, $people, $siteOptions, $listUrl),
            'clearUrl' => $listState->hasActiveFilters() ? $listUrl($listState->cleared()) : '',
            'unassignedDone' => $result['filter'] === AssignmentService::FILTER_UNASSIGNED && $state === AssignmentService::STATE_DONE,
            'selfName' => AssigneeEligibility::label(['username' => (string)($GLOBALS['BE_USER']->user['username'] ?? ''), 'realName' => (string)($GLOBALS['BE_USER']->user['realName'] ?? '')]),
        ]);
        $this->addSettingsDocHeaderButton($moduleTemplate, $this->accessControlService?->canShowSettings($this->backendContextService->getBackendUser()) ?? false, $base);

        return $moduleTemplate->renderResponse('Issues/Index');
    }

    private function routeToken(string $url): string
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);

        return is_string($parameters['token'] ?? null) ? $parameters['token'] : '';
    }

    /**
     * MI-04: the filters that narrow the list, as readable chips; each × link is the same list without it. Widening
     * choices ("Everyone", "All" states, "All sites") are no chips (owner QA 2026-11-14, the same rule as AI fixes);
     * "Clear filters" still resets them to the defaults.
     *
     * @param list<array{uid:string,label:string}> $people
     * @param list<array{value:string,label:string}> $siteOptions
     * @param \Closure(IssuesListState):string $listUrl
     * @return list<array{name:string,label:string,value:string,removeUrl:string,removeLabel?:string}>
     */
    private function activeFilterChips(IssuesListState $state, array $people, array $siteOptions, \Closure $listUrl): array
    {
        $chips = [];
        if (!in_array($state->assignee, [AssignmentService::FILTER_ME, AssignmentService::FILTER_ALL], true)) {
            $value = $state->assignee === AssignmentService::FILTER_UNASSIGNED
                ? BackendLabelUtility::translate('assignment.filter.unassigned', 'Nobody (unassigned)')
                : (array_column($people, 'label', 'uid')[$state->assignee] ?? ('#' . $state->assignee));
            $chips[] = ['name' => 'assignee', 'label' => BackendLabelUtility::translate('assignment.filter.assignee', 'Assigned to'), 'value' => $value, 'removeUrl' => $listUrl($state->without('assignee'))];
        }
        if ($state->state === AssignmentService::STATE_DONE) {
            $chips[] = ['name' => 'state', 'label' => BackendLabelUtility::translate('assignment.filter.state', 'Status'), 'value' => BackendLabelUtility::translate('assignment.state.done', 'Done'), 'removeUrl' => $listUrl($state->without('state'))];
        }
        if ($state->site !== '') {
            $chips[] = ['name' => 'site', 'label' => BackendLabelUtility::translate('assignment.filter.site', 'Site'), 'value' => array_column($siteOptions, 'label', 'value')[$state->site] ?? $state->site, 'removeUrl' => $listUrl($state->without('site'))];
        }
        if ($state->query !== '') {
            $chips[] = ['name' => 'q', 'label' => BackendLabelUtility::translate('assignment.filter.search', 'Search'), 'value' => '“' . $state->query . '”', 'removeUrl' => $listUrl($state->without('q'))];
        }

        $remove = BackendLabelUtility::translate('assignment.chips.remove', 'Remove filter %s');
        foreach ($chips as $index => $chip) {
            $chips[$index]['removeLabel'] = sprintf($remove, $chip['label'] . ': ' . $chip['value']);
        }

        return $chips;
    }

    /**
     * Page links around the current page: first, last, two either side, ellipses between (NEW-03).
     *
     * @param \Closure(int):string $pageUrl
     * @return list<array{type:string,label:string,url:string,current:bool}>
     */
    private function paginationItems(int $current, int $pages, \Closure $pageUrl): array
    {
        $items = [];
        $last = 0;
        for ($page = 1; $page <= $pages; $page++) {
            if ($page !== 1 && $page !== $pages && abs($page - $current) > 2) {
                continue;
            }
            if ($last > 0 && $page - $last > 1) {
                $items[] = ['type' => 'ellipsis', 'label' => '…', 'url' => '', 'current' => false];
            }
            $items[] = ['type' => 'page', 'label' => (string)$page, 'url' => $pageUrl($page), 'current' => $page === $current];
            $last = $page;
        }

        return $items;
    }

    /**
     * The id of the Page Detail card the task opens (IssueRuleGroupingUtility: rule anchor + severity, plus the template
     * element's suffix for a shared task). A done task has no open card; the page itself is the target then.
     *
     * @param array<string, mixed> $task
     */
    private function cardAnchor(array $task): string
    {
        $severity = (int)$task['severity'];
        if ($severity >= 99) {
            return '';
        }
        $rule = $task['shared'] ? (string)$task['linkRule'] : (string)$task['issueKey'];
        $anchor = RuleAnchorUtility::anchorId($rule) . '-' . Severity::fromInt($severity)->key();

        return $task['shared'] && TemplateScope::isKey((string)$task['issueKey'])
            ? $anchor . '-' . substr(md5($rule . '#' . $task['issueKey']), 0, 8)
            : $anchor;
    }

    private function languageLabel(string $siteIdentifier, int $languageUid): string
    {
        try {
            return $this->siteFinder->getSiteByIdentifier($siteIdentifier)->getLanguageById($languageUid)->getTitle();
        } catch (\Throwable) {
            return (string)$languageUid;
        }
    }
}
