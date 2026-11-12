<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantContext;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantService;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationException;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\IssueLocationService;
use Priebera\A11yQualityGate\Service\LocalIssueGuidanceService;
use Priebera\A11yQualityGate\Service\ScanPermissionService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Ask AQG's repair workflow: an explicit request to fix content ("Fix this element", "Oprav mi túto stránku") becomes
 * an actionable plan instead of a text answer, built on the existing AI Fixes workflow only.
 *
 * plan()     — no AI request, no content write (like prepare(), it releases AI Fixes items left Preparing for over a
 *              day: RemediationWorkflowService::blockingItem()). The authorised open findings of the target (the content element, or the page,
 *              never the whole website) are run through RemediationWorkflowService::preflight(), the same checks
 *              prepare() makes: an open item already holding the finding, licence, a safe target re-located in the
 *              record as it is stored NOW, the user's AI rights and the site's AI readiness, field edit access
 *              (readOnly/displayCond via BackendRecordAccessService) and a readable image. Each finding is reported as
 *              ready, queued (with the exact AI Fixes item), saved, manual, blocked (with the genuine reason) or stale.
 * prepare()  — only after the editor's click, for findings of the same target that are ready at that moment: the
 *              existing RemediationWorkflowService::prepare() creates the AI Fixes items (no AI request yet). The browser
 *              then asks the existing generate route per item, with the row version returned here, so a retry or a
 *              second click can never pay twice (claims + row versions of the workflow).
 *
 * Nothing here writes content: every proposal waits for human review in AI Fixes, and applying it is the existing
 * approve step (image references) or the editor's own TYPO3 Save (rich text and Plain HTML).
 */
final class AskAqgRepairService
{
    public const TARGET_RECORD = 'record';
    public const TARGET_PAGE = 'page';
    public const TARGET_FILE = 'file';

    /** Findings evaluated per answer: the workflow's batch limit. */
    public const MAX_FINDINGS = RemediationWorkflowService::MAX_BATCH;

    private const MANUAL_REASONS = ['rendered_output', 'no_safe_action', 'manual_only', 'rule_based_in_editor', 'target_missing', 'no_edit_access'];

    public function __construct(
        private readonly ToolbarAssistantService $assistantService,
        private readonly RemediationWorkflowService $workflow,
        private readonly RemediationAccessService $access,
        private readonly LocalIssueGuidanceService $guidanceService,
        private readonly IssueLocationService $locationService,
        private readonly BackendRecordAccessService $recordAccess,
        private readonly BackendContextService $backendContextService,
        private readonly UriBuilder $uriBuilder,
        private readonly ?ScanPermissionService $scanPermissionService = null,
        private readonly ?AiActionCostEstimator $costEstimator = null,
        private readonly ?LockFactory $lockFactory = null,
    ) {}

    /**
     * The repair answer for an Ask AQG question (deterministic, no AI request, nothing written).
     *
     * @param array{repair:bool,mentions:string,kinds:list<string>} $intent
     * @return array<string,mixed> an Ask AQG payload with intent "repair" and the plan under "repair"
     */
    public function plan(ToolbarAssistantContext $context, string $scope, string $question, array $intent, string $requestedTarget = ''): array
    {
        if (!in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD, ToolbarAssistantContext::TYPE_FILE], true)) {
            throw new EditorAiException('no_context', 'Open a page, a content element or a file first.', 422);
        }
        $selected = $this->selectedTarget($context);
        $target = $this->target($context, $requestedTarget);
        $mentions = (string)($intent['mentions'] ?? '');
        $kinds = array_values(array_filter((array)($intent['kinds'] ?? []), 'is_string'));
        $notices = [];
        $alternatives = [];

        // The website is never a repair target here: an instruction is not silently widened into a site-wide bulk run.
        if ($scope === AskAqgService::SCOPE_SITE || $mentions === AskAqgRepairIntent::MENTION_SITE) {
            $notices[] = $this->t('toolbar.repair.notice.site', 'Ask AQG prepares fixes for one content element or page at a time, never for the whole website at once. It shows the selected target below; use AI fixes for other pages.');
        }
        if ($context->type === ToolbarAssistantContext::TYPE_RECORD && $target === self::TARGET_RECORD && $mentions === AskAqgRepairIntent::MENTION_PAGE) {
            // "Oprav mi túto stránku" with "This content element" selected: say which target is used and offer the page.
            $notices[] = $this->t('toolbar.repair.notice.pageWording', 'You asked about the page, but the selected scope is this content element. AQG shows only this element. Choose the whole page if you meant it.');
            $alternatives[] = ['target' => self::TARGET_PAGE, 'label' => $this->t('toolbar.repair.alt.page', 'Show fixes for the whole page')];
        } elseif ($context->type === ToolbarAssistantContext::TYPE_RECORD && $target === self::TARGET_PAGE) {
            $alternatives[] = ['target' => self::TARGET_RECORD, 'label' => $this->t('toolbar.repair.alt.record', 'Show only this content element')];
        }

        if ($context->workspace > 0) {
            $notices[] = $this->t('toolbar.repair.notice.workspace', 'Findings describe the live website. Suggestions prepared here belong to your current workspace and are applied in its version of the record.');
        }

        $all = $this->findingsOf($context, $target);
        $issues = $kinds === [] ? $all : array_values(array_filter($all, static fn (array $issue): bool => in_array(self::kindOfRule((string)$issue['rule_id']), $kinds, true)));
        if ($kinds !== [] && count($issues) < count($all)) {
            $notices[] = sprintf($this->t('toolbar.repair.notice.kinds', 'Only the kinds of problems you named are included; %d other open issues of this target are not.'), count($all) - count($issues));
        }
        $more = max(0, count($issues) - self::MAX_FINDINGS);
        $issues = array_slice($issues, 0, self::MAX_FINDINGS);
        if ($more > 0) {
            $notices[] = sprintf($this->t('toolbar.repair.notice.more', 'Only the %1$d most severe issues are included; %2$d more are in Page Detail.'), self::MAX_FINDINGS, $more);
        }

        $evaluated = $issues === [] ? [] : $this->workflow->preflight(array_map(static fn (array $issue): int => (int)$issue['uid'], $issues), false);
        $byFinding = [];
        foreach ($evaluated as $entry) {
            $byFinding[$entry['findingUid']] = $entry;
        }

        $siteIdentifier = AskAqgService::siteOf($context, $issues);
        $aiState = $siteIdentifier !== '' ? $this->access->aiState($siteIdentifier) : 'none';
        $licence = $siteIdentifier !== '' && $this->access->hasLicence($siteIdentifier);
        $language = $this->backendContextService->getCurrentLanguageCode() === 'de' ? 'de' : 'en';
        $queueUrl = $this->queueUrl($context->pageUid > 0 ? $context->pageUid : (int)($issues[0]['page_uid'] ?? 0), 0);

        $items = [];
        $counts = ['found' => count($issues), 'ready' => 0, 'queued' => 0, 'saved' => 0, 'manual' => 0, 'blocked' => 0, 'stale' => 0];
        $cost = 0;
        $costUnknown = false;
        $readyIds = [];
        foreach ($issues as $issue) {
            $entry = $byFinding[(int)$issue['uid']] ?? ['outcome' => 'skipped', 'reason' => 'not_found', 'itemUid' => 0, 'itemStatus' => '', 'itemReason' => '', 'aiKind' => '', 'targetKind' => ''];
            $item = $this->item($context, $issue, $entry, $language);
            if ($item === null) {
                continue;
            }
            $counts[$item['state']]++;
            if ($item['state'] === 'ready') {
                $readyIds[] = $item['id'];
                $micro = $this->maxCost((string)$issue['site_identifier'], (string)$entry['aiKind']);
                if ($micro === null) {
                    $costUnknown = true;
                } else {
                    $cost += $micro;
                    $item['cost'] = $this->money($micro, $language);
                }
            }
            $items[] = $item;
        }
        $counts['found'] = count($items);

        $freshness = $this->freshness($context);
        $repair = [
            'target' => $target,
            'targetLabel' => $this->targetLabel($context, $target),
            'selectedTarget' => $selected,
            'notices' => $notices,
            'alternatives' => $alternatives,
            'counts' => $counts,
            'headline' => $this->headline($counts['found'], $target),
            'summary' => $this->summaryLines($counts),
            'freshness' => $freshness,
            'ai' => [
                'ready' => $aiState === EditorAiAccessService::STATE_READY,
                'state' => $aiState,
                'licence' => $licence,
                'reason' => $aiState === EditorAiAccessService::STATE_READY ? '' : $this->stateReason($aiState, $licence),
            ],
            'bulk' => $this->access->mayUseBulk(),
            'cost' => [
                'total' => $readyIds !== [] && !$costUnknown ? $this->money($cost, $language) : '',
                'unknown' => $readyIds !== [] && $costUnknown,
                'requests' => count($readyIds),
            ],
            'items' => $items,
            'prepareIds' => $readyIds,
            'queueUrl' => $queueUrl,
            'editUrl' => $context->type === ToolbarAssistantContext::TYPE_RECORD && !$context->isEditingRecord() && $this->recordAccess->canEditRecord($context->table, $context->recordUid)
                ? $this->assistantService->editUrl($context->table, $context->recordUid) : '',
        ];

        $answer = $repair['headline'] . ($repair['summary'] !== [] ? "\n" . implode("\n", array_map(static fn (string $line): string => '- ' . $line, $repair['summary'])) : '');

        return [
            'status' => AskAqgPrompts::STATUS_ANSWER,
            'source' => 'aqg',
            'intent' => 'repair',
            'scope' => AskAqgService::SCOPE_PAGE,
            'question' => AskAqgService::normaliseQuestion($question) !== '' ? AskAqgService::normaliseQuestion($question) : $this->t('toolbar.repair.question', 'Fix this'),
            'answer' => $answer,
            'nextStep' => '',
            'generalAdvice' => false,
            'basedOnFindings' => $items !== [],
            'citations' => [],
            'notes' => [],
            'totals' => ['issue_types' => count(array_unique(array_column($issues, 'rule_id'))), 'occurrences' => count($issues)],
            'sent' => [],
            'repair' => $repair,
        ];
    }

    /**
     * "Prepare fixes": creates AI Fixes items for the given findings of the target that are ready now (existing
     * prepare(), no AI request). Findings outside the target, or no longer ready, are reported and never prepared.
     *
     * @param list<mixed> $findingIds
     * @return array<string,mixed>
     */
    public function prepare(ToolbarAssistantContext $context, string $requestedTarget, array $findingIds): array
    {
        if (!in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD, ToolbarAssistantContext::TYPE_FILE], true)) {
            throw new EditorAiException('no_context', 'Open a page, a content element or a file first.', 422);
        }
        $target = $this->target($context, $requestedTarget);
        $requested = array_values(array_unique(array_filter(array_map(static fn (mixed $id): int => is_scalar($id) ? (int)$id : 0, $findingIds), static fn (int $id): bool => $id > 0)));
        if ($requested === [] || count($requested) > self::MAX_FINDINGS) {
            throw new EditorAiException('invalid_input', 'Choose between 1 and 50 findings.', 422);
        }
        if (count($requested) > 1 && !$this->access->mayUseBulk()) {
            // Preparing several suggestions in one step is the queue permission (ai.bulk), exactly as in Page Detail.
            throw new EditorAiException('permission_denied', 'Preparing several suggestions at once needs the AI queue permission.', 403);
        }
        // Only findings of this very target (the same authorised set the plan showed); anything else is refused.
        $inTarget = [];
        foreach ($this->findingsOf($context, $target) as $issue) {
            $inTarget[(int)$issue['uid']] = $issue;
        }
        $outside = array_values(array_diff($requested, array_keys($inTarget)));
        $requested = array_values(array_intersect($requested, array_keys($inTarget)));
        if ($requested === []) {
            throw new EditorAiException('scope_restricted', 'These findings do not belong to the selected target.', 403);
        }

        $lock = $this->acquireLock($this->access->userUid());
        try {
            $prepared = $this->workflow->prepare($requested, count($requested) > 1 ? RemediationAccessService::ORIGIN_BULK : RemediationAccessService::ORIGIN_EDITOR_REQUEST);
        } catch (RemediationException $exception) {
            throw new EditorAiException($exception->safeCode, 'The fixes could not be prepared.', $exception->httpStatus);
        } finally {
            $lock?->release();
        }

        $results = [];
        foreach ($prepared['items'] as $entry) {
            $issue = $inTarget[(int)$entry['findingUid']] ?? [];
            $result = [
                'id' => (int)$entry['findingUid'],
                'outcome' => (string)$entry['outcome'],
                'reason' => (string)$entry['reason'],
                'reasonLabel' => (string)$entry['reason'] !== '' ? $this->reasonLabel((string)$entry['reason']) : '',
                'itemUid' => 0,
                'rowVersion' => 0,
                'status' => '',
                'statusLabel' => '',
                'generate' => false,
                'reviewUrl' => '',
            ];
            $itemUid = (int)$entry['itemUid'];
            if ($itemUid > 0) {
                try {
                    $item = $this->workflow->visibleItem($itemUid);
                } catch (RemediationException) {
                    $item = null;
                }
                if (is_array($item)) {
                    $status = (string)$item['status'];
                    $result['itemUid'] = $itemUid;
                    $result['rowVersion'] = (int)$item['row_version'];
                    $result['status'] = $status;
                    $result['statusLabel'] = $this->t('remediation.status.' . $status, $status);
                    $result['reviewUrl'] = $this->queueUrl((int)$item['page_uid'], $itemUid);
                    // Asked for a suggestion only for an item this click created (the plan's cost estimate covers exactly
                    // those), while it is still Preparing and nobody generates it. An item that already existed (another
                    // person's, an earlier click's, Pending review, Failed) is linked, never generated from here.
                    $result['generate'] = (string)$entry['outcome'] === 'queued'
                        && $status === RemediationStatus::PREPARING
                        && !RemediationWorkflowService::isGenerating($item)
                        && ((int)$item['created_by'] === $this->access->userUid() || $this->access->mayUseBulk());
                }
            }
            $results[] = $result;
        }
        foreach ($outside as $id) {
            $results[] = ['id' => $id, 'outcome' => 'skipped', 'reason' => 'scope_restricted', 'reasonLabel' => $this->t('toolbar.repair.reason.outside', 'Not part of the selected target.'), 'itemUid' => 0, 'rowVersion' => 0, 'status' => '', 'statusLabel' => '', 'generate' => false, 'reviewUrl' => ''];
        }

        return [
            'status' => 'prepared',
            'target' => $target,
            'items' => $results,
            'queueUrl' => $this->queueUrl($context->pageUid > 0 ? $context->pageUid : (int)((reset($inTarget) ?: [])['page_uid'] ?? 0), 0),
        ];
    }

    /**
     * The authorised open findings of the target, most severe first (ToolbarAssistantService decides access exactly as
     * for every Ask AQG answer). A file collects only the findings of the site whose AI settings apply.
     *
     * @return list<array<string,mixed>>
     */
    private function findingsOf(ToolbarAssistantContext $context, string $target): array
    {
        $issues = $target === self::TARGET_PAGE ? $this->assistantService->openPageIssues($context) : $this->assistantService->openIssues($context);
        if ($context->type === ToolbarAssistantContext::TYPE_FILE) {
            $issues = AskAqgService::issuesOfSite($issues, AskAqgService::siteOf($context, $issues));
        }

        // The record each finding belongs to must be readable now, not only its page (another table, language or
        // a moved element): nothing of it is listed, and nothing is prepared for it.
        return $this->assistantService->withReadableSources($issues);
    }

    /** What the selected Ask scope means for this context: the element, the page or the file. */
    private function selectedTarget(ToolbarAssistantContext $context): string
    {
        return match ($context->type) {
            ToolbarAssistantContext::TYPE_RECORD => self::TARGET_RECORD,
            ToolbarAssistantContext::TYPE_FILE => self::TARGET_FILE,
            default => self::TARGET_PAGE,
        };
    }

    /** The target to use: the selected one, or the page of the open element when the editor chose it explicitly. */
    private function target(ToolbarAssistantContext $context, string $requested): string
    {
        $selected = $this->selectedTarget($context);
        if ($requested === self::TARGET_PAGE && $context->type === ToolbarAssistantContext::TYPE_RECORD && $context->pageUid > 0) {
            return self::TARGET_PAGE;
        }

        return $selected;
    }

    /**
     * @param array<string,mixed> $issue
     * @param array<string,mixed> $entry
     * @return array<string,mixed>|null
     */
    private function item(ToolbarAssistantContext $context, array $issue, array $entry, string $language): ?array
    {
        $outcome = (string)$entry['outcome'];
        $reason = (string)$entry['reason'];
        if ($outcome === 'skipped' && in_array($reason, ['not_found', 'finding_closed'], true)) {
            // Closed or no longer readable since the list was read: not part of the answer.
            return null;
        }
        $guidance = $this->guidanceService->present($issue, $language);
        $title = (string)(($guidance['localizedTitle'] ?? '') !== '' ? $guidance['localizedTitle'] : (($guidance['title'] ?? '') !== '' ? $guidance['title'] : $issue['rule_id']));
        $severity = Severity::fromInt((int)$issue['severity']);
        $location = $this->locationService->describe((string)$issue['source_table'], (int)$issue['source_uid'], (string)$issue['source_field']);
        $table = (string)($issue['source_table'] ?? '');
        $recordUid = (int)($issue['source_uid'] ?? 0);
        $item = [
            'id' => (int)$issue['uid'],
            'ruleId' => (string)$issue['rule_id'],
            'title' => $title,
            'tone' => ToolbarAssistantService::tone($severity),
            'severityLabel' => $this->t('severity.' . $severity->key(), $severity->label()),
            'location' => trim(implode(' · ', array_filter([(string)($location['title'] ?? ''), (string)($location['fieldLabel'] ?? '')]))),
            'kind' => (string)$entry['aiKind'] !== '' ? (string)$entry['aiKind'] : self::kindOfRule((string)$issue['rule_id']),
            'state' => 'manual',
            'stateLabel' => '',
            'reason' => '',
            'itemUid' => 0,
            'itemStatus' => '',
            'reviewUrl' => '',
            'cost' => '',
            'detailUrl' => $this->assistantService->pageDetailUrl($issue),
            // Not for the record whose form is open already (the editor is in it); otherwise only with edit access.
            'editUrl' => $table !== '' && $recordUid > 0 && !($context->isEditingRecord() && $context->table === $table && $context->recordUid === $recordUid)
                && $this->recordAccess->canEditRecord($table, $recordUid) ? $this->assistantService->editUrl($table, $recordUid) : '',
            'howToFix' => ($guidance['howToFixIsFallback'] ?? false) === true ? '' : mb_substr((string)($guidance['howToFix'] ?? ''), 0, 300),
        ];

        if ($outcome === 'ready') {
            $item['state'] = 'ready';
            $item['stateLabel'] = $this->t('toolbar.repair.state.ready', 'Can be prepared');
            $item['reason'] = $this->t('toolbar.repair.reason.ready', 'Found in the saved content just now.');

            return $item;
        }
        if ($outcome === 'already_queued') {
            if ((int)$entry['itemUid'] > 0) {
                $item['state'] = 'queued';
                $item['itemUid'] = (int)$entry['itemUid'];
                $item['itemStatus'] = (string)$entry['itemStatus'];
                $item['stateLabel'] = $this->t('remediation.status.' . $entry['itemStatus'], (string)$entry['itemStatus']);
                $item['reason'] = $this->t('toolbar.repair.reason.queued', 'Already in AI fixes; nothing new is prepared.');
                $item['reviewUrl'] = $this->queueUrl((int)$issue['page_uid'], (int)$entry['itemUid']);
            } else {
                // Held by another person's item that this user may not open: not revealed, not prepared twice.
                $item['state'] = 'blocked';
                $item['stateLabel'] = $this->t('toolbar.repair.state.held', 'Requested by someone else');
                $item['reason'] = $this->t('toolbar.repair.reason.held', 'Another person already requested a suggestion for this issue.');
            }

            return $item;
        }
        if ((int)($entry['savedItemUid'] ?? 0) > 0) {
            $item['state'] = 'saved';
            $item['itemUid'] = (int)$entry['savedItemUid'];
            $item['stateLabel'] = $this->t('remediation.status.' . (string)$entry['savedItemStatus'], (string)$entry['savedItemStatus']);
            $item['reason'] = $this->t('toolbar.repair.reason.saved', 'A change for this issue was saved. Check the saved page to confirm it.');
            $item['reviewUrl'] = $this->queueUrl((int)$issue['page_uid'], (int)$entry['savedItemUid']);

            return $item;
        }
        if ($reason === 'target_changed') {
            $item['state'] = 'stale';
            $item['stateLabel'] = $this->t('toolbar.repair.state.stale', 'Not found in the saved content');
            $item['reason'] = $this->t('toolbar.repair.reason.stale', 'The saved content no longer matches this finding. It may be fixed already; check the saved page.');

            return $item;
        }
        if (in_array($reason, self::MANUAL_REASONS, true)) {
            $item['state'] = 'manual';
            $item['stateLabel'] = $this->t('toolbar.repair.state.manual', 'Needs manual work');
            $item['reason'] = $this->reasonLabel($reason);

            return $item;
        }
        // Licence, AI readiness, rights, privacy switches, unreadable images: genuine reasons, with the manual path.
        $item['state'] = 'blocked';
        $item['stateLabel'] = $this->t('toolbar.repair.state.blocked', 'Cannot be prepared now');
        $item['reason'] = $this->reasonLabel($reason);

        return $item;
    }

    /** The AI kind a rule is about (for "fix the alt texts"): the AI Fixes catalogue first, then the rule id. */
    public static function kindOfRule(string $ruleId): string
    {
        $entry = EditorAiRuleCatalog::entry($ruleId);
        if ($entry !== null) {
            return $entry['kind'] === EditorAiRuleCatalog::KIND_HEADING_LEVEL ? EditorAiRuleCatalog::KIND_HEADING_TEXT : $entry['kind'];
        }
        if (in_array($ruleId, ImageFindingContextResolver::SUPPORTED_RULE_IDS, true)) {
            return EditorAiRuleCatalog::KIND_IMAGE_ALT;
        }

        return match (true) {
            preg_match('/iframe|frame/i', $ruleId) === 1 => EditorAiRuleCatalog::KIND_IFRAME_TITLE,
            preg_match('/button/i', $ruleId) === 1 => EditorAiRuleCatalog::KIND_BUTTON_LABEL,
            preg_match('/alt|img|image/i', $ruleId) === 1 => EditorAiRuleCatalog::KIND_IMAGE_ALT,
            preg_match('/link/i', $ruleId) === 1 => EditorAiRuleCatalog::KIND_LINK_TEXT,
            preg_match('/heading/i', $ruleId) === 1 => EditorAiRuleCatalog::KIND_HEADING_TEXT,
            default => '',
        };
    }

    /** @return array{label:string,stale:bool,never:bool,canCheck:bool,pageUid:int,languageUid:int,text:string} */
    private function freshness(ToolbarAssistantContext $context): array
    {
        $freshness = $this->assistantService->freshness($context);
        $canCheck = $context->pageUid > 0 && $context->type !== ToolbarAssistantContext::TYPE_FILE
            && ($this->scanPermissionService?->canScanPage($context->pageUid) ?? false);
        $never = $freshness['known'] && $freshness['checkedAt'] === 0;
        $text = match (true) {
            $never => $this->t('toolbar.repair.fresh.never', 'AQG has no completed content check of this page yet, so these findings may be incomplete.'),
            $freshness['stale'] => sprintf($this->t('toolbar.repair.fresh.stale', 'The last content check (%s) is older than 14 days. Problems that can be prepared were found in the saved content just now; the others may be fixed already or incomplete.'), $freshness['label']),
            $freshness['checkedAt'] > 0 => sprintf($this->t('toolbar.repair.fresh.checked', 'Based on the content check of %s.'), $freshness['label']),
            default => '',
        };

        return [
            'label' => $freshness['label'],
            'stale' => $freshness['stale'],
            'never' => $never,
            // "Check saved page now" only where it is useful and the user may scan; nothing is scanned automatically.
            'canCheck' => $canCheck && ($freshness['stale'] || $never),
            'pageUid' => $canCheck ? $context->pageUid : 0,
            'languageUid' => max(0, $context->languageUid),
            'text' => $text,
        ];
    }

    private function targetLabel(ToolbarAssistantContext $context, string $target): string
    {
        return match ($target) {
            self::TARGET_RECORD => sprintf($this->t('toolbar.repair.target.record', 'Content element: %s'), $context->recordTitle !== '' ? $context->recordTitle : '#' . $context->recordUid),
            self::TARGET_FILE => sprintf($this->t('toolbar.repair.target.file', 'File: %s'), $context->fileName),
            default => sprintf($this->t('toolbar.repair.target.page', 'Page: %s'), $context->pageTitle !== '' ? $context->pageTitle : '#' . $context->pageUid),
        };
    }

    private function headline(int $found, string $target): string
    {
        if ($found === 0) {
            return $this->t('toolbar.repair.headline.none', 'AQG stores no open issues you can access for this target. That does not prove it is accessible; a new content check shows the current state.');
        }
        $where = match ($target) {
            self::TARGET_RECORD => $this->t('toolbar.repair.where.record', 'this content element'),
            self::TARGET_FILE => $this->t('toolbar.repair.where.file', 'this file'),
            default => $this->t('toolbar.repair.where.page', 'this page'),
        };

        return $found === 1
            ? sprintf($this->t('toolbar.repair.headline.one', 'I found 1 problem in %s.'), $where)
            : sprintf($this->t('toolbar.repair.headline.many', 'I found %1$d problems in %2$s.'), $found, $where);
    }

    /**
     * @param array<string,int> $counts
     * @return list<string>
     */
    private function summaryLines(array $counts): array
    {
        $lines = [];
        $line = fn (int $count, string $one, string $oneFallback, string $many, string $manyFallback): string => $count === 1 ? $this->t($one, $oneFallback) : sprintf($this->t($many, $manyFallback), $count);
        if ($counts['ready'] > 0) {
            $lines[] = $line($counts['ready'], 'toolbar.repair.sum.readyOne', '1 AI suggestion can be prepared for your review.', 'toolbar.repair.sum.ready', '%d AI suggestions can be prepared for your review.');
        }
        if ($counts['queued'] > 0) {
            $lines[] = $line($counts['queued'], 'toolbar.repair.sum.queuedOne', '1 is already in AI Fixes.', 'toolbar.repair.sum.queued', '%d are already in AI Fixes.');
        }
        if ($counts['saved'] > 0) {
            $lines[] = $line($counts['saved'], 'toolbar.repair.sum.savedOne', '1 change was saved and waits for a check.', 'toolbar.repair.sum.saved', '%d changes were saved and wait for a check.');
        }
        if ($counts['manual'] > 0) {
            $lines[] = $line($counts['manual'], 'toolbar.repair.sum.manualOne', '1 needs manual work.', 'toolbar.repair.sum.manual', '%d need manual work.');
        }
        if ($counts['blocked'] > 0) {
            $lines[] = $line($counts['blocked'], 'toolbar.repair.sum.blockedOne', '1 cannot be prepared now.', 'toolbar.repair.sum.blocked', '%d cannot be prepared now.');
        }
        if ($counts['stale'] > 0) {
            $lines[] = $line($counts['stale'], 'toolbar.repair.sum.staleOne', '1 no longer matches the saved content.', 'toolbar.repair.sum.stale', '%d no longer match the saved content.');
        }

        return $lines;
    }

    private function stateReason(string $state, bool $licence): string
    {
        if (!$licence) {
            return $this->t('toolbar.repair.reason.no_licence', 'AI fixes need a PRO or Agency licence for this site.');
        }

        return $this->reasonLabel($state);
    }

    /** A refusal or skip reason as text: AI Fixes' own labels first, then Ask AQG's for readiness states. */
    private function reasonLabel(string $reason): string
    {
        $map = [
            EditorAiAccessService::STATE_PAUSED => 'ai_paused',
            EditorAiAccessService::STATE_BUDGET_REACHED => 'budget_reached',
            EditorAiAccessService::STATE_NOT_CONFIGURED => 'provider_not_configured',
        ];
        $reason = $map[$reason] ?? $reason;
        $translated = $this->t('remediation.reason.' . $reason, '');
        if ($translated !== '') {
            return $translated;
        }

        return match ($reason) {
            'no_licence' => $this->t('toolbar.repair.reason.no_licence', 'AI fixes need a PRO or Agency licence for this site.'),
            'no_permission', 'permission_denied' => $this->t('toolbar.repair.reason.no_permission', 'Your account may not request AI suggestions for this. Ask an administrator, or fix it in the editor.'),
            'privacy_page_title', 'privacy_images' => $this->t('toolbar.repair.reason.privacy', 'The site\'s AI privacy settings do not allow this suggestion.'),
            'usage_unavailable' => $this->t('toolbar.ask.error.usage', 'AQG could not record this AI question, so it was not sent. Try again later.'),
            'none' => $this->t('toolbar.repair.reason.no_site', 'No site is known for this context.'),
            default => $this->t('toolbar.repair.reason.unavailable', 'AI is not available for this site right now. Fix it in the editor, or ask an administrator.'),
        };
    }

    private function maxCost(string $siteIdentifier, string $aiKind): ?int
    {
        try {
            return $this->costEstimator?->maxCostMicro($siteIdentifier, $aiKind === EditorAiRuleCatalog::KIND_IMAGE_ALT ? AiActionCostEstimator::ACTION_FIX_IMAGE : AiActionCostEstimator::ACTION_FIX_TEXT);
        } catch (\Throwable) {
            return null;
        }
    }

    private function money(int $micro, string $language): string
    {
        return sprintf($this->t('ai.cost.max', '<$%s'), AiActionCostEstimator::formatUpperBound($micro, $language === 'de' ? ',' : '.'));
    }

    /** The AI Fixes list of the page, or one item's review drawer (the item route checks visibility again). */
    private function queueUrl(int $pageUid, int $itemUid): string
    {
        $parameters = $pageUid > 0 ? ['id' => $pageUid, 'pageUid' => $pageUid] : [];
        if ($itemUid > 0) {
            $parameters['item'] = $itemUid;
        }
        try {
            return (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remediation', $parameters);
        } catch (\Throwable) {
            return '';
        }
    }

    private function acquireLock(int $beUser): ?LockingStrategyInterface
    {
        if ($this->lockFactory === null) {
            return null;
        }
        $mode = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        try {
            $lock = $this->lockFactory->createLocker('aqg_toolbar_repair_' . $beUser, $mode);
            if (!$lock->acquire($mode)) {
                throw new EditorAiException('busy', 'Fixes are being prepared already.', 429);
            }

            return $lock;
        } catch (LockAcquireWouldBlockException) {
            throw new EditorAiException('busy', 'Fixes are being prepared already.', 429);
        }
    }

    private function t(string $key, string $fallback): string
    {
        $translated = $this->backendContextService->translate($key);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }
}
