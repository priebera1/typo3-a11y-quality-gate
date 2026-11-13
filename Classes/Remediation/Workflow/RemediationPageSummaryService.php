<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\Contract\RemediationItemRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

/**
 * How each stored finding of a page can be fixed, who should fix it, and what the queue already holds for it — for
 * Page Detail, the page plan, Overview and the Page module. Classification reads the stored finding only (no AI call,
 * no rule re-run on render); whether a safe target really exists is decided again when an item is prepared.
 *
 * Fix paths: ai_image (image use, queue + AQG write), ai_text (rich text / Plain HTML, queue + editor), rule_editor
 * (deterministic fix in the editor, no AI), developer (template output: guidance only), manual (no safe action).
 */
final class RemediationPageSummaryService
{
    public const FIX_AI_IMAGE = 'ai_image';
    public const FIX_AI_TEXT = 'ai_text';
    public const FIX_RULE_EDITOR = 'rule_editor';
    public const FIX_DEVELOPER = 'developer';
    public const FIX_MANUAL = 'manual';

    public function __construct(
        private readonly RemediationItemRepositoryInterface $items,
        private readonly ImageFindingContextResolver $imageResolver,
        private readonly RuleMetadataPresentationService $ruleMetadata,
        private readonly BackendLanguageService $languageService,
        private readonly RemediationAccessService $access,
    ) {}

    /** @param array<string,mixed> $issue */
    public function fixPath(array $issue): string
    {
        $ruleId = (string)($issue['rule_id'] ?? '');
        $sourceType = (string)($issue['source_type'] ?? '');
        if ($this->imageResolver->supportsIssueRow($issue)) {
            return self::FIX_AI_IMAGE;
        }
        if ($sourceType === 'rte' && EditorAiRuleCatalog::supports($ruleId)) {
            return EditorAiRuleCatalog::hasAiFix($ruleId) ? self::FIX_AI_TEXT : self::FIX_RULE_EDITOR;
        }
        if ($sourceType === 'rendered') {
            return self::FIX_DEVELOPER;
        }

        return self::FIX_MANUAL;
    }

    /**
     * Per finding: owner, fix path and the active queue item, plus owner and queue counts of the open findings.
     *
     * @param list<array<string,mixed>> $issues tx_a11y_issue rows of one page
     * @return array{findings:array<int,array<string,mixed>>,owners:array<string,int>,queue:array<string,int>}
     */
    public function summarize(string $siteIdentifier, int $pageUid, array $issues): array
    {
        $assignments = $pageUid > 0 && $siteIdentifier !== '' ? $this->items->findOwnerAssignmentsForPage($siteIdentifier, $pageUid) : [];
        $active = [];
        foreach ($this->items->findActiveForFindings(array_map(static fn (array $issue): int => (int)$issue['uid'], $issues), RemediationStatus::ACTIVE) as $item) {
            // Only items this person may open (their workspace, their requests without the queue permission).
            if ($this->access->canSee($item)) {
                $active[(int)$item['finding_uid']] ??= $item;
            }
        }
        $owners = array_fill_keys(RemediationOwner::ALL, 0);
        $queue = ['pending' => 0, 'inEditor' => 0, 'saved' => 0, 'notVerified' => 0, 'problems' => 0];
        $findings = [];
        foreach ($issues as $issue) {
            $uid = (int)$issue['uid'];
            $path = $this->fixPath($issue);
            $owner = $this->owner($issue, $path, $assignments);
            $item = $active[$uid] ?? null;
            $findings[$uid] = [
                'owner' => $owner,
                'ownerLabel' => $this->label('remediation.owner.' . $owner),
                'ownerAssigned' => isset($assignments[(string)$issue['rule_id']]),
                'fixPath' => $path,
                'item' => $item === null ? null : [
                    'uid' => (int)$item['uid'],
                    'status' => (string)$item['status'],
                    'statusLabel' => $this->label('remediation.status.' . (string)$item['status']),
                    // Why a Failed / No suggestion item is in that state, so Page Detail can say it next to the status.
                    'reasonLabel' => in_array((string)$item['status'], [RemediationStatus::FAILED, RemediationStatus::NO_SUGGESTION, RemediationStatus::CONFLICT], true)
                        && (string)$item['status_reason'] !== '' && (string)$item['status_reason'] !== RemediationWorkflowService::GENERATING
                        ? $this->label('remediation.reason.' . (string)$item['status_reason']) : '',
                    'isReview' => (string)$item['status'] === RemediationStatus::PENDING_REVIEW,
                ],
            ];
            if ((int)$issue['status'] === IssueStatus::Open->value) {
                $owners[$owner]++;
            }
            if ($item !== null) {
                match ((string)$item['status']) {
                    RemediationStatus::PREPARING, RemediationStatus::PENDING_REVIEW, RemediationStatus::APPROVED => $queue['pending']++,
                    RemediationStatus::IN_EDITOR => $queue['inEditor']++,
                    RemediationStatus::SAVED, RemediationStatus::APPLYING => $queue['saved']++,
                    RemediationStatus::NOT_VERIFIED => $queue['notVerified']++,
                    default => $queue['problems']++,
                };
            }
        }

        return ['findings' => $findings, 'owners' => $owners, 'queue' => $queue];
    }

    /**
     * A page remediation plan from stored findings: open issue types grouped by owner, ordered by severity and reach,
     * each with its fix path, how-to guidance and queue state. Deterministic — no AI, nothing sent, nothing written.
     *
     * @param list<array<string,mixed>> $issues open tx_a11y_issue rows of one page and language
     * @return array{groups:list<array<string,mixed>>,total:int,text:string}
     */
    public function plan(string $siteIdentifier, int $pageUid, array $issues, string $pageTitle): array
    {
        $open = array_values(array_filter($issues, static fn (array $issue): bool => (int)$issue['status'] === IssueStatus::Open->value));
        $summary = $this->summarize($siteIdentifier, $pageUid, $open);
        $language = $this->languageService->getCurrentLanguageCode();

        $rules = [];
        foreach ($open as $issue) {
            $ruleId = (string)$issue['rule_id'];
            $finding = $summary['findings'][(int)$issue['uid']];
            $rules[$ruleId] ??= [
                'ruleId' => $ruleId,
                // The rule's own title, as Page Detail lists it (not the grouped "Links without discernible text").
                'title' => RemediationRuleTitle::plain($this->ruleMetadata, $ruleId, $language, (string)($issue['message'] ?? '')),
                'howToFix' => (string)($this->ruleMetadata->present($issue, $language)['localizedHowToFix'] ?? ''),
                'severity' => (int)$issue['severity'],
                'owner' => $finding['owner'],
                'fixPath' => $finding['fixPath'],
                'occurrences' => 0,
                'queued' => 0,
            ];
            $rules[$ruleId]['occurrences']++;
            $rules[$ruleId]['severity'] = min($rules[$ruleId]['severity'], (int)$issue['severity']);
            $rules[$ruleId]['queued'] += $finding['item'] !== null ? 1 : 0;
        }

        $order = [RemediationOwner::EDITOR, RemediationOwner::MIXED, RemediationOwner::DEVELOPER, RemediationOwner::UNASSIGNED];
        $groups = [];
        foreach ($order as $owner) {
            $steps = array_values(array_filter($rules, static fn (array $rule): bool => $rule['owner'] === $owner));
            if ($steps === []) {
                continue;
            }
            // Severity first (critical = 1), then reach on this page.
            usort($steps, static fn (array $a, array $b): int => [$a['severity'], -$a['occurrences']] <=> [$b['severity'], -$b['occurrences']]);
            foreach ($steps as &$step) {
                $step['severityKey'] = Severity::tryFrom($step['severity'])?->key() ?? 'info';
                $step['fixPathLabel'] = $this->label('remediation.fixPath.' . $step['fixPath']);
            }
            unset($step);
            $groups[] = ['owner' => $owner, 'ownerLabel' => $this->label('remediation.owner.' . $owner), 'steps' => $steps];
        }

        return ['groups' => $groups, 'total' => count($open), 'text' => $this->planText($pageTitle, $pageUid, $groups)];
    }

    /** @param list<array<string,mixed>> $groups */
    private function planText(string $pageTitle, int $pageUid, array $groups): string
    {
        $lines = [sprintf('%s: %s [%d]', $this->label('remediation.plan.title'), $pageTitle, $pageUid), ''];
        $number = 1;
        foreach ($groups as $group) {
            $lines[] = '## ' . $group['ownerLabel'];
            foreach ($group['steps'] as $step) {
                $lines[] = sprintf('%d. %s (%d × %s) — %s', $number++, $step['title'], $step['occurrences'], $step['ruleId'], $step['fixPathLabel']);
                if ($step['howToFix'] !== '') {
                    $lines[] = '   ' . $step['howToFix'];
                }
            }
            $lines[] = '';
        }
        $lines[] = $this->label('remediation.plan.textFooter');

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $issue
     * @param array<string,string> $assignments
     */
    private function owner(array $issue, string $path, array $assignments): string
    {
        $assigned = $assignments[(string)($issue['rule_id'] ?? '')] ?? null;
        if (is_string($assigned) && RemediationOwner::isValid($assigned)) {
            return $assigned;
        }
        $presented = $this->ruleMetadata->present($issue);

        return RemediationOwner::suggest(
            (string)($presented['owner'] ?? ''),
            (string)($issue['rule_id'] ?? ''),
            (string)($issue['source_type'] ?? ''),
            in_array($path, [self::FIX_AI_IMAGE, self::FIX_AI_TEXT, self::FIX_RULE_EDITOR], true),
        );
    }

    private function label(string $key): string
    {
        $value = $this->languageService->translate($key);

        return $value !== $key ? $value : '';
    }
}
