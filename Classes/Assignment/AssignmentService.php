<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Assignment;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAssignedOwnerService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationOwner;
use Priebera\A11yQualityGate\Scan\RenderedRichTextDuplicates;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Team assignment of issue types on pages ("My issues").
 *
 * Unit: site + page + language + canonical rule (RenderedRichTextDuplicates::canonicalRuleId()). The state is read live:
 * open (open occurrences), done (none left, at least one resolved), with "changed since the last scan" and "assignee can
 * no longer open it" as flags. Nothing is stored per finding, so rescans, new fingerprints and resolved/reopened
 * findings never orphan or duplicate a task.
 *
 * Authorization (every read and write, evaluated now): assigning needs administrator rights or
 * `options.a11y_quality_gate.assign = 1`, module access, content-edit access to the page and the language; the assignee is
 * checked as that user (AssigneeEligibility). A list shows a task only while the viewer may read its page and language,
 * so counts and the workload line never include pages outside the viewer's access.
 */
class AssignmentService
{
    public const FILTER_ME = 'me';
    public const FILTER_UNASSIGNED = 'unassigned';
    public const FILTER_ALL = 'all';

    public const STATE_OPEN = 'open';
    public const STATE_DONE = 'done';

    /** @var array<string, bool> */
    private array $readable = [];
    /** @var array<string, bool> */
    private array $currentSite = [];
    /** @var array<string, bool> */
    private array $sharedReadable = [];

    public function __construct(
        private readonly AssignmentRepository $repository,
        private readonly AssigneeEligibility $eligibility,
        private readonly IssueRepository $issueRepository,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly AccessControlService $accessControlService,
        private readonly BackendUserService $backendUserService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ?RuleMetadataPresentationService $ruleMetadataPresentationService = null,
        private readonly ?RemediationAssignedOwnerService $assignedOwnerService = null,
    ) {
    }

    /** The user may assign work at all (the page checks come per task). */
    public function mayAssign(): bool
    {
        $user = $this->backendUserService->getBackendUser();
        if (!$user instanceof BackendUserAuthentication || !$this->accessControlService->canAccessModule($user)) {
            return false;
        }
        if ($user->isAdmin()) {
            return true;
        }
        $value = $user->getTSConfig()['options.']['a11y_quality_gate.']['assign'] ?? null;

        return $value === 1 || $value === '1';
    }

    public function canAssignOn(int $pageUid, int $languageUid): bool
    {
        return $this->mayAssign()
            && $this->scopeAccessService->canEditPage($pageUid)
            && $this->scopeAccessService->canUseLanguage($languageUid)
            && $this->scopeAccessService->canReadPageInLanguage($pageUid, $languageUid);
    }

    /**
     * Assigns (or with $assigneeUid 0 unassigns) the issue type of a stored finding. The finding only names the task:
     * site, page, language and rule come from the stored row, never from the request.
     *
     * @return array{issueKey:string,assignee:int,assigneeLabel:string}
     */
    public function assignByFinding(int $findingUid, int $assigneeUid, string $note = '', ?int $expectedAssignee = null): array
    {
        $issue = $findingUid > 0 ? $this->issueRepository->findByUid($findingUid) : null;
        if (!is_array($issue) || (int)($issue['deleted'] ?? 0) === 1) {
            throw new AssignmentException('not_found', 404);
        }
        $siteIdentifier = (string)$issue['site_identifier'];
        $pageUid = (int)$issue['page_uid'];
        $languageUid = max(0, (int)$issue['source_lang_uid']);
        if ($siteIdentifier === '' || $pageUid <= 0
            || $this->siteResolutionService->resolveSiteByPageId($pageUid)?->getIdentifier() !== $siteIdentifier
        ) {
            // A finding of a renamed/removed site or a page that moved to another site is history, not assignable work.
            throw new AssignmentException('not_found', 404);
        }
        if (!$this->scopeAccessService->canReadPageInLanguage($pageUid, $languageUid) || !$this->scopeAccessService->canUseLanguage($languageUid)) {
            throw new AssignmentException('not_found', 404);
        }
        if (TemplateScope::isTemplateFinding($issue)) {
            return $this->assignTemplateFinding($issue, $siteIdentifier, $assigneeUid, $note, $expectedAssignee);
        }
        if (!$this->canAssignOn($pageUid, $languageUid)) {
            throw new AssignmentException('permission_denied', 403);
        }
        if ($assigneeUid > 0 && (int)$issue['status'] !== IssueStatus::Open->value) {
            throw new AssignmentException('finding_closed', 409);
        }
        if ($assigneeUid > 0) {
            $reason = $this->eligibility->reason($assigneeUid, $pageUid, $languageUid);
            if ($reason !== '') {
                throw new AssignmentException('assignee_' . $reason, 422);
            }
        }

        $issueKey = RenderedRichTextDuplicates::canonicalRuleId((string)$issue['rule_id']);
        if (!$this->repository->save($siteIdentifier, $pageUid, $languageUid, $issueKey, $assigneeUid, $this->backendUserService->getBackendUserUid(), $note, $expectedAssignee)) {
            throw new AssignmentException('assignment_conflict', 409);
        }
        $assignee = $assigneeUid > 0 ? $this->repository->findBackendUser($assigneeUid) : null;

        return [
            'issueKey' => $issueKey,
            'assignee' => $assigneeUid,
            'assigneeLabel' => $assignee !== null ? AssigneeEligibility::label($assignee) : '',
        ];
    }

    /**
     * Candidates for the issue type of a finding, for the assign control. Empty without the right to assign there.
     *
     * @return list<array{uid:int,label:string}>
     */
    public function candidatesForFinding(int $findingUid): array
    {
        $issue = $findingUid > 0 ? $this->issueRepository->findByUid($findingUid) : null;
        if (!is_array($issue)) {
            return [];
        }
        $pageUid = (int)$issue['page_uid'];
        $languageUid = max(0, (int)$issue['source_lang_uid']);
        if (TemplateScope::isTemplateFinding($issue)) {
            $rootPageUid = $this->templateRootPage((string)$issue['site_identifier'], $pageUid);

            return $rootPageUid > 0 && $this->scopeAccessService->canReadPageInLanguage($pageUid, $languageUid)
                ? $this->eligibility->candidates($rootPageUid, 0)
                : [];
        }
        if (!$this->canAssignOn($pageUid, $languageUid)) {
            return [];
        }

        return $this->eligibility->candidates($pageUid, $languageUid);
    }

    /**
     * A shared template task (N-01): one task of the site for the same template element on every page and language.
     * Assigning needs the assign right and edit access to the site root; the assignee must be able to open the root.
     *
     * @param array<string, mixed> $issue
     * @return array{issueKey:string,assignee:int,assigneeLabel:string}
     */
    private function assignTemplateFinding(array $issue, string $siteIdentifier, int $assigneeUid, string $note, ?int $expectedAssignee = null): array
    {
        $rootPageUid = $this->templateRootPage($siteIdentifier, (int)$issue['page_uid']);
        if ($rootPageUid <= 0) {
            throw new AssignmentException('permission_denied', 403);
        }
        if ($assigneeUid > 0 && (int)$issue['status'] !== IssueStatus::Open->value) {
            throw new AssignmentException('finding_closed', 409);
        }
        if ($assigneeUid > 0) {
            $reason = $this->eligibility->reason($assigneeUid, $rootPageUid, 0);
            if ($reason !== '') {
                throw new AssignmentException('assignee_' . $reason, 422);
            }
        }
        $issueKey = TemplateScope::key((string)$issue['rule_id'], (string)($issue['context_snippet'] ?? ''));
        if (!$this->repository->save($siteIdentifier, 0, 0, $issueKey, $assigneeUid, $this->backendUserService->getBackendUserUid(), $note, $expectedAssignee)) {
            throw new AssignmentException('assignment_conflict', 409);
        }
        $assignee = $assigneeUid > 0 ? $this->repository->findBackendUser($assigneeUid) : null;

        return [
            'issueKey' => $issueKey,
            'assignee' => $assigneeUid,
            'assigneeLabel' => $assignee !== null ? AssigneeEligibility::label($assignee) : '',
        ];
    }

    /** The root page of the site when this user may assign its shared template work there; otherwise 0. */
    private function templateRootPage(string $siteIdentifier, int $pageUid): int
    {
        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        if ($site === null || $site->getIdentifier() !== $siteIdentifier || !$this->mayAssign()
            || !$this->scopeAccessService->canReadSite($site) || !$this->scopeAccessService->canEditSite($site)
        ) {
            return 0;
        }

        return (int)$site->getRootPageId();
    }

    /** May the current user assign the shared template work of the site this page belongs to (Page Detail cards)? */
    public function canAssignTemplateOn(string $siteIdentifier, int $pageUid): bool
    {
        return $this->templateRootPage($siteIdentifier, $pageUid) > 0;
    }

    /** May the current user see the shared template work of this site (site root read access)? */
    private function canSeeSharedWork(string $siteIdentifier): bool
    {
        return $this->sharedReadable[$siteIdentifier] ??= $this->scopeAccessService->canReadSiteIdentifier($siteIdentifier);
    }

    /**
     * Assignment chips for the issue types of one page (Page Detail), keyed by canonical issue key.
     *
     * @return array<string, array{assignee:int,label:string,flag:string,assignedAt:int}>
     */
    public function chipsForPage(string $siteIdentifier, int $pageUid, int $languageUid): array
    {
        if (!$this->isReadable($pageUid, $languageUid)) {
            return [];
        }
        $chips = [];
        $rows = $this->repository->findForPage($siteIdentifier, $pageUid, max(0, $languageUid));
        // Shared template tasks of the site (page 0) show on every page where the template defect appears.
        if ($this->canSeeSharedWork($siteIdentifier)) {
            $rows += $this->repository->findForPage($siteIdentifier, 0, 0);
        }
        foreach ($rows as $key => $row) {
            $assignee = (int)$row['assignee'];
            if ($assignee <= 0) {
                continue;
            }
            $user = $this->repository->findBackendUser($assignee);
            $shared = TemplateScope::isKey((string)$key);
            $rootPageUid = $shared ? (int)($this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier)?->getRootPageId() ?? 0) : 0;
            $chips[$key] = [
                'assignee' => $assignee,
                'label' => $user !== null ? AssigneeEligibility::label($user) : '',
                'flag' => $shared
                    ? $this->eligibility->reason($assignee, $rootPageUid, 0)
                    : $this->eligibility->reason($assignee, $pageUid, max(0, $languageUid)),
                'assignedAt' => (int)$row['assigned_at'],
            ];
        }

        return $chips;
    }

    /** NEW-03: My issues is paginated on the server; this many tasks per page. */
    public const PER_PAGE = 100;

    /**
     * The "My issues" list, one page of it (NEW-03: no silent cut-off; NEW-04: bounded work per request).
     *
     * The whole list is classified first — permission (batched, same rules as before), assignment, state and the
     * filters — because the totals, the unassigned count and the team workload describe all tasks, not one page. Only
     * the requested page is then turned into full task rows (owner, assignee, rescan flag), so the cost of a request
     * no longer grows with the size of the site. The order is stable (to do before done, severity, occurrences, page
     * title, then the task identity), so paging never skips or repeats a task.
     *
     * @param string $filter FILTER_ME | FILTER_UNASSIGNED | FILTER_ALL | a user uid (assigners only)
     * @return array{tasks:list<array<string,mixed>>,workload:list<array{uid:int,label:string,open:int,flagged:bool}>,unassignedOpen:int,filter:string,total:int,page:int,pages:int,perPage:int,rangeStart:int,rangeEnd:int,query:string}
     */
    public function listTasks(string $filter, string $state = self::STATE_OPEN, string $siteIdentifier = '', string $language = 'en', int $page = 1, int $perPage = self::PER_PAGE, string $query = ''): array
    {
        $me = $this->backendUserService->getBackendUserUid();
        $assigner = $this->mayAssign();
        if (!$assigner && $filter !== self::FILTER_ME) {
            $filter = self::FILTER_ME;
        }
        $state = in_array($state, [self::STATE_OPEN, self::STATE_DONE, 'any'], true) ? $state : self::STATE_OPEN;
        $perPage = max(1, min(500, $perPage));
        $query = mb_strtolower(trim(mb_substr($query, 0, 100)));

        $person = $filter === self::FILTER_ME ? $me : (ctype_digit($filter) ? (int)$filter : null);
        // Every assignment classifies a task as assigned (the unassigned count and filter); the person filter applies after.
        $assignments = $this->repository->findAssigned();
        $assignedKeys = [];
        foreach ($assignments as $row) {
            $assignedKeys[$this->key((string)$row['site_identifier'], (int)$row['page_uid'], (int)$row['language_uid'], (string)$row['issue_key'])] = $row;
        }

        // Live findings of every page that can appear in this list.
        $pageUids = $person === null || $assigner
            ? null
            : array_map(static fn (array $row): int => (int)$row['page_uid'], array_filter($assignments, static fn (array $row): bool => (int)$row['assignee'] === $person));
        $groups = [];
        $findingRows = $this->repository->aggregateFindings($siteIdentifier !== '' ? [$siteIdentifier] : [], $pageUids);
        $this->preloadSiteMembership(array_map(static fn (array $row): int => (int)$row['page_uid'], $findingRows));
        foreach ($findingRows as $row) {
            if (!$this->isCurrentSite($row['site_identifier'], $row['page_uid'])) {
                continue;
            }
            $issueKey = RenderedRichTextDuplicates::canonicalRuleId($row['rule_id']);
            $key = $this->key($row['site_identifier'], $row['page_uid'], $row['source_lang_uid'], $issueKey);
            $group = $groups[$key] ?? [
                'siteIdentifier' => $row['site_identifier'], 'pageUid' => $row['page_uid'], 'languageUid' => $row['source_lang_uid'],
                'issueKey' => $issueKey, 'open' => 0, 'severity' => 99, 'lastChange' => 0, 'lastResolved' => 0, 'ruleIds' => [], 'sourceTypes' => [],
                'message' => '',
            ];
            if ($group['message'] === '' || $row['rule_id'] === $issueKey) {
                $group['message'] = $row['message'];
            }
            $group['open'] += $row['open'];
            $group['severity'] = min($group['severity'], $row['top_severity']);
            $group['lastChange'] = max($group['lastChange'], $row['last_change']);
            $group['lastResolved'] = max($group['lastResolved'], $row['last_resolved']);
            $group['ruleIds'][] = $row['rule_id'];
            $group['sourceTypes'][str_starts_with($row['rule_id'], 'rendered.') ? 'rendered' : 'content'] = true;
            $groups[$key] = $group;
        }
        foreach ($this->sharedTemplateGroups($siteIdentifier) as $key => $group) {
            $groups[$key] = $group;
        }
        $this->preloadReadability($groups);

        $matched = [];
        $workload = [];
        $unassignedOpen = 0;
        foreach ($groups as $key => $group) {
            $readable = !empty($group['shared'])
                ? $this->canSeeSharedWork((string)$group['siteIdentifier'])
                : $this->isReadable($group['pageUid'], $group['languageUid']);
            if (!$readable) {
                continue;
            }
            $assignment = $assignedKeys[$key] ?? null;
            $taskState = $group['open'] > 0 ? self::STATE_OPEN : self::STATE_DONE;
            if ($assignment === null && $taskState === self::STATE_OPEN) {
                $unassignedOpen++;
            }
            if ($assignment !== null && $taskState === self::STATE_OPEN) {
                $uid = (int)$assignment['assignee'];
                $workload[$uid] ??= ['uid' => $uid, 'label' => '', 'open' => 0, 'flagged' => false];
                $workload[$uid]['open']++;
            }
            $matches = match (true) {
                $filter === self::FILTER_UNASSIGNED => $assignment === null && $taskState === self::STATE_OPEN,
                $filter === self::FILTER_ALL => $assignment !== null || $taskState === self::STATE_OPEN,
                default => $assignment !== null && (int)$assignment['assignee'] === $person,
            };
            if (!$matches || ($state !== 'any' && $state !== $taskState)) {
                continue;
            }
            $matched[$key] = ['group' => $group, 'assignment' => $assignment, 'state' => $taskState];
        }

        foreach ($workload as $uid => $entry) {
            $user = $this->repository->findBackendUser($uid);
            $workload[$uid]['label'] = $user !== null ? AssigneeEligibility::label($user) : '';
            $workload[$uid]['flagged'] = $user === null || $user['disable'] === 1 || $user['deleted'] === 1;
        }

        $titles = $this->repository->findPageTitles(array_map(static fn (array $entry): int => (int)$entry['group']['pageUid'], array_filter($matched, static fn (array $entry): bool => empty($entry['group']['shared']))));
        $sharedTitle = \Priebera\A11yQualityGate\Utility\BackendLabelUtility::translate('assignment.shared.page', 'Site template');
        foreach ($matched as $key => $entry) {
            $group = $entry['group'];
            $matched[$key]['pageTitle'] = !empty($group['shared']) ? $sharedTitle : ($titles[(int)$group['pageUid']] ?? ('#' . (int)$group['pageUid']));
            if ($query !== '') {
                $rule = !empty($group['shared']) ? TemplateScope::ruleOfKey((string)$group['issueKey']) : (string)$group['issueKey'];
                $haystack = mb_strtolower($matched[$key]['pageTitle'] . ' ' . $this->cachedRuleTitle($rule, (string)$group['message'], $language) . ' ' . $rule);
                if (!str_contains($haystack, $query)) {
                    unset($matched[$key]);
                }
            }
        }
        uksort($matched, static function (string $ka, string $kb) use ($matched): int {
            $a = $matched[$ka];
            $b = $matched[$kb];

            return [$a['state'] === self::STATE_DONE, $a['group']['severity'], -$a['group']['open'], $a['pageTitle'], $ka]
                <=> [$b['state'] === self::STATE_DONE, $b['group']['severity'], -$b['group']['open'], $b['pageTitle'], $kb];
        });

        $total = count($matched);
        $pages = max(1, (int)ceil($total / $perPage));
        $page = max(1, min($pages, $page));
        $window = array_slice($matched, ($page - 1) * $perPage, $perPage, true);
        $this->preloadOwners($window);
        $tasks = [];
        foreach ($window as $entry) {
            $tasks[] = $this->task($entry['group'], $entry['assignment'], $entry['state'], $language, $entry['pageTitle']);
        }

        return [
            'tasks' => $tasks,
            'workload' => $assigner ? array_values($workload) : [],
            'unassignedOpen' => $assigner ? $unassignedOpen : 0,
            'filter' => $filter,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'rangeStart' => $total > 0 ? ($page - 1) * $perPage + 1 : 0,
            'rangeEnd' => min($total, $page * $perPage),
            'query' => $query,
        ];
    }

    /** @var array<string, string> */
    private array $ruleTitles = [];
    /** @var array<string, array<string, string>> site => issueKey => owner */
    private array $owners = [];

    private function cachedRuleTitle(string $issueKey, string $message, string $language): string
    {
        return $this->ruleTitles[$language . '|' . $issueKey . '|' . $message] ??= $this->ruleTitle($issueKey, $message, $language);
    }

    /**
     * Batched permission check for the non-shared groups (NEW-04): fills the readable cache with exactly
     * canUseLanguage() && canReadPageInLanguage(), computed with one query per batch instead of per page.
     *
     * @param array<string, array<string, mixed>> $groups
     */
    private function preloadReadability(array $groups): void
    {
        $userUid = $this->backendUserService->getBackendUserUid();
        $pairs = [];
        foreach ($groups as $group) {
            if (!empty($group['shared'])) {
                continue;
            }
            $cacheKey = $userUid . ':' . (int)$group['pageUid'] . ':' . (int)$group['languageUid'];
            if (!isset($this->readable[$cacheKey])) {
                $pairs[(int)$group['pageUid'] . ':' . (int)$group['languageUid']] = [(int)$group['pageUid'], (int)$group['languageUid']];
            }
        }
        if ($pairs === []) {
            return;
        }
        $readable = $this->scopeAccessService->readablePagesInLanguages(array_values($pairs));
        $languages = [];
        foreach ($pairs as $pairKey => [$pageUid, $languageUid]) {
            $languages[$languageUid] ??= $this->scopeAccessService->canUseLanguage($languageUid);
            $this->readable[$userUid . ':' . $pageUid . ':' . $languageUid] = $languages[$languageUid] && ($readable[$pairKey] ?? false);
        }
    }

    /**
     * The remediation owner of each rule of the page window, one lookup per site (NEW-04: was one per task).
     *
     * @param array<string, array<string, mixed>> $window
     */
    private function preloadOwners(array $window): void
    {
        if ($this->assignedOwnerService === null) {
            return;
        }
        $rulesBySite = [];
        foreach ($window as $entry) {
            if (empty($entry['group']['shared'])) {
                $rulesBySite[(string)$entry['group']['siteIdentifier']][(string)$entry['group']['issueKey']] = true;
            }
        }
        foreach ($rulesBySite as $site => $rules) {
            foreach (array_keys($rules) as $rule) {
                $this->owners[(string)$site][(string)$rule] = '';
            }
            foreach ($this->assignedOwnerService->forRules((string)$site, array_keys($rules)) as $rule => $data) {
                $this->owners[(string)$site][(string)$rule] = (string)($data['owner'] ?? '');
            }
        }
    }

    /**
     * Shared template groups (N-01) of the sites this user may see as a whole, keyed "site|0|0|template:…". Open count
     * covers every page and language; the representative page is the first one with an open occurrence (else the first
     * one at all) and serves as the link target.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sharedTemplateGroups(string $siteIdentifier): array
    {
        $groups = [];
        $templateRows = $this->repository->findTemplateFindings($siteIdentifier !== '' ? [$siteIdentifier] : []);
        $this->preloadSiteMembership(array_map(static fn (array $row): int => (int)$row['page_uid'], $templateRows));
        foreach ($templateRows as $row) {
            if (!$this->canSeeSharedWork($row['site_identifier']) || !$this->isCurrentSite($row['site_identifier'], $row['page_uid'])) {
                continue;
            }
            $issueKey = TemplateScope::key($row['rule_id'], $row['context_snippet']);
            $key = $this->key($row['site_identifier'], 0, 0, $issueKey);
            $open = $row['status'] === IssueStatus::Open->value;
            $group = $groups[$key] ?? [
                'siteIdentifier' => $row['site_identifier'], 'pageUid' => $row['page_uid'], 'languageUid' => $row['source_lang_uid'],
                'issueKey' => $issueKey, 'open' => 0, 'severity' => 99, 'lastChange' => 0, 'lastResolved' => 0,
                'ruleIds' => [], 'sourceTypes' => ['rendered' => true], 'message' => $row['message'],
                'shared' => true, 'pages' => [], 'languages' => [], 'representativeOpen' => false,
            ];
            if ($open) {
                $group['open']++;
                $group['severity'] = min($group['severity'], $row['severity']);
                $group['pages'][$row['page_uid']] = true;
                $group['languages'][$row['source_lang_uid']] = true;
                if (!$group['representativeOpen']) {
                    $group['pageUid'] = $row['page_uid'];
                    $group['languageUid'] = $row['source_lang_uid'];
                    $group['representativeOpen'] = true;
                }
            }
            $group['lastChange'] = max($group['lastChange'], $row['tstamp']);
            $group['lastResolved'] = max($group['lastResolved'], $row['resolved_at']);
            $group['ruleIds'][$row['rule_id']] = $row['rule_id'];
            $groups[$key] = $group;
        }

        return $groups;
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,mixed>|null $assignment
     * @return array<string,mixed>
     */
    private function task(array $group, ?array $assignment, string $state, string $language, ?string $pageTitle = null): array
    {
        if (!empty($group['shared'])) {
            return $this->sharedTask($group, $assignment, $state, $language);
        }
        $page = $pageTitle !== null ? ['title' => $pageTitle] : BackendUtility::getRecord(Tables::PAGES, (int)$group['pageUid'], 'uid,title');
        $assignee = $assignment !== null ? (int)$assignment['assignee'] : 0;
        $assigneeUser = $assignee > 0 ? $this->repository->findBackendUser($assignee) : null;
        $flag = $assignee > 0 ? $this->eligibility->reason($assignee, (int)$group['pageUid'], (int)$group['languageUid']) : '';
        $rendered = isset($group['sourceTypes']['rendered']) && !isset($group['sourceTypes']['content']);
        $owner = $this->owners[(string)$group['siteIdentifier']][(string)$group['issueKey']]
            ?? ($this->assignedOwnerService?->forRules((string)$group['siteIdentifier'], [(string)$group['issueKey']])[(string)$group['issueKey']]['owner'] ?? '');
        if (!is_string($owner) || !RemediationOwner::isValid($owner)) {
            $owner = RemediationOwner::suggest('', (string)$group['issueKey'], $rendered ? 'rendered' : 'rte', !$rendered);
        }

        return [
            'siteIdentifier' => (string)$group['siteIdentifier'],
            'pageUid' => (int)$group['pageUid'],
            'pageTitle' => is_array($page) ? (string)$page['title'] : ('#' . (int)$group['pageUid']),
            'languageUid' => (int)$group['languageUid'],
            'issueKey' => (string)$group['issueKey'],
            // The plain rule title, as on the Page Detail card (rule.<id>.title), never the stored message.
            'title' => $this->cachedRuleTitle((string)$group['issueKey'], (string)$group['message'], $language),
            'severity' => (int)$group['severity'],
            'open' => (int)$group['open'],
            'state' => $state,
            'needsRescan' => $state === self::STATE_OPEN
                && $this->repository->hasChangedSinceLastScan((string)$group['siteIdentifier'], (int)$group['pageUid'], (int)$group['languageUid'], array_values(array_unique($group['ruleIds']))),
            'doneAt' => $state === self::STATE_DONE ? (int)$group['lastResolved'] : 0,
            'assignee' => $assignee,
            'assigneeLabel' => $assigneeUser !== null ? AssigneeEligibility::label($assigneeUser) : '',
            'assigneeFlag' => $flag,
            'assignedAt' => $assignment !== null ? (int)$assignment['assigned_at'] : 0,
            'note' => $assignment !== null ? (string)$assignment['note'] : '',
            'owner' => $owner,
            'renderedOnly' => $rendered,
            'shared' => false,
            'linkRule' => (string)$group['issueKey'],
            'pageCount' => 1,
            'languageCount' => 1,
        ];
    }

    /**
     * @param array<string,mixed> $group
     * @param array<string,mixed>|null $assignment
     * @return array<string,mixed>
     */
    private function sharedTask(array $group, ?array $assignment, string $state, string $language): array
    {
        $assignee = $assignment !== null ? (int)$assignment['assignee'] : 0;
        $assigneeUser = $assignee > 0 ? $this->repository->findBackendUser($assignee) : null;
        $rootPageUid = (int)($this->siteResolutionService->resolveSiteByIdentifier((string)$group['siteIdentifier'])?->getRootPageId() ?? 0);
        $rule = TemplateScope::ruleOfKey((string)$group['issueKey']);

        return [
            'siteIdentifier' => (string)$group['siteIdentifier'],
            'pageUid' => (int)$group['pageUid'],
            'pageTitle' => \Priebera\A11yQualityGate\Utility\BackendLabelUtility::translate('assignment.shared.page', 'Site template'),
            'languageUid' => (int)$group['languageUid'],
            'issueKey' => (string)$group['issueKey'],
            'ruleKey' => $rule,
            // The rendered rule of the template card the task opens in Page Detail.
            'linkRule' => (string)(array_values($group['ruleIds'])[0] ?? $rule),
            'title' => $this->ruleTitle($rule, (string)$group['message'], $language),
            'severity' => (int)$group['severity'],
            'open' => (int)$group['open'],
            'state' => $state,
            'needsRescan' => false,
            'doneAt' => $state === self::STATE_DONE ? (int)$group['lastResolved'] : 0,
            'assignee' => $assignee,
            'assigneeLabel' => $assigneeUser !== null ? AssigneeEligibility::label($assigneeUser) : '',
            'assigneeFlag' => $assignee > 0 && $rootPageUid > 0 ? $this->eligibility->reason($assignee, $rootPageUid, 0) : '',
            'assignedAt' => $assignment !== null ? (int)$assignment['assigned_at'] : 0,
            'note' => $assignment !== null ? (string)$assignment['note'] : '',
            'owner' => RemediationOwner::DEVELOPER,
            'renderedOnly' => true,
            'shared' => true,
            'pageCount' => count($group['pages']),
            'languageCount' => count($group['languages']),
        ];
    }

    private function ruleTitle(string $issueKey, string $message, string $language): string
    {
        $title = \Priebera\A11yQualityGate\Utility\BackendLabelUtility::translate('rule.' . $issueKey . '.title', '');
        if ($title !== '' && $title !== 'rule.' . $issueKey . '.title') {
            return $title;
        }
        $friendly = (string)($this->ruleMetadataPresentationService?->friendlyTitleForRule($issueKey, $message, $language) ?? '');

        return $friendly !== '' && $friendly !== $issueKey ? $friendly : rtrim($message !== '' ? $message : $issueKey, '.');
    }

    private function isReadable(int $pageUid, int $languageUid): bool
    {
        $key = $this->backendUserService->getBackendUserUid() . ':' . $pageUid . ':' . $languageUid;

        return $this->readable[$key] ??= $this->scopeAccessService->canUseLanguage($languageUid)
            && $this->scopeAccessService->canReadPageInLanguage($pageUid, $languageUid);
    }

    /** A finding counts as work of its site only while its page still belongs to that site (D-03: renamed sites). */
    private function isCurrentSite(string $siteIdentifier, int $pageUid): bool
    {
        if (array_key_exists($pageUid, $this->pageSite)) {
            return $this->pageSite[$pageUid] === $siteIdentifier;
        }

        return $this->currentSite[$pageUid . ':' . $siteIdentifier] ??= $this->siteResolutionService->resolveSiteByPageId($pageUid)?->getIdentifier() === $siteIdentifier;
    }

    /** @var array<int, string> pageUid => identifier of the site it belongs to ('' = none) */
    private array $pageSite = [];

    /**
     * NEW-04: the site of many pages at once — the nearest ancestor (or the page itself) that is a site's root page, as
     * SiteFinder::getSiteByPageId() finds it through the rootline, but with one parent query per tree level instead of
     * a rootline per page. A page whose parent chain cannot be followed (deleted or missing parent) is left to the
     * per-page check.
     *
     * @param list<int> $pageUids
     */
    private function preloadSiteMembership(array $pageUids): void
    {
        $todo = array_values(array_diff(array_unique(array_filter($pageUids, static fn (int $uid): bool => $uid > 0)), array_keys($this->pageSite)));
        if ($todo === []) {
            return;
        }
        $rootToSite = [];
        foreach ($this->siteResolutionService->getAllSites() as $site) {
            $rootToSite[(int)$site->getRootPageId()] = $site->getIdentifier();
        }
        if ($rootToSite === []) {
            return;
        }
        $parents = [];
        $frontier = $todo;
        for ($depth = 0; $depth < 100 && $frontier !== []; $depth++) {
            $rows = $this->repository->findPageParents($frontier);
            $next = [];
            foreach ($frontier as $uid) {
                if (!array_key_exists($uid, $rows)) {
                    $parents[$uid] = null;
                    continue;
                }
                $parents[$uid] = $rows[$uid];
                $pid = $rows[$uid];
                if ($pid > 0 && !isset($rootToSite[$uid]) && !array_key_exists($pid, $parents)) {
                    $next[$pid] = $pid;
                }
            }
            $frontier = array_values($next);
        }
        foreach ($todo as $uid) {
            $current = $uid;
            for ($steps = 0; $steps < 100; $steps++) {
                if (isset($rootToSite[$current])) {
                    $this->pageSite[$uid] = $rootToSite[$current];
                    break;
                }
                if (!array_key_exists($current, $parents) || $parents[$current] === null) {
                    break;
                }
                $current = (int)$parents[$current];
                if ($current <= 0) {
                    $this->pageSite[$uid] = '';
                    break;
                }
            }
        }
    }

    private function key(string $siteIdentifier, int $pageUid, int $languageUid, string $issueKey): string
    {
        return $siteIdentifier . '|' . $pageUid . '|' . max(0, $languageUid) . '|' . $issueKey;
    }
}
