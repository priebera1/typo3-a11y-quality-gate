<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiImageResolver;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Concurrency\DatabaseMutex;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\RemediationItemRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageRemediationValidationException;
use Priebera\A11yQualityGate\Remediation\InvalidImageVersionTokenException;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The remediation workflow of one item: Detect → Suggest → Review → Approve → Apply → Save → Verify.
 *
 * Every step is decided here, on the server, from stored data: the browser names an item and the row version it saw,
 * never a table, uid or field. A step happens only through a compare-and-set transition of the item row, so a second
 * click, a second person or a stale screen cannot repeat it. The only server write is the existing allow-listed image
 * path (ImageRemediationService → DataHandler → sys_file_reference.alternative), behind a check of the target value
 * the suggestion was made for, under a row lock. Rich text and Plain HTML are handed to the person's own editor and
 * stored with TYPO3 Save. "Verified" is set only by an AQG check of the stored record.
 *
 * AI work reuses Packages 1–3: the editor's fix for rich text (EditorAiRemediationService) and the contextual image
 * alternative for one image use (ReferenceAltAiService), so provider, privacy switches, rate limits and the usage
 * ledger are the ones those paths already enforce. The queue stores the proposed value and provenance, never prompts,
 * images or field content.
 */
final class RemediationWorkflowService
{
    public const MAX_BATCH = 50;
    /** An "applying" claim older than this was interrupted; the stored value tells whether the write happened. */
    private const APPLY_INTERRUPTED_AFTER = 300;
    /** A staged change nobody saved returns to Approved after this time (no unsaved editor holds it any more). */
    private const STAGE_RELEASE_AFTER = 1800;

    public const REJECT_REASONS = ['none', 'factually_wrong', 'decorative', 'solved_differently', 'other'];

    /**
     * The in-flight state of a Preparing item while one request asks the provider (status_reason), and how long it
     * holds: longer than the slowest provider request (45 s), so only an interrupted request ever runs out.
     */
    public const GENERATING = 'generating';
    public const GENERATION_LEASE = 180;

    /** A suggestion nobody generated within this time no longer holds its finding. */
    private const PREPARING_EXPIRES_AFTER = 86400;
    /** NEW-09: the per-finding prepare mutex; a crashed holder frees it after the TTL, a waiter waits this long. */
    private const PREPARE_MUTEX_TTL = 30;
    private const PREPARE_MUTEX_WAIT_MS = 3000;

    /** Failure reasons of the apply step; every other failure is a failed generation. */
    public const APPLY_FAILURES = ['write_failed', 'interrupted', 'permission_denied', 'workspace_mismatch', 'invalid_value'];

    /** Apply failures after which nothing was written and the same reviewed value may be sent again. */
    public const RETRYABLE_APPLY_FAILURES = ['write_failed', 'interrupted'];

    public function __construct(
        private readonly RemediationItemRepositoryInterface $items,
        private readonly IssueRemediationRepositoryInterface $issues,
        private readonly RemediationTargetResolver $targetResolver,
        private readonly RemediationAccessService $access,
        private readonly ReferenceAltAiService $referenceAi,
        private readonly EditorAiRemediationService $editorAi,
        private readonly EditorAiValueValidator $valueValidator,
        private readonly ImageAltTextValidator $altTextValidator,
        private readonly ImageRemediationServiceInterface $imageRemediation,
        private readonly ImageFindingContextResolver $imageResolver,
        private readonly ImageFindingVersionTokenService $versionTokens,
        private readonly RuleMetadataPresentationServiceInterface $ruleMetadata,
        private readonly ConnectionPool $connectionPool,
        private readonly ?DatabaseMutex $prepareMutex = null,
    ) {}

    /**
     * Creates queue items for open local findings (nothing is generated yet; see generate()). Findings without a safe
     * target, without permission, or already held by an active item are reported per finding and skipped.
     *
     * @param list<int> $findingUids
     * @return array{batchId:string,items:list<array{findingUid:int,itemUid:int,outcome:string,reason:string}>}
     */
    public function prepare(array $findingUids, string $origin): array
    {
        $this->access->assertLoggedIn();
        if ($origin === RemediationAccessService::ORIGIN_AGENT) {
            throw new RemediationException('agent_disabled', 'Agents cannot prepare suggestions in this release.', 403);
        }
        $findingUids = array_values(array_unique(array_filter(array_map('intval', $findingUids), static fn (int $uid): bool => $uid > 0)));
        if ($findingUids === []) {
            throw new RemediationException('invalid_input', 'No finding selected.', 422);
        }
        if (count($findingUids) > self::MAX_BATCH) {
            throw new RemediationException('batch_too_large', 'At most 50 findings per run.', 422, ['max' => self::MAX_BATCH]);
        }
        $bulk = $origin === RemediationAccessService::ORIGIN_BULK || count($findingUids) > 1;
        if ($bulk && !$this->access->mayUseBulk()) {
            throw new RemediationException('permission_denied', 'Preparing several suggestions needs the queue permission.', 403);
        }
        $batchId = $bulk ? bin2hex(random_bytes(8)) : '';

        $results = [];
        foreach ($findingUids as $findingUid) {
            $results[] = ['findingUid' => $findingUid] + $this->prepareOne($findingUid, $bulk ? RemediationAccessService::ORIGIN_BULK : RemediationAccessService::ORIGIN_EDITOR_REQUEST, $batchId, $bulk);
        }

        return ['batchId' => $batchId, 'items' => $results];
    }

    /**
     * "Explain with AI" for a stored content-scan finding (Page Detail). An explanation only: nothing is queued,
     * proposed or written. The finding is located in the record as it is stored now, and the request goes through the
     * editor's explain path, so the same licence, site readiness, edit-access, privacy, rate-limit and budget checks
     * and the same minimal context apply. Supported: the rich-text rules of EditorAiRuleCatalog.
     *
     * @return array<string,mixed>
     */
    public function explain(int $findingUid): array
    {
        $this->access->assertLoggedIn();
        $issue = $this->issues->findByUid($findingUid);
        if (!is_array($issue) || !$this->access->canReadPage((int)$issue['page_uid'])) {
            throw new RemediationException('not_found', 'The finding does not exist.', 404);
        }
        if ((int)$issue['status'] !== IssueStatus::Open->value) {
            throw new RemediationException('finding_closed', 'The finding is no longer open.', 409);
        }
        $siteIdentifier = (string)$issue['site_identifier'];
        if (!$this->access->hasLicence($siteIdentifier)) {
            throw new RemediationException('no_licence', 'AI explanations need a PRO or Agency licence.', 403);
        }
        $ruleId = (string)$issue['rule_id'];
        if ((string)($issue['source_type'] ?? '') !== 'rte' || !EditorAiRuleCatalog::supports($ruleId)) {
            throw new RemediationException('unsupported_rule', 'No AI explanation exists for this finding.', 422);
        }
        // An explanation sends no image, so only the site's AI readiness applies (not the image permission).
        $refusal = $this->access->prepareRefusal($siteIdentifier, '', false);
        if ($refusal !== '') {
            // Readiness and budget states answer 422 like the editor's explain path; a missing right is 403.
            throw new RemediationException($refusal, 'AI is not available for this finding.', $refusal === 'permission_denied' ? 403 : 422);
        }
        $located = $this->editorAi->locateStoredFinding(
            (string)$issue['source_table'],
            (int)$issue['source_uid'],
            (string)$issue['source_field'],
            $ruleId,
            (string)($issue['context_path'] ?? ''),
            (string)($issue['context_snippet'] ?? ''),
        );
        if ($located === null) {
            throw new RemediationException('stale', 'The finding no longer matches the stored content. Scan the page again.', 409);
        }

        return $this->editorAi->explain([
            'table' => $located['record']->table,
            'uid' => $located['record']->uid,
            'field' => $located['record']->field,
            'html' => $located['html'],
            'ruleId' => $ruleId,
            'contextPath' => $located['target']->contextPath,
            'occurrence' => $located['target']->occurrence,
        ]);
    }

    /**
     * What prepare() would do for each finding, without creating anything and without any AI request: the same checks
     * in the same order (read access, open status, an item already holding the finding, licence, a safe target in the
     * record as it is stored now, AI readiness and rights, edit access, a readable image). Ask AQG's repair answer uses
     * it to show what can be prepared before the editor starts anything.
     *
     * Outcomes: 'ready' (prepare() would queue it; aiKind and targetKind tell the cost class), 'already_queued' (an
     * open item holds it: itemUid/itemStatus/itemReason only when this person may see that item), 'saved' (a change
     * of this person's visible item was saved and waits for a check) or 'skipped' with prepare()'s reason.
     *
     * @param list<int> $findingUids
     * @return list<array{findingUid:int,outcome:string,reason:string,itemUid:int,itemStatus:string,itemReason:string,aiKind:string,targetKind:string}>
     */
    public function preflight(array $findingUids, bool $bulk): array
    {
        $this->access->assertLoggedIn();
        $results = [];
        foreach (array_values(array_unique(array_filter(array_map('intval', $findingUids), static fn (int $uid): bool => $uid > 0))) as $findingUid) {
            $evaluation = $this->evaluate($findingUid, $bulk);
            $item = $evaluation['item'] ?? null;
            $visible = is_array($item) && $this->access->canSee($item);
            $result = [
                'findingUid' => $findingUid,
                'outcome' => $evaluation['outcome'],
                'reason' => $evaluation['reason'],
                'itemUid' => $visible ? (int)$item['uid'] : 0,
                'itemStatus' => $visible ? (string)$item['status'] : '',
                'itemReason' => $visible ? (string)$item['status_reason'] : '',
                'aiKind' => isset($evaluation['target']) ? $evaluation['target']->aiKind : '',
                'targetKind' => isset($evaluation['target']) ? $evaluation['target']->kind : '',
            ];
            if ($evaluation['outcome'] !== 'already_queued') {
                // A change this person saved for the finding (not yet verified): shown instead of offering it again blind.
                foreach ($this->items->findActiveForFindings([$findingUid], [RemediationStatus::SAVED, RemediationStatus::NOT_VERIFIED]) as $saved) {
                    if ((int)$saved['workspace_id'] === $this->access->currentWorkspace() && $this->access->canSee($saved)) {
                        $result['savedItemUid'] = (int)$saved['uid'];
                        $result['savedItemStatus'] = (string)$saved['status'];
                        break;
                    }
                }
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * The checks of prepareOne() without the write: 'ready' with the target, or the outcome and reason it stops at.
     *
     * @return array{outcome:string,reason:string,issue?:array<string,mixed>,item?:array<string,mixed>,target?:RemediationTarget,descriptor?:string}
     */
    private function evaluate(int $findingUid, bool $bulk): array
    {
        $issue = $this->issues->findByUid($findingUid);
        if (!is_array($issue) || !$this->access->canReadPage((int)$issue['page_uid'])) {
            return ['outcome' => 'skipped', 'reason' => 'not_found'];
        }
        if ((int)$issue['status'] !== IssueStatus::Open->value) {
            return ['outcome' => 'skipped', 'reason' => 'finding_closed'];
        }
        $existing = $this->blockingItem($findingUid);
        if ($existing !== null) {
            return ['outcome' => 'already_queued', 'reason' => '', 'item' => $existing];
        }
        $siteIdentifier = (string)$issue['site_identifier'];
        if (!$this->access->hasLicence($siteIdentifier)) {
            return ['outcome' => 'skipped', 'reason' => 'no_licence'];
        }
        $target = $this->targetResolver->resolve($issue);
        if (!$target->isAvailable()) {
            return ['outcome' => 'skipped', 'reason' => $target->unavailableReason];
        }
        $refusal = $this->access->prepareRefusal($siteIdentifier, $target->aiKind, $bulk);
        if ($refusal !== '') {
            return ['outcome' => 'skipped', 'reason' => $refusal, 'target' => $target];
        }
        $targetDescriptor = RemediationItemData::encodeDescriptor($target->descriptor + ['aiKind' => $target->aiKind]);
        // An item holds the finding for everyone (blockingItem()), so only someone who may change the record field it
        // fixes creates one; read access is enough for "Explain with AI", never for an actionable item.
        if (!$this->access->canEditTarget([
            'target_kind' => $target->kind,
            'target_table' => $target->table,
            'target_uid' => $target->uid,
            'target_field' => $target->field,
            'target_descriptor' => $targetDescriptor,
        ])) {
            return ['outcome' => 'skipped', 'reason' => 'no_edit_access'];
        }

        // An image AQG cannot read (missing file, external URL, no read access) would only produce a Failed item: it is
        // refused here, before an item holds the finding and before any provider request, with the exact reason.
        $imageReason = $this->unreadableImageReason($issue, $target);
        if ($imageReason !== '') {
            return ['outcome' => 'skipped', 'reason' => $imageReason, 'target' => $target];
        }

        return ['outcome' => 'ready', 'reason' => '', 'issue' => $issue, 'target' => $target, 'descriptor' => $targetDescriptor];
    }

    /** @return array{itemUid:int,outcome:string,reason:string} */
    private function prepareOne(int $findingUid, string $origin, string $batchId, bool $bulk): array
    {
        // NEW-09: "is the finding already held?" and "create the item" happen under one mutex per finding and workspace,
        // shared by all PHP processes. Two people preparing the same finding at the same instant created two items (24
        // of 25 barrier trials); now the second one waits briefly and is told the item is already queued.
        $mutex = $this->prepareMutex ?? new DatabaseMutex($this->connectionPool);
        $mutexKey = 'remediation-prepare:' . $findingUid . ':' . $this->access->currentWorkspace();
        $token = $mutex->acquire($mutexKey, self::PREPARE_MUTEX_TTL, self::PREPARE_MUTEX_WAIT_MS);
        if ($token === null) {
            $evaluation = $this->evaluate($findingUid, $bulk);
            if ($evaluation['outcome'] === 'already_queued') {
                return ['itemUid' => $this->access->canSee($evaluation['item']) ? (int)$evaluation['item']['uid'] : 0, 'outcome' => 'already_queued', 'reason' => ''];
            }

            return ['itemUid' => 0, 'outcome' => 'skipped', 'reason' => 'in_progress'];
        }
        try {
            return $this->prepareOneExclusively($findingUid, $origin, $batchId, $bulk);
        } finally {
            $mutex->release($mutexKey, $token);
        }
    }

    /** @return array{itemUid:int,outcome:string,reason:string} */
    private function prepareOneExclusively(int $findingUid, string $origin, string $batchId, bool $bulk): array
    {
        $evaluation = $this->evaluate($findingUid, $bulk);
        if ($evaluation['outcome'] === 'already_queued') {
            // The uid only for someone who may open the item: the queue never reveals another person's request.
            return ['itemUid' => $this->access->canSee($evaluation['item']) ? (int)$evaluation['item']['uid'] : 0, 'outcome' => 'already_queued', 'reason' => ''];
        }
        if ($evaluation['outcome'] !== 'ready') {
            return ['itemUid' => 0, 'outcome' => 'skipped', 'reason' => $evaluation['reason']];
        }
        $issue = $evaluation['issue'];
        $target = $evaluation['target'];
        $targetDescriptor = $evaluation['descriptor'];
        $siteIdentifier = (string)$issue['site_identifier'];

        $uid = $this->items->insert([
            'site_identifier' => $siteIdentifier,
            'page_uid' => (int)$issue['page_uid'],
            'language_uid' => (int)($issue['source_lang_uid'] ?? 0),
            'workspace_id' => $this->access->currentWorkspace(),
            'finding_uid' => $findingUid,
            'finding_fingerprint' => (string)$issue['fingerprint'],
            'rule_id' => (string)$issue['rule_id'],
            'severity' => (int)$issue['severity'],
            'target_kind' => $target->kind,
            'target_table' => $target->table,
            'target_uid' => $target->uid,
            'target_field' => $target->field,
            'target_descriptor' => $targetDescriptor,
            'base_hash' => $target->baseHash,
            'original_value' => $target->currentValue,
            'proposed_value' => '',
            'edited_value' => '',
            'owner' => $this->ownerFor($issue, true),
            'status' => RemediationStatus::PREPARING,
            'origin' => $origin,
            'batch_id' => $batchId,
            'created_by' => $this->access->userUid(),
        ]);
        $this->event($uid, 'prepared', '', RemediationStatus::PREPARING, $origin);

        return ['itemUid' => $uid, 'outcome' => 'queued', 'reason' => ''];
    }

    /**
     * Asks the site's AI provider for the item's suggestion (once per call; a concurrent second call is refused, so a
     * double click never pays twice). Rate limits leave the item queued with the retry time.
     *
     * @return array<string,mixed> the updated item row plus 'retryAfter' when rate limited
     */
    public function generate(int $itemUid, int $rowVersion): array
    {
        $item = $this->visibleItem($itemUid);
        $status = (string)$item['status'];
        if (in_array($status, [RemediationStatus::NO_SUGGESTION, RemediationStatus::CONFLICT, RemediationStatus::FAILED, RemediationStatus::PENDING_REVIEW], true)) {
            // Regenerate: back to Preparing first, from exactly the state the person saw.
            if ($status === RemediationStatus::FAILED && in_array((string)$item['status_reason'], self::APPLY_FAILURES, true)) {
                // A failed write is retried as a write (approve), never by replacing the reviewed suggestion.
                throw new RemediationException('not_retryable', 'Retry applying this item instead.', 409);
            }
            $this->transitionOrFail($item, $rowVersion, [$status], RemediationStatus::PREPARING, ['status_reason' => ''], 'regenerate');
            $item = $this->mustFind($itemUid);
            $rowVersion = (int)$item['row_version'];
        } elseif ($status !== RemediationStatus::PREPARING) {
            throw new RemediationException('invalid_state', 'This item has no suggestion to prepare.', 409);
        }
        if ((int)$item['created_by'] !== $this->access->userUid() && !$this->access->mayUseBulk()) {
            throw new RemediationException('permission_denied', 'Only the queue permission may prepare this item.', 403);
        }

        // The claim: exactly one request generates at a time. It holds while the provider is asked, also against a
        // client that has read the claimed row version since, and is released on every way out that leaves the item
        // Preparing (rate limit, refusal, error), so a retry is possible right away.
        // The claim is identified by its attempt number: every claim counts one up, so a later claim (after a reject,
        // reopen and regenerate meanwhile) is never mistaken for this one, while an owner change keeps it.
        $claim = (int)$item['attempts'] + 1;
        if (!$this->items->claimGeneration($itemUid, $rowVersion, time() - self::GENERATION_LEASE, ['attempts' => $claim])) {
            throw new RemediationException('in_progress', 'This suggestion is being prepared already.', 409);
        }
        try {
            return $this->generateClaimed($itemUid, $claim);
        } finally {
            $this->releaseGeneration($itemUid, $claim);
        }
    }

    /**
     * Whether a generation request for this item is running now (claimed and not yet ended or run out).
     *
     * @param array<string,mixed> $item
     */
    public static function isGenerating(array $item, ?int $now = null): bool
    {
        return (string)($item['status'] ?? '') === RemediationStatus::PREPARING
            && (string)($item['status_reason'] ?? '') === self::GENERATING
            && (int)($item['tstamp'] ?? 0) >= ($now ?? time()) - self::GENERATION_LEASE;
    }

    /** Ends this claim if it still holds the item (Preparing); anything else already ended it. */
    private function releaseGeneration(int $itemUid, int $claim): void
    {
        $this->settle($itemUid, $claim, RemediationStatus::PREPARING, ['status_reason' => ''], '');
    }

    /**
     * Writes a generation outcome only while this claim still holds the item: a rejection, a closed finding or a newer
     * claim in the meantime wins, and the outcome is dropped instead of landing on a reopened item.
     *
     * @param array<string,mixed> $fields
     */
    private function settle(int $itemUid, int $claim, string $to, array $fields, string $action, string $valueHash = ''): void
    {
        $fresh = $this->items->findByUid($itemUid);
        if (!is_array($fresh) || (string)$fresh['status'] !== RemediationStatus::PREPARING
            || (string)$fresh['status_reason'] !== self::GENERATING || (int)$fresh['attempts'] !== $claim) {
            return;
        }
        if ($this->items->transition($itemUid, (int)$fresh['row_version'], [RemediationStatus::PREPARING], $to, $fields) && $action !== '') {
            $this->event($itemUid, $action, RemediationStatus::PREPARING, $to, '', $valueHash, (int)($fields['proposal_version'] ?? $fresh['proposal_version']));
        }
    }

    /**
     * The generation of a claimed item: one provider request at most.
     *
     * @return array<string,mixed>
     */
    private function generateClaimed(int $itemUid, int $claim): array
    {
        $item = $this->mustFind($itemUid);
        // Two preparations that raced past the duplicate check: the older item keeps the finding, this one asks no
        // provider (no second charge) and steps aside.
        $older = $this->blockingItem((int)$item['finding_uid'], $itemUid);
        if ($older !== null && (int)$older['uid'] < $itemUid) {
            $this->settle($itemUid, $claim, RemediationStatus::NOT_APPLICABLE, ['status_reason' => 'duplicate'], 'not_applicable');
            return $this->mustFind($itemUid);
        }

        $issue = $this->issues->findByUid((int)$item['finding_uid']);
        if (!is_array($issue) || (int)$issue['status'] !== IssueStatus::Open->value) {
            $this->settle($itemUid, $claim, RemediationStatus::NOT_APPLICABLE, ['status_reason' => 'finding_closed'], 'not_applicable');
            return $this->mustFind($itemUid);
        }
        $target = $this->targetResolver->resolve($issue);
        if (!$target->isAvailable() || $target->kind !== (string)$item['target_kind'] || $target->uid !== (int)$item['target_uid']) {
            $this->settle($itemUid, $claim, RemediationStatus::NOT_APPLICABLE, ['status_reason' => $target->unavailableReason !== '' ? $target->unavailableReason : 'target_changed'], 'not_applicable');
            return $this->mustFind($itemUid);
        }
        $refusal = $this->access->prepareRefusal((string)$item['site_identifier'], $target->aiKind, false);
        if ($refusal !== '') {
            throw new RemediationException($refusal, 'AI is not available for this item.', $refusal === 'permission_denied' || $refusal === 'no_licence' ? 403 : 422);
        }

        try {
            $result = $target->kind === RemediationTargetKind::IMAGE_REFERENCE_ALT
                ? $this->referenceAi->suggest([
                    'parentTable' => (string)$target->descriptor['parentTable'],
                    'parentUid' => (int)$target->descriptor['parentUid'],
                    'fieldName' => (string)$target->descriptor['fieldName'],
                    'referenceUid' => (int)$target->descriptor['referenceUid'],
                    'fileUid' => (int)$target->descriptor['fileUid'],
                    'currentAlt' => $target->currentValue,
                    'mode' => 'suggest',
                ])
                : $this->richTextSuggestion($issue, $target);
        } catch (AiRateLimitException $exception) {
            $this->releaseGeneration($itemUid, $claim);
            return $this->mustFind($itemUid) + ['retryAfter' => max(1, $exception->retryAfter)];
        } catch (EditorAiException $exception) {
            $reason = $exception->safeCode === 'image_unavailable' && $target->kind === RemediationTargetKind::RICH_TEXT
                // The file changed after preparation: record why, not only that it failed (rich text / Plain HTML;
                // file references keep the general image_unavailable reason).
                ? ($this->unreadableImageReason($issue, $target) ?: EditorAiImageResolver::UNREADABLE)
                : $this->readinessReason(mb_substr($exception->safeCode, 0, 64));
            $this->settle($itemUid, $claim, $exception->safeCode === 'stale' ? RemediationStatus::NOT_APPLICABLE : RemediationStatus::FAILED, ['status_reason' => $exception->safeCode === 'stale' ? 'target_changed' : $reason], 'generation_failed');
            return $this->mustFind($itemUid);
        } catch (AiProviderException $exception) {
            $this->settle($itemUid, $claim, RemediationStatus::FAILED, ['status_reason' => $this->providerReason((int)$exception->getCode())], 'generation_failed');
            return $this->mustFind($itemUid);
        } catch (AiConfigurationException $exception) {
            // A budget or readiness refusal (also from the locked budget check right before the request) is recorded
            // as such, not as a missing configuration.
            $this->settle($itemUid, $claim, RemediationStatus::FAILED, ['status_reason' => $this->readinessReason(EditorAiAccessService::stateForConfigurationCode((int)$exception->getCode()))], 'generation_failed');
            return $this->mustFind($itemUid);
        }

        $provenance = [
            'provider' => mb_substr((string)($result['provider'] ?? ''), 0, 32),
            'model' => mb_substr((string)($result['model'] ?? ''), 0, 100),
            'prompt_version' => mb_substr((string)($result['promptVersion'] ?? ''), 0, 32),
            'input_tokens' => max(0, (int)($result['usage']['inputTokens'] ?? 0)),
            'output_tokens' => max(0, (int)($result['usage']['outputTokens'] ?? 0)),
            // The suggestion is for exactly this value: the base the apply step will compare with.
            'base_hash' => $target->baseHash,
            'original_value' => $target->currentValue,
            'target_descriptor' => RemediationItemData::encodeDescriptor($target->descriptor + ['aiKind' => $target->aiKind, 'sent' => array_values(array_map('strval', (array)($result['sent'] ?? [])))]),
            'explanation' => mb_substr((string)($result['reason'] ?? ''), 0, 500),
            'edited_value' => '',
        ];
        $value = (string)($result['value'] ?? '');
        if (($result['status'] ?? '') === 'suggestion' && $value !== '') {
            $this->settle($itemUid, $claim, RemediationStatus::PENDING_REVIEW, $provenance + [
                'proposed_value' => $value,
                'proposal_version' => (int)$item['proposal_version'] + 1,
                'status_reason' => ($result['warnings'] ?? []) !== [] ? 'still_reported_warning' : '',
            ], 'suggested', RemediationTarget::hashValue($value));
        } else {
            $this->settle($itemUid, $claim, RemediationStatus::NO_SUGGESTION, $provenance + [
                'proposed_value' => '',
                'status_reason' => mb_substr((string)($result['status'] ?? 'needs_review'), 0, 64),
            ], 'no_suggestion');
        }

        return $this->mustFind($itemUid);
    }

    /**
     * For an alt-text finding in rich text or Plain HTML: why its image cannot be sent (EditorAiImageResolver reason),
     * '' when it can or when the finding has no image. Called only after the access checks of the caller, so the
     * answer never tells a person without access whether a file exists.
     *
     * @param array<string,mixed> $issue
     */
    private function unreadableImageReason(array $issue, RemediationTarget $target): string
    {
        if ($target->kind !== RemediationTargetKind::RICH_TEXT || $target->aiKind !== EditorAiRuleCatalog::KIND_IMAGE_ALT) {
            return '';
        }
        try {
            $located = $this->editorAi->locateStoredFinding(
                $target->table,
                $target->uid,
                $target->field,
                (string)$issue['rule_id'],
                (string)($issue['context_path'] ?? ''),
                (string)($issue['context_snippet'] ?? ''),
            );
        } catch (EditorAiException) {
            return '';
        }
        // A finding that no longer matches is reported by the generation step (target_changed), not as an image problem.
        return $located === null ? '' : $this->editorAi->imageAvailability($located['record'], $located['target']);
    }

    /**
     * @param array<string,mixed> $issue
     * @return array<string,mixed>
     */
    private function richTextSuggestion(array $issue, RemediationTarget $target): array
    {
        $located = $this->editorAi->locateStoredFinding(
            $target->table,
            $target->uid,
            $target->field,
            (string)$issue['rule_id'],
            (string)($issue['context_path'] ?? ''),
            (string)($issue['context_snippet'] ?? ''),
        );
        if ($located === null) {
            throw new EditorAiException('stale', 'The finding no longer matches the stored content.', 409);
        }

        return $this->editorAi->fix([
            'table' => $target->table,
            'uid' => $target->uid,
            'field' => $target->field,
            'html' => $located['html'],
            'ruleId' => (string)$issue['rule_id'],
            'contextPath' => (string)$target->descriptor['contextPath'],
            'occurrence' => (int)$target->descriptor['occurrence'],
        ]);
    }

    /**
     * Records that this person displayed the complete proposal of this version (the review drawer or bulk dialog).
     * Bulk approval requires it per item and version.
     */
    public function markViewed(int $itemUid, int $proposalVersion): array
    {
        $item = $this->visibleItem($itemUid);
        if ($proposalVersion !== (int)$item['proposal_version'] || $proposalVersion <= 0) {
            throw new RemediationException('stale_view', 'A newer suggestion exists. Review it again.', 409);
        }
        if (!$this->items->hasSeen($itemUid, $this->access->userUid(), $proposalVersion)) {
            $this->items->addEvent([
                'item_uid' => $itemUid,
                'be_user' => $this->access->userUid(),
                'action' => 'viewed',
                'from_status' => (string)$item['status'],
                'to_status' => (string)$item['status'],
                'proposal_version' => $proposalVersion,
                'value_hash' => RemediationTarget::hashValue(RemediationItemData::finalValue($item)),
            ]);
        }

        return $item;
    }

    /** A reviewer's own value for a pending suggestion (validated like a typed value; the person has seen it). */
    public function editValue(int $itemUid, int $rowVersion, string $value): array
    {
        $item = $this->visibleItem($itemUid);
        // Changing the text to approve is part of applying it: the same rights, never only visibility.
        $refusal = $this->access->applyRefusal($item, false);
        if ($refusal !== '') {
            throw new RemediationException($refusal, 'You may not change this suggestion.', 403);
        }
        if ((string)$item['status'] !== RemediationStatus::PENDING_REVIEW) {
            throw new RemediationException('invalid_state', 'Only a pending suggestion can be edited.', 409);
        }
        $value = $this->validatedValue($item, $value);
        $version = (int)$item['proposal_version'] + 1;
        $this->transitionOrFail($item, $rowVersion, [RemediationStatus::PENDING_REVIEW], RemediationStatus::PENDING_REVIEW, [
            'edited_value' => $value === (string)$item['proposed_value'] ? '' : $value,
            'proposal_version' => $version,
        ], 'edited', RemediationTarget::hashValue($value));
        $this->items->addEvent([
            'item_uid' => $itemUid,
            'be_user' => $this->access->userUid(),
            'action' => 'viewed',
            'from_status' => RemediationStatus::PENDING_REVIEW,
            'to_status' => RemediationStatus::PENDING_REVIEW,
            'proposal_version' => $version,
            'value_hash' => RemediationTarget::hashValue($value),
        ]);

        return $this->mustFind($itemUid);
    }

    public function reject(int $itemUid, int $rowVersion, string $reason): array
    {
        $item = $this->visibleItem($itemUid);
        if (!in_array($reason, self::REJECT_REASONS, true)) {
            $reason = 'none';
        }
        // Rejecting needs the queue permission or one's own request (Atlas: reject and reassign need read access and
        // the queue permission); it writes nothing in TYPO3.
        if ((int)$item['created_by'] !== $this->access->userUid() && !$this->access->mayUseBulk()) {
            throw new RemediationException('permission_denied', 'You may not decide on this item.', 403);
        }
        $this->transitionOrFail($item, $rowVersion, [
            RemediationStatus::PREPARING, RemediationStatus::PENDING_REVIEW, RemediationStatus::NO_SUGGESTION,
            RemediationStatus::APPROVED, RemediationStatus::IN_EDITOR, RemediationStatus::CONFLICT, RemediationStatus::FAILED,
        ], RemediationStatus::REJECTED, [
            'status_reason' => 'reject_' . $reason,
            'reviewed_by' => $this->access->userUid(),
            'reviewed_at' => time(),
        ], 'rejected');

        return $this->mustFind($itemUid);
    }

    public function reopen(int $itemUid, int $rowVersion): array
    {
        $item = $this->visibleItem($itemUid);
        if ((string)$item['status'] !== RemediationStatus::REJECTED) {
            throw new RemediationException('invalid_state', 'Only a rejected item can be reopened.', 409);
        }
        $issue = $this->issues->findByUid((int)$item['finding_uid']);
        if (!is_array($issue) || (int)$issue['status'] !== IssueStatus::Open->value) {
            throw new RemediationException('finding_closed', 'The finding is no longer open.', 409);
        }
        // A reopened item holds the finding again, so it needs the same right as preparing one (prepareOne()).
        if (!$this->access->canEditTarget($item)) {
            throw new RemediationException('no_edit_access', 'You cannot edit the record this item changes.', 403);
        }
        // NEW-09 (review): reopening makes the item hold the finding again, so "is the finding held?" and the transition
        // run under the same per-finding mutex as prepareOne(); a concurrent prepare cannot create a second holder.
        $mutex = $this->prepareMutex ?? new DatabaseMutex($this->connectionPool);
        $mutexKey = 'remediation-prepare:' . (int)$item['finding_uid'] . ':' . (int)$item['workspace_id'];
        $token = $mutex->acquire($mutexKey, self::PREPARE_MUTEX_TTL, self::PREPARE_MUTEX_WAIT_MS);
        if ($token === null) {
            throw new RemediationException('in_progress', 'This finding is being prepared right now. Try again in a moment.', 409);
        }
        try {
            $other = $this->blockingItem((int)$item['finding_uid'], $itemUid);
            if ($other !== null) {
                throw new RemediationException('already_queued', 'Another item holds this finding.', 409, ['itemUid' => (int)$other['uid']]);
            }
            $to = (string)$item['proposed_value'] !== '' ? RemediationStatus::PENDING_REVIEW : RemediationStatus::NO_SUGGESTION;
            // A new version: a view recorded before the rejection is no approval evidence for the reopened item.
            $this->transitionOrFail($item, $rowVersion, [RemediationStatus::REJECTED], $to, [
                'status_reason' => '',
                'reviewed_by' => 0,
                'reviewed_at' => 0,
                'proposal_version' => (int)$item['proposal_version'] + 1,
            ], 'reopened');
        } finally {
            $mutex->release($mutexKey, $token);
        }

        return $this->mustFind($itemUid);
    }

    /**
     * Approve and apply an image item through AQG's allow-listed write, or approve a rich-text item for the editor.
     * $bulk marks a bulk run: it needs the queue permission and a recorded view of this exact proposal version.
     *
     * @return array<string,mixed> the updated item
     */
    public function approve(int $itemUid, int $rowVersion, bool $bulk, string $batchId = ''): array
    {
        $item = $this->visibleItem($itemUid);
        $refusal = $this->access->applyRefusal($item, $bulk);
        if ($refusal !== '') {
            throw new RemediationException($refusal, 'You may not apply this item.', $refusal === 'no_licence' ? 403 : 403);
        }
        if ((int)$item['workspace_id'] !== $this->access->currentWorkspace()) {
            throw new RemediationException('workspace_mismatch', 'Switch to the workspace this item was prepared in.', 409);
        }
        $status = (string)$item['status'];
        $retry = $status === RemediationStatus::FAILED && in_array((string)$item['status_reason'], self::RETRYABLE_APPLY_FAILURES, true);
        if ($status !== RemediationStatus::PENDING_REVIEW && !$retry) {
            throw new RemediationException(RemediationStatus::isDone($status) || in_array($status, RemediationStatus::SAVED_STATES, true) ? 'already_done' : 'invalid_state', 'This item cannot be approved now.', 409, ['status' => $status]);
        }
        if ((int)$item['proposal_version'] <= 0 || RemediationItemData::finalValue($item) === '') {
            throw new RemediationException('invalid_state', 'There is no suggestion to approve.', 409);
        }
        // Server-authoritative review evidence: nobody approves a change they have not displayed in full.
        if (!$this->items->hasSeen($itemUid, $this->access->userUid(), (int)$item['proposal_version'])) {
            throw new RemediationException('not_reviewed', 'Open the complete change before approving it.', 409);
        }
        $issue = $this->issues->findByUid((int)$item['finding_uid']);
        if (!is_array($issue) || (int)$issue['status'] !== IssueStatus::Open->value) {
            $this->transitionOrFail($item, $rowVersion, [$status], RemediationStatus::NOT_APPLICABLE, ['status_reason' => 'finding_closed'], 'not_applicable');
            return $this->mustFind($itemUid);
        }
        $value = $this->validatedValue($item, RemediationItemData::finalValue($item));

        if ((string)$item['target_kind'] === RemediationTargetKind::IMAGE_REFERENCE_ALT) {
            if ((int)$item['workspace_id'] > 0) {
                // AQG's base check reads the live reference; in a workspace TYPO3 writes a version row, so a check of
                // the live value proves nothing about the version. The change is applied in the record form there.
                throw new RemediationException('workspace_apply_in_record', 'In a workspace, apply this text in the record.', 409);
            }
            return $this->applyImage($item, $rowVersion, $status, $value, $batchId);
        }
        if ((string)$item['target_kind'] === RemediationTargetKind::RICH_TEXT) {
            if ($bulk) {
                throw new RemediationException('editor_only', 'Rich text is applied one by one in the editor.', 422);
            }
            return $this->approveForEditor($item, $rowVersion, $value);
        }

        throw new RemediationException('no_safe_action', 'This item has no apply path.', 422);
    }

    /** @param array<string,mixed> $item */
    private function applyImage(array $item, int $rowVersion, string $fromStatus, string $value, string $batchId): array
    {
        $itemUid = (int)$item['uid'];
        // DataHandler stamps the reference with the request's EXEC_TIME; the check of the stored reference compares
        // its tstamp with this time, so both must come from the same clock reading.
        $now = (int)($GLOBALS['EXEC_TIME'] ?? time());
        // The claim: from here on no second request may write this item.
        $this->transitionOrFail($item, $rowVersion, [$fromStatus], RemediationStatus::APPLYING, [
            'reviewed_by' => $this->access->userUid(),
            'reviewed_at' => $now,
            'applied_by' => $this->access->userUid(),
            'applied_at' => $now,
            'attempts' => (int)$item['attempts'] + 1,
            'status_reason' => '',
        ], 'approved');
        $item = $this->mustFind($itemUid);

        $connection = $this->connectionPool->getConnectionForTable(Tables::SYS_FILE_REFERENCE);
        try {
            $outcome = $connection->transactional(function (Connection $connection) use ($item, $value): string {
                // Lock the reference, so a concurrent TYPO3 save or a second apply waits until this one rechecked
                // the base and wrote (the same pattern as the Filelist metadata write).
                $lock = str_contains(strtolower($connection->getDatabasePlatform()::class), 'sqlite') ? '' : ' FOR UPDATE';
                $current = $connection->executeQuery(
                    'SELECT alternative, tx_a11y_is_decorative, uid_local FROM ' . Tables::SYS_FILE_REFERENCE . ' WHERE uid = ? AND deleted = 0' . $lock,
                    [(int)$item['target_uid']],
                    [Connection::PARAM_INT],
                )->fetchAssociative();
                if (!is_array($current)) {
                    return 'target_missing';
                }
                // The write goes through the finding (ImageRemediationService resolves it again): it must still name
                // exactly the reviewed image use and file, and still be the same finding. Finding rows can be reused
                // for another reference of the same field by a later scan.
                $issue = $this->issues->findByUid((int)$item['finding_uid']);
                $context = $this->imageResolver->resolve((int)$item['finding_uid']);
                $descriptor = RemediationItemData::descriptor($item);
                if (!is_array($issue) || (string)$issue['fingerprint'] !== (string)$item['finding_fingerprint']
                    || $context->fileReferenceUid !== (int)$item['target_uid']
                    || $context->fileUid !== (int)($descriptor['fileUid'] ?? 0)
                    || (int)$current['uid_local'] !== $context->fileUid) {
                    return 'conflict';
                }
                $currentHash = RemediationTargetResolver::imageBaseHash((string)$current['alternative'], (string)(int)$current['tx_a11y_is_decorative'], (string)(int)$current['uid_local']);
                if ($currentHash !== (string)$item['base_hash']) {
                    // Somebody stored the very value already (a retry after an interrupted write): nothing to repeat.
                    $alreadyWritten = RemediationTargetResolver::imageBaseHash($value, '0', (string)(int)$current['uid_local']);
                    return $currentHash === $alreadyWritten ? 'already_saved' : 'conflict';
                }
                $this->imageRemediation->applyAlt((int)$item['finding_uid'], $value, $this->versionTokens->create($context));

                return 'saved';
            });
        } catch (StaleImageFindingException) {
            $outcome = 'conflict';
        } catch (InvalidImageVersionTokenException) {
            $outcome = 'conflict';
        } catch (ImageRemediationPermissionException $exception) {
            $outcome = $exception->getMessage() === 'workspace_mismatch' ? 'workspace_mismatch' : 'permission_denied';
        } catch (ImageRemediationValidationException) {
            $outcome = 'invalid_value';
        } catch (\Throwable) {
            $outcome = 'write_failed';
        }

        $detail = $batchId !== '' ? 'batch:' . $batchId : '';
        if ($outcome === 'saved' || $outcome === 'already_saved') {
            $this->move($item, RemediationStatus::SAVED, ['saved_at' => time(), 'status_reason' => $outcome === 'already_saved' ? 'already_saved' : ''], 'saved', RemediationTarget::hashValue($value), $detail);
            return $this->refresh($itemUid);
        }
        if ($outcome === 'conflict' || $outcome === 'target_missing') {
            $this->move($item, RemediationStatus::CONFLICT, ['status_reason' => $outcome === 'conflict' ? 'target_changed' : 'target_missing'], 'conflict', '', $detail);
        } else {
            // Nothing was written: the transaction rolled back or the write was refused before it started.
            $this->move($item, RemediationStatus::FAILED, ['status_reason' => $outcome], 'apply_failed', '', $detail);
        }

        return $this->mustFind($itemUid);
    }

    /**
     * Rich text: the approval stages the change for the person's editor. Nothing is written here; the item becomes
     * "Saved" only once the stored record contains exactly the approved element (refresh()).
     *
     * @param array<string,mixed> $item
     */
    private function approveForEditor(array $item, int $rowVersion, string $value): array
    {
        $issue = $this->issues->findByUid((int)$item['finding_uid']) ?? [];
        $target = $this->targetResolver->resolve($issue);
        if (!$target->isAvailable() || $target->baseHash !== (string)$item['base_hash']) {
            $this->transitionOrFail($item, $rowVersion, [RemediationStatus::PENDING_REVIEW], RemediationStatus::CONFLICT, ['status_reason' => 'target_changed'], 'conflict');
            return $this->mustFind((int)$item['uid']);
        }
        $located = $this->editorAi->locateStoredFinding($target->table, $target->uid, $target->field, (string)$issue['rule_id'], (string)($issue['context_path'] ?? ''), (string)($issue['context_snippet'] ?? ''));
        $expected = $located !== null ? $this->editorAi->expectedSignature($located['html'], $located['target'], $value) : null;
        if ($expected === null) {
            $this->transitionOrFail($item, $rowVersion, [RemediationStatus::PENDING_REVIEW], RemediationStatus::CONFLICT, ['status_reason' => 'target_changed'], 'conflict');
            return $this->mustFind((int)$item['uid']);
        }
        $descriptor = RemediationItemData::descriptor($item);
        // The saved element is proven by its attributes and text, not by a position: the editor's save normalizes
        // the HTML (CKEditor drops empty elements), which shifts positions counted in the stored HTML.
        $descriptor['expected'] = ['occurrence' => -1, 'total' => -1] + $expected;
        $this->transitionOrFail($item, $rowVersion, [RemediationStatus::PENDING_REVIEW], RemediationStatus::APPROVED, [
            'reviewed_by' => $this->access->userUid(),
            'reviewed_at' => time(),
            'target_descriptor' => RemediationItemData::encodeDescriptor($descriptor),
            // The editor receives exactly the validated value the expected element was computed from.
            'edited_value' => $value === (string)$item['proposed_value'] ? '' : $value,
        ], 'approved', RemediationTarget::hashValue($value));

        return $this->mustFind((int)$item['uid']);
    }

    /**
     * The staged change for the editor that opens this item's record: only for the same record and field, only while
     * the stored element is still the one the suggestion was made for. Marks the item "In editor, unsaved".
     *
     * @return array<string,mixed>
     */
    public function staged(int $itemUid, string $table, int $uid, string $field): array
    {
        $item = $this->visibleItem($itemUid);
        if ((string)$item['target_kind'] !== RemediationTargetKind::RICH_TEXT
            || (string)$item['target_table'] !== $table || (int)$item['target_uid'] !== $uid || (string)$item['target_field'] !== $field) {
            throw new RemediationException('not_this_field', 'This change belongs to another field.', 409);
        }
        $refusal = $this->access->applyRefusal($item, false);
        if ($refusal !== '') {
            throw new RemediationException($refusal, 'You may not apply this item.', 403);
        }
        if (!in_array((string)$item['status'], [RemediationStatus::APPROVED, RemediationStatus::IN_EDITOR], true)) {
            throw new RemediationException('invalid_state', 'This change is not approved for the editor.', 409, ['status' => (string)$item['status']]);
        }
        $issue = $this->issues->findByUid((int)$item['finding_uid']) ?? [];
        $target = $this->targetResolver->resolve($issue);
        if (!$target->isAvailable() || $target->baseHash !== (string)$item['base_hash']) {
            // The editor reloads with the same link after TYPO3 Save: a stored record that holds exactly the approved
            // element is the save, not a conflict.
            $checked = $this->refresh($itemUid);
            if (in_array((string)$checked['status'], RemediationStatus::SAVED_STATES, true)) {
                // Nothing to stage: the editor shows the saved text and the queue already knows.
                return ['itemUid' => $itemUid, 'saved' => true, 'itemStatus' => (string)$checked['status']];
            }
            $this->move($checked, RemediationStatus::CONFLICT, ['status_reason' => 'target_changed'], 'conflict');
            throw new RemediationException('conflict', 'The text changed after this suggestion was made.', 409);
        }
        $now = time();
        if ((string)$item['status'] === RemediationStatus::APPROVED) {
            $this->move($item, RemediationStatus::IN_EDITOR, ['staged_at' => $now, 'applied_by' => $this->access->userUid()], 'staged');
        } else {
            $this->items->transition((int)$item['uid'], (int)$item['row_version'], [RemediationStatus::IN_EDITOR], RemediationStatus::IN_EDITOR, ['staged_at' => $now]);
        }
        $item = $this->mustFind($itemUid);
        $descriptor = RemediationItemData::descriptor($item);

        return [
            'itemUid' => $itemUid,
            'rowVersion' => (int)$item['row_version'],
            'ruleId' => (string)$item['rule_id'],
            'target' => [
                'tag' => (string)($descriptor['tag'] ?? ''),
                'occurrence' => (int)($descriptor['occurrence'] ?? -1),
                'total' => (int)($descriptor['total'] ?? -1),
                'contextPath' => (string)($descriptor['contextPath'] ?? ''),
                'kind' => (string)($descriptor['aiKind'] ?? ''),
                'currentValue' => (string)$item['original_value'],
                'attributes' => (object)(array)($descriptor['attributes'] ?? []),
                'text' => (string)($descriptor['text'] ?? ''),
            ],
            'value' => RemediationItemData::finalValue($item),
            'reason' => (string)$item['explanation'],
            'provider' => (string)$item['provider'],
            'model' => (string)$item['model'],
            'usage' => ['inputTokens' => (int)$item['input_tokens'], 'outputTokens' => (int)$item['output_tokens'], 'reported' => (int)$item['input_tokens'] > 0],
            'serverTime' => $now,
        ];
    }

    /** The editor reports that the staged change was applied to the text (unsaved). Audit only; refresh() decides. */
    public function editorInserted(int $itemUid): array
    {
        $item = $this->visibleItem($itemUid);
        if ((string)$item['status'] === RemediationStatus::IN_EDITOR) {
            $this->event($itemUid, 'inserted', RemediationStatus::IN_EDITOR, RemediationStatus::IN_EDITOR);
        }

        return $item;
    }

    /**
     * U-09: closes pre-apply items (preparing, pending review, no suggestion, conflict, failed) whose finding is no longer
     * open — fixed, ignored or gone. Nothing is applied; anyone opening the queue triggers it, so an item never waits for
     * the one user who may still act on it. Returns the number of items closed.
     */
    public function closeItemsOfClosedFindings(): int
    {
        $closed = 0;
        $statuses = [RemediationStatus::PENDING_REVIEW, RemediationStatus::PREPARING, RemediationStatus::NO_SUGGESTION, RemediationStatus::CONFLICT, RemediationStatus::FAILED];
        foreach ($this->items->findInStatusesWithClosedFinding($statuses) as $item) {
            $before = (string)$item['status'];
            $this->move($item, RemediationStatus::NOT_APPLICABLE, ['status_reason' => 'finding_closed'], 'not_applicable');
            if ((string)($this->items->findByUid((int)$item['uid'])['status'] ?? $before) === RemediationStatus::NOT_APPLICABLE) {
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Re-reads the item's target and moves it forward only on evidence: Saved when the stored record holds the
     * approved value, Verified when AQG's own check of the stored record no longer reports the finding, Not verified
     * (with the reason) otherwise. Repeated calls never count twice: a verified item stays as it is.
     */
    public function refresh(int $itemUid): array
    {
        $item = $this->visibleItem($itemUid);
        $status = (string)$item['status'];
        $kind = (string)$item['target_kind'];

        if ($status === RemediationStatus::APPLYING && (int)$item['tstamp'] < time() - self::APPLY_INTERRUPTED_AFTER) {
            return $this->recoverInterrupted($item);
        }
        if (in_array($status, [RemediationStatus::PENDING_REVIEW, RemediationStatus::PREPARING, RemediationStatus::NO_SUGGESTION, RemediationStatus::CONFLICT, RemediationStatus::FAILED], true)) {
            $issue = $this->issues->findByUid((int)$item['finding_uid']);
            if (!is_array($issue) || (int)$issue['status'] !== IssueStatus::Open->value) {
                $this->move($item, RemediationStatus::NOT_APPLICABLE, ['status_reason' => 'finding_closed'], 'not_applicable');
            }
            return $this->mustFind($itemUid);
        }
        if (!in_array($status, [RemediationStatus::APPROVED, RemediationStatus::IN_EDITOR, RemediationStatus::SAVED, RemediationStatus::NOT_VERIFIED], true)) {
            return $item;
        }

        $result = $kind === RemediationTargetKind::IMAGE_REFERENCE_ALT ? $this->checkImage($item) : $this->checkRichText($item);
        $now = time();
        if ($result === 'value_changed' || $result === 'target_changed') {
            return $this->notThisProposal($item, $result, $now);
        }
        if ($result === 'not_saved') {
            if ($status === RemediationStatus::IN_EDITOR && (int)$item['staged_at'] < $now - self::STAGE_RELEASE_AFTER) {
                $this->move($item, RemediationStatus::APPROVED, ['status_reason' => 'released'], 'released');
            } elseif (in_array($status, [RemediationStatus::SAVED, RemediationStatus::NOT_VERIFIED], true)) {
                // The stored value is not the applied one any more (reverted or overwritten in TYPO3).
                $this->move($item, RemediationStatus::NOT_VERIFIED, ['status_reason' => 'value_changed', 'checked_at' => $now], 'checked');
            }
            return $this->mustFind($itemUid);
        }
        if ($result === 'unchecked') {
            return $this->mustFind($itemUid);
        }

        if (in_array($status, [RemediationStatus::APPROVED, RemediationStatus::IN_EDITOR], true)) {
            $this->move($item, RemediationStatus::SAVED, ['saved_at' => $now], 'saved', RemediationTarget::hashValue(RemediationItemData::finalValue($item)));
            $item = $this->mustFind($itemUid);
        }
        if ($result === 'verified') {
            $this->move($item, RemediationStatus::VERIFIED, ['verified_at' => $now, 'checked_at' => $now, 'status_reason' => ''], 'verified');
        } elseif ((string)$item['status'] === RemediationStatus::NOT_VERIFIED && $this->findingIgnored($item)) {
            // Someone decided to ignore the finding: the saved change stays in TYPO3, the item leaves the queue.
            $this->move($item, RemediationStatus::NOT_APPLICABLE, ['checked_at' => $now, 'status_reason' => 'finding_closed'], 'not_applicable');
        } else {
            $this->move($item, RemediationStatus::NOT_VERIFIED, ['checked_at' => $now, 'status_reason' => $result], 'checked');
        }

        return $this->mustFind($itemUid);
    }

    /**
     * The stored image use does not hold this reviewed proposal (another text, decorative, another file, or gone). The
     * item is then no evidence of a fix, whatever the check of the finding says: it stays Not verified with the
     * reason, and leaves the queue as "solved differently" once the finding is closed, never as Verified.
     *
     * @param array<string,mixed> $item
     */
    private function notThisProposal(array $item, string $reason, int $now): array
    {
        $itemUid = (int)$item['uid'];
        if ((string)$item['status'] !== RemediationStatus::NOT_VERIFIED || (string)$item['status_reason'] !== $reason) {
            $this->move($item, RemediationStatus::NOT_VERIFIED, ['checked_at' => $now, 'status_reason' => $reason], 'checked');
            $item = $this->mustFind($itemUid);
        }
        $issue = $this->issues->findByUid((int)$item['finding_uid']);
        // A closed finding row alone is no proof (applying an image text closes its finding itself): "solved
        // differently" also needs AQG's check of the stored image to pass now.
        if ((string)$item['status'] === RemediationStatus::NOT_VERIFIED && (!is_array($issue) || (int)$issue['status'] !== IssueStatus::Open->value)
            && ($reason !== 'value_changed' || $this->imageCheck($item) === 'verified')) {
            $this->move($item, RemediationStatus::NOT_APPLICABLE, [
                'checked_at' => $now,
                'status_reason' => $reason === 'value_changed' ? 'solved_differently' : 'finding_closed',
            ], 'not_applicable');
        }

        return $this->mustFind($itemUid);
    }

    /**
     * @param array<string,mixed> $item
     * @return string verified | still_reported | workspace_publish | check_unavailable | not_saved | value_changed | target_changed | unchecked
     */
    private function checkImage(array $item): string
    {
        $descriptor = RemediationItemData::descriptor($item);
        if ((int)$item['workspace_id'] === 0 && (int)$item['saved_at'] > 0) {
            // A passing rule proves that the image has a text, not that it is this one: the stored reference must
            // hold exactly the reviewed value, not decorative, on the reviewed file. (In a workspace the version row
            // holds the value and the live row proves nothing; that path ends in workspace_publish below.)
            $stored = $this->storedReference((int)$item['target_uid']);
            if (!is_array($stored) || (int)$stored['uid_local'] !== (int)($descriptor['fileUid'] ?? 0)) {
                return 'target_changed';
            }
            if ((string)$stored['alternative'] !== $this->appliedImageValue($item) || (int)$stored['tx_a11y_is_decorative'] !== 0) {
                return 'value_changed';
            }
        }
        $status = $this->imageCheck($item);
        if ($status === 'unchecked') {
            return 'unchecked';
        }
        if ($status === 'not_saved' && (int)$item['saved_at'] > 0) {
            return 'not_saved';
        }
        if ((int)$item['workspace_id'] > 0 || $status === 'workspace') {
            return 'workspace_publish';
        }

        return match ($status) {
            'verified' => 'verified',
            'still_reported' => 'still_reported',
            'not_saved' => 'not_saved',
            default => 'check_unavailable',
        };
    }

    /** @param array<string,mixed> $item */
    private function checkRichText(array $item): string
    {
        $expected = RemediationItemData::descriptor($item)['expected'] ?? null;
        if (!is_array($expected)) {
            return 'check_unavailable';
        }
        if ((int)($expected['before'] ?? -1) >= 0) {
            // One saved element proves one change: elements that other items of this field already count as their
            // save (same expected element) are no evidence for this item.
            $expected['before'] = (int)$expected['before'] + $this->claimedBySavedItems($item, $expected);
        }
        $since = (int)$item['staged_at'] > 0 ? (int)$item['staged_at'] : (int)$item['reviewed_at'];
        try {
            $result = $this->editorAi->verify((string)$item['target_table'], (int)$item['target_uid'], (string)$item['target_field'], [$expected + ['suggestedAt' => $since]]);
        } catch (EditorAiException) {
            return 'unchecked';
        }
        $status = (string)($result['results'][0] ?? 'not_verified');
        if ($status === 'not_saved' || $status === 'not_found') {
            return 'not_saved';
        }
        if ((int)$item['workspace_id'] > 0) {
            // The check read the workspace version; the published page and its findings change only after publishing.
            return 'workspace_publish';
        }

        return match ($status) {
            'verified' => 'verified',
            'still_reported' => 'still_reported',
            default => 'check_unavailable',
        };
    }

    /**
     * Other items of the same field and workspace that already count a stored element with the same expected tag,
     * attributes and text as their save. Only saves after this item's approval count: an element saved earlier is
     * part of this item's 'before' already. A "Not verified" item whose value is gone from the record claims nothing.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $expected
     */
    private function claimedBySavedItems(array $item, array $expected): int
    {
        $signature = static function (array $expected): string {
            $attributes = array_map('strval', (array)($expected['attributes'] ?? []));
            ksort($attributes);

            return json_encode([strtolower((string)($expected['tag'] ?? '')), $attributes, trim((string)($expected['text'] ?? ''))], JSON_UNESCAPED_UNICODE) ?: '';
        };
        $own = $signature($expected);
        $claimed = 0;
        foreach ($this->items->findForQueue(['sites' => [(string)$item['site_identifier']], 'pageUid' => (int)$item['page_uid'], 'statuses' => RemediationStatus::SAVED_STATES], 1000) as $other) {
            if ((int)$other['uid'] === (int)$item['uid']
                || (string)$other['target_kind'] !== RemediationTargetKind::RICH_TEXT
                || (string)$other['target_table'] !== (string)$item['target_table']
                || (int)$other['target_uid'] !== (int)$item['target_uid']
                || (string)$other['target_field'] !== (string)$item['target_field']
                || (int)$other['workspace_id'] !== (int)$item['workspace_id']
                || (int)$other['saved_at'] < (int)$item['reviewed_at']
                || ((string)$other['status'] === RemediationStatus::NOT_VERIFIED && (string)$other['status_reason'] === 'value_changed')) {
                continue;
            }
            $otherExpected = RemediationItemData::descriptor($other)['expected'] ?? null;
            if (is_array($otherExpected) && $signature($otherExpected) === $own) {
                $claimed++;
            }
        }

        return $claimed;
    }

    /**
     * AQG's check of the stored image use: verified | still_reported | workspace | not_saved | not_verified | unchecked.
     *
     * @param array<string,mixed> $item
     */
    private function imageCheck(array $item): string
    {
        $descriptor = RemediationItemData::descriptor($item);
        try {
            $result = $this->referenceAi->verify(
                (string)($descriptor['parentTable'] ?? ''),
                (int)($descriptor['parentUid'] ?? 0),
                (string)($descriptor['fieldName'] ?? ''),
                [['referenceUid' => (int)$item['target_uid'], 'suggestedAt' => (int)$item['applied_at']]],
            );
        } catch (EditorAiException) {
            return 'unchecked';
        }

        return (string)($result['items'][0]['status'] ?? '');
    }

    /**
     * The live sys_file_reference row as stored now, or null when it is gone.
     *
     * @return array<string,mixed>|null
     */
    private function storedReference(int $referenceUid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(Tables::SYS_FILE_REFERENCE)->executeQuery(
            'SELECT alternative, tx_a11y_is_decorative, uid_local FROM ' . Tables::SYS_FILE_REFERENCE . ' WHERE uid = ? AND deleted = 0',
            [$referenceUid],
            [Connection::PARAM_INT],
        )->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The alternative text the apply step writes for this item: the approved value as validated for storage.
     *
     * @param array<string,mixed> $item
     */
    private function appliedImageValue(array $item): string
    {
        $value = trim(str_replace(["\r\n", "\r", "\n"], ' ', RemediationItemData::finalValue($item)));
        try {
            return $this->altTextValidator->validate($value);
        } catch (\InvalidArgumentException) {
            return $value;
        }
    }

    /** @param array<string,mixed> $item */
    private function recoverInterrupted(array $item): array
    {
        $current = $this->storedReference((int)$item['target_uid']);
        $written = is_array($current)
            && (string)$current['alternative'] === $this->appliedImageValue($item)
            && (int)$current['uid_local'] === (int)(RemediationItemData::descriptor($item)['fileUid'] ?? 0)
            && (int)$current['tx_a11y_is_decorative'] === 0;
        if ($written) {
            $this->move($item, RemediationStatus::SAVED, ['saved_at' => time(), 'status_reason' => 'recovered'], 'saved');
            return $this->refresh((int)$item['uid']);
        }
        $this->move($item, RemediationStatus::FAILED, ['status_reason' => 'interrupted'], 'apply_failed');

        return $this->mustFind((int)$item['uid']);
    }

    /**
     * Assigns the owner role of one rule on one page (and of that rule's open items there). Needs edit access to the
     * page; every change is recorded.
     *
     * @return array{owner:string,updated:int}
     */
    public function assignOwner(string $siteIdentifier, int $pageUid, string $ruleId, string $owner): array
    {
        $this->access->assertLoggedIn();
        if (!RemediationOwner::isValid($owner) || $ruleId === '' || strlen($ruleId) > 255 || $pageUid <= 0) {
            throw new RemediationException('invalid_input', 'Unknown owner.', 422);
        }
        if (!$this->access->canEditPage($pageUid) || !$this->access->canReadSiteIdentifier($siteIdentifier)) {
            throw new RemediationException('permission_denied', 'You may not change the owner on this page.', 403);
        }
        // Ownership is part of the paid remediation workflow (PRO/Agency, not Trial or Free), like the views offering it.
        if (!$this->access->hasLicence($siteIdentifier)) {
            throw new RemediationException('no_licence', 'Owners need an AQG PRO or Agency licence.', 403);
        }
        $this->items->saveOwnerAssignment($siteIdentifier, $pageUid, $ruleId, $owner, $this->access->userUid());
        $updated = 0;
        foreach ($this->items->findForQueue(['sites' => [$siteIdentifier], 'pageUid' => $pageUid, 'statuses' => RemediationStatus::ACTIVE], 500) as $item) {
            if ((string)$item['rule_id'] !== $ruleId || (string)$item['owner'] === $owner) {
                continue;
            }
            if ($this->items->transition((int)$item['uid'], (int)$item['row_version'], [(string)$item['status']], (string)$item['status'], ['owner' => $owner])) {
                $this->event((int)$item['uid'], 'owner_changed', (string)$item['status'], (string)$item['status'], $owner);
                $updated++;
            }
        }

        return ['owner' => $owner, 'updated' => $updated];
    }

    /**
     * The owner of a finding: the assignment for its rule on its page, else AQG's suggestion.
     *
     * @param array<string,mixed> $issue
     */
    public function ownerFor(array $issue, bool $hasEditableTarget, ?array $assignments = null): string
    {
        $ruleId = (string)($issue['rule_id'] ?? '');
        $assigned = $assignments !== null
            ? ($assignments[$ruleId] ?? null)
            : $this->items->findOwnerAssignment((string)($issue['site_identifier'] ?? ''), (int)($issue['page_uid'] ?? 0), $ruleId);
        if (is_string($assigned) && RemediationOwner::isValid($assigned)) {
            return $assigned;
        }
        $presented = $this->ruleMetadata->present($issue);

        return RemediationOwner::suggest((string)($presented['owner'] ?? ''), $ruleId, (string)($issue['source_type'] ?? ''), $hasEditableTarget);
    }

    /**
     * The item that keeps a finding from getting another suggestion: an open one of the same workspace. Saved changes
     * (saved, not verified) do not block a new attempt, items of other workspaces never block this one, and a
     * suggestion nobody prepared within a day is released.
     *
     * @return array<string,mixed>|null
     */
    private function blockingItem(int $findingUid, int $exceptUid = 0): ?array
    {
        $workspace = $this->access->currentWorkspace();
        $blocking = array_values(array_diff(RemediationStatus::ACTIVE, RemediationStatus::SAVED_STATES));
        foreach ($this->items->findActiveForFindings([$findingUid], $blocking) as $item) {
            if ((int)$item['uid'] === $exceptUid || (int)$item['workspace_id'] !== $workspace) {
                continue;
            }
            if ((string)$item['status'] === RemediationStatus::PREPARING && (int)$item['tstamp'] < time() - self::PREPARING_EXPIRES_AFTER) {
                $this->move($item, RemediationStatus::NOT_APPLICABLE, ['status_reason' => 'expired'], 'not_applicable');
                continue;
            }

            return $item;
        }

        return null;
    }

    /** @param array<string,mixed> $item */
    private function findingIgnored(array $item): bool
    {
        $issue = $this->issues->findByUid((int)$item['finding_uid']);

        return !is_array($issue) || (int)$issue['status'] === IssueStatus::Ignored->value;
    }

    /** @return array<string,mixed> an item the current user may see */
    public function visibleItem(int $itemUid): array
    {
        $this->access->assertLoggedIn();
        $item = $this->items->findByUid($itemUid);
        if ($item === null || !$this->access->canSee($item)) {
            throw new RemediationException('not_found', 'This item does not exist or is not visible to you.', 404);
        }

        return $item;
    }

    /** @param array<string,mixed> $item */
    private function validatedValue(array $item, string $value): string
    {
        $value = trim(str_replace(["\r\n", "\r", "\n"], ' ', $value));
        if ($value === '') {
            throw new RemediationException('invalid_value', 'Enter a value.', 422);
        }
        if ((string)$item['target_kind'] === RemediationTargetKind::IMAGE_REFERENCE_ALT) {
            try {
                return $this->altTextValidator->validate($value);
            } catch (\InvalidArgumentException) {
                // The validator throws plain InvalidArgumentException (empty, too long), not only its subclass.
                throw new RemediationException('invalid_value', 'This alternative text cannot be stored.', 422);
            }
        }
        $kind = (string)(RemediationItemData::descriptor($item)['aiKind'] ?? '');
        [$validated, $rejection] = $this->valueValidator->validate($kind, $value, (string)$item['original_value']);
        if ($rejection !== EditorAiValueValidator::VALID) {
            throw new RemediationException('invalid_value', 'This value cannot be applied safely.', 422, ['rejection' => $rejection]);
        }

        return $validated;
    }

    /**
     * @param array<string,mixed> $item
     * @param list<string> $from
     * @param array<string,mixed> $fields
     */
    private function transitionOrFail(array $item, int $rowVersion, array $from, string $to, array $fields, string $action, string $valueHash = '', string $detail = ''): void
    {
        $current = (string)$item['status'];
        if ($current !== $to && !RemediationStatus::canTransition($current, $to)) {
            throw new RemediationException('invalid_state', 'This step is not possible now.', 409, ['status' => $current]);
        }
        if (!$this->items->transition((int)$item['uid'], $rowVersion, $from, $to, $fields)) {
            $fresh = $this->items->findByUid((int)$item['uid']) ?? [];
            throw new RemediationException('changed_concurrently', 'This item changed in the meantime. Review it again.', 409, ['status' => (string)($fresh['status'] ?? ''), 'rowVersion' => (int)($fresh['row_version'] ?? 0)]);
        }
        $this->event((int)$item['uid'], $action, $current, $to, $detail, $valueHash, (int)($fields['proposal_version'] ?? $item['proposal_version']));
    }

    /**
     * A transition the server makes on its own evidence, from the item row it just read.
     *
     * @param array<string,mixed> $item
     * @param array<string,mixed> $fields
     */
    private function move(array $item, string $to, array $fields, string $action, string $valueHash = '', string $detail = ''): void
    {
        // Only from the state the caller observed: a step someone else took meanwhile (a rejection while the
        // suggestion was generated) is never overridden by this one.
        $from = (string)$item['status'];
        $fresh = $this->mustFind((int)$item['uid']);
        if ((string)$fresh['status'] !== $from || ($from !== $to && !RemediationStatus::canTransition($from, $to))) {
            return;
        }
        if ($this->items->transition((int)$fresh['uid'], (int)$fresh['row_version'], [$from], $to, $fields)) {
            $this->event((int)$fresh['uid'], $action, $from, $to, $detail, $valueHash, (int)($fields['proposal_version'] ?? $fresh['proposal_version']));
        }
    }

    private function event(int $itemUid, string $action, string $from, string $to, string $detail = '', string $valueHash = '', int $proposalVersion = 0): void
    {
        $this->items->addEvent([
            'item_uid' => $itemUid,
            'be_user' => $this->access->userUid(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'detail' => mb_substr($detail, 0, 64),
            'proposal_version' => $proposalVersion,
            'value_hash' => $valueHash,
        ]);
    }

    /** @return array<string,mixed> */
    private function mustFind(int $itemUid): array
    {
        $item = $this->items->findByUid($itemUid);
        if ($item === null) {
            throw new RemediationException('not_found', 'This item no longer exists.', 404);
        }

        return $item;
    }

    /** Status reasons for editor AI states: paused and budget_reached keep their own reason. */
    private function readinessReason(string $state): string
    {
        return match ($state) {
            EditorAiAccessService::STATE_PAUSED => 'ai_paused',
            EditorAiAccessService::STATE_BUDGET_REACHED => 'budget_reached',
            EditorAiAccessService::STATE_NOT_CONFIGURED => 'provider_not_configured',
            default => $state,
        };
    }

    private function providerReason(int $code): string
    {
        return match ($code) {
            1771002313 => 'provider_refused',
            1771002302 => 'provider_timeout',
            1771002311 => 'provider_rate_limited',
            1771002320, 1771002323 => 'provider_credit',
            1771002310, 1771002317, 1771002321 => 'provider_paused',
            1771002301, 1771002304, 1771002305, 1771002314, 1771002315, 1771002316, 1771002318 => 'invalid_response',
            default => 'provider_unavailable',
        };
    }
}
