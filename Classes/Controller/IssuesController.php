<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Assignment\AssignmentService;
use Priebera\A11yQualityGate\Assignment\AssigneeEligibility;
use Priebera\A11yQualityGate\Assignment\AssignmentRepository;
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
        $filter = (string)($query['assignee'] ?? AssignmentService::FILTER_ME);
        if (!in_array($filter, [AssignmentService::FILTER_ME, AssignmentService::FILTER_UNASSIGNED, AssignmentService::FILTER_ALL], true) && !ctype_digit($filter)) {
            $filter = AssignmentService::FILTER_ME;
        }
        $state = (string)($query['state'] ?? AssignmentService::STATE_OPEN);
        $readableSites = array_values(array_filter($this->siteFinder->getAllSites(), fn (Site $site): bool => $this->scopeAccessService->canReadSite($site)));
        $siteIdentifier = (string)($query['site'] ?? '');
        if ($siteIdentifier !== '' && !in_array($siteIdentifier, array_map(static fn (Site $s): string => $s->getIdentifier(), $readableSites), true)) {
            $siteIdentifier = '';
        }

        $language = (string)($GLOBALS['BE_USER']->user['lang'] ?? '') === 'de' ? 'de' : 'en';
        // NEW-03: the list is paginated on the server (no silent cut-off) and can be searched.
        $requestedPage = max(1, (int)($query['page'] ?? 1));
        $search = trim(mb_substr((string)($query['q'] ?? ''), 0, 100));
        $result = $this->assignmentService->listTasks($filter, $state, $siteIdentifier, $language, $requestedPage, AssignmentService::PER_PAGE, $search);

        $tasks = array_map(fn (array $task): array => $task + [
            'url' => $this->buildRouteUrl('web_a11y.pageDetail', [
                'id' => $task['pageUid'],
                'pageUid' => $task['pageUid'],
                'site' => $task['siteIdentifier'],
                'language' => $task['languageUid'],
                'rule' => $task['shared'] ? (string)$task['linkRule'] : $task['issueKey'],
            ]) . '#' . $this->cardAnchor($task),
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

        $listParameters = array_filter([
            'id' => $selectedId > 0 ? $selectedId : null,
            'assignee' => $result['filter'],
            'state' => $state,
            'site' => $siteIdentifier !== '' ? $siteIdentifier : null,
            'q' => $search !== '' ? $search : null,
        ], static fn ($value): bool => $value !== null);
        $pageUrl = fn (int $page): string => $this->buildRouteUrl('web_a11y.issues', $listParameters + ($page > 1 ? ['page' => $page] : []));

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
            'siteOptions' => array_map(static fn (Site $site): array => ['value' => $site->getIdentifier(), 'label' => (string)($site->getConfiguration()['websiteTitle'] ?? '') ?: $site->getIdentifier()], $readableSites),
            'people' => $people,
            'selectedId' => $selectedId > 0 ? $selectedId : null,
            'formAction' => $this->buildRouteUrl('web_a11y.issues'),
            'selfName' => AssigneeEligibility::label(['username' => (string)($GLOBALS['BE_USER']->user['username'] ?? ''), 'realName' => (string)($GLOBALS['BE_USER']->user['realName'] ?? '')]),
        ]);

        return $moduleTemplate->renderResponse('Issues/Index');
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
