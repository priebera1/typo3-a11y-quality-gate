<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\RemediationItemRepositoryInterface;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * The browser shape of a remediation item. Values are plain strings the page inserts as text, never markup. It
 * carries what a reviewer needs to decide (the complete before/after, target, provenance, who did what) and the
 * actions this person may take; no table/uid pair the browser could send back, no prompt, no cost figure.
 */
final class RemediationItemPresenter
{
    private const L = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:';
    private const LOG_OUTCOMES = [
        RemediationStatus::SAVED, RemediationStatus::VERIFIED, RemediationStatus::NOT_VERIFIED,
        RemediationStatus::CONFLICT, RemediationStatus::FAILED, RemediationStatus::REJECTED,
    ];

    /** @var array<int,string> */
    private array $userNames = [];
    /** @var array<int,string> */
    private array $pageTitles = [];
    /** @var array<string,bool> */
    private array $aiReady = [];
    /** @var array<string,string> */
    private array $modelLabels = [];

    public function __construct(
        private readonly RemediationAccessService $access,
        private readonly RemediationItemRepositoryInterface $items,
        private readonly RuleMetadataPresentationService $ruleMetadata,
        private readonly BackendLanguageService $languageService,
        private readonly UriBuilder $uriBuilder,
        private readonly IssueRemediationRepositoryInterface $issues,
        private readonly RemediationConflictView $conflictView,
        private readonly AiModelCompatibilityRegistry $models,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     */
    public function present(array $item, bool $detailed = false, string $returnUrl = ''): array
    {
        $descriptor = RemediationItemData::descriptor($item);
        $status = (string)$item['status'];
        $kind = (string)$item['target_kind'];
        $language = $this->languageService->getCurrentLanguageCode();
        $applyRefusal = $this->access->applyRefusal($item, false);
        // U-09: the person who prepared it in bulk lost the bulk permission: say so (they can still reject it).
        if ($applyRefusal === 'permission_denied' && (string)$item['origin'] === RemediationAccessService::ORIGIN_BULK
            && (int)$item['created_by'] === $this->access->userUid() && !$this->access->mayUseBulk()) {
            $applyRefusal = 'bulk_permission_lost';
        }
        $retryableFailure = $status === RemediationStatus::FAILED && in_array((string)$item['status_reason'], RemediationWorkflowService::RETRYABLE_APPLY_FAILURES, true);
        $proposalVersion = (int)$item['proposal_version'];
        $seen = $proposalVersion > 0 && $this->items->hasSeen((int)$item['uid'], $this->access->userUid(), $proposalVersion);
        $severity = Severity::tryFrom((int)$item['severity']);
        // New AI requests only where the site's AI is ready for this person; reviewing stored proposals never needs it.
        $aiReady = $this->aiReady[(string)$item['site_identifier']] ??= $this->access->aiState((string)$item['site_identifier']) === 'ready';

        $data = [
            'uid' => (int)$item['uid'],
            'rowVersion' => (int)$item['row_version'],
            'proposalVersion' => $proposalVersion,
            'status' => $status,
            'statusLabel' => $this->label('remediation.status.' . $status, $status),
            'statusReason' => (string)$item['status_reason'],
            // "Being prepared" only while the request runs; an interrupted one says nothing until it is generated again.
            'statusReasonLabel' => (string)$item['status_reason'] !== '' && ((string)$item['status_reason'] !== RemediationWorkflowService::GENERATING || RemediationWorkflowService::isGenerating($item))
                ? $this->label('remediation.reason.' . (string)$item['status_reason'], '') : '',
            'isDone' => RemediationStatus::isDone($status),
            'isSaved' => in_array($status, RemediationStatus::SAVED_STATES, true),
            'isVerified' => $status === RemediationStatus::VERIFIED,
            'kind' => $kind,
            'applyMode' => RemediationTargetKind::isServerWritable($kind) ? 'server' : (RemediationTargetKind::isEditorApplied($kind) ? 'editor' : 'none'),
            'ruleId' => (string)$item['rule_id'],
            'ruleTitle' => $this->ruleTitle($item, $language),
            'severity' => $severity?->key() ?? 'info',
            'severityLabel' => $this->label('severity.' . ($severity === Severity::NeedsReview ? 'needsReview' : ($severity?->key() ?? 'info')), $severity?->key() ?? ''),
            'owner' => (string)$item['owner'],
            'ownerLabel' => $this->label('remediation.owner.' . (string)$item['owner'], (string)$item['owner']),
            'origin' => (string)$item['origin'],
            'originLabel' => $this->label('remediation.origin.' . (string)$item['origin'], (string)$item['origin']),
            'pageUid' => (int)$item['page_uid'],
            'pageTitle' => $this->pageTitle((int)$item['page_uid']),
            'languageUid' => (int)$item['language_uid'],
            'workspaceId' => (int)$item['workspace_id'],
            'fieldLabel' => $this->fieldLabel($kind, $descriptor),
            'targetLabel' => $this->targetLabel($kind, $descriptor, (int)$item['target_uid']),
            'originalValue' => (string)$item['original_value'],
            'proposedValue' => (string)$item['proposed_value'],
            'editedValue' => (string)$item['edited_value'],
            'finalValue' => RemediationItemData::finalValue($item),
            'isEdited' => RemediationItemData::isEdited($item),
            'explanation' => (string)$item['explanation'],
            'provider' => (string)$item['provider'],
            'providerLabel' => (string)$item['provider'] !== '' ? AiProviderCatalog::label((string)$item['provider']) : '',
            'model' => (string)$item['model'],
            'modelLabel' => $this->modelLabel($item),
            'usage' => ['inputTokens' => (int)$item['input_tokens'], 'outputTokens' => (int)$item['output_tokens']],
            'createdBy' => $this->userName((int)$item['created_by']),
            'reviewedBy' => $this->userName((int)$item['reviewed_by']),
            'appliedBy' => $this->userName((int)$item['applied_by']),
            'createdAt' => (int)$item['crdate'],
            'updatedAt' => (int)$item['tstamp'],
            'reviewedAt' => (int)$item['reviewed_at'],
            'appliedAt' => (int)$item['applied_at'],
            'savedAt' => (int)$item['saved_at'],
            'verifiedAt' => (int)$item['verified_at'],
            'checkedAt' => (int)$item['checked_at'],
            'seen' => $seen,
            'isOwnRequest' => (int)$item['created_by'] === $this->access->userUid(),
            'permissionLine' => $applyRefusal !== '' ? $applyRefusal : ($kind === RemediationTargetKind::IMAGE_REFERENCE_ALT && (int)$item['workspace_id'] > 0 ? 'workspace_apply_in_record' : 'can_apply'),
            'actions' => [
                'approve' => $applyRefusal === '' && ($status === RemediationStatus::PENDING_REVIEW || $retryableFailure) && $proposalVersion > 0
                    && !($kind === RemediationTargetKind::IMAGE_REFERENCE_ALT && (int)$item['workspace_id'] > 0),
                'retry' => $applyRefusal === '' && $retryableFailure,
                'edit' => $applyRefusal === '' && $status === RemediationStatus::PENDING_REVIEW,
                'reject' => in_array($status, [RemediationStatus::PREPARING, RemediationStatus::PENDING_REVIEW, RemediationStatus::NO_SUGGESTION, RemediationStatus::APPROVED, RemediationStatus::IN_EDITOR, RemediationStatus::CONFLICT, RemediationStatus::FAILED], true)
                    && ($applyRefusal === '' || (int)$item['created_by'] === $this->access->userUid()),
                'regenerate' => $aiReady && (in_array($status, [RemediationStatus::NO_SUGGESTION, RemediationStatus::CONFLICT, RemediationStatus::PENDING_REVIEW], true)
                    || ($status === RemediationStatus::FAILED && !in_array((string)$item['status_reason'], RemediationWorkflowService::APPLY_FAILURES, true))),
                // Not while another request generates: the second click would only be refused as in progress.
                'generate' => $aiReady && $status === RemediationStatus::PREPARING && !RemediationWorkflowService::isGenerating($item),
                'reopen' => $status === RemediationStatus::REJECTED,
                'verify' => in_array($status, [RemediationStatus::APPROVED, RemediationStatus::IN_EDITOR, RemediationStatus::SAVED, RemediationStatus::NOT_VERIFIED, RemediationStatus::APPLYING], true),
                'openInEditor' => $applyRefusal === '' && RemediationTargetKind::isEditorApplied($kind) && in_array($status, [RemediationStatus::PENDING_REVIEW, RemediationStatus::APPROVED, RemediationStatus::IN_EDITOR], true),
            ],
            'editUrl' => '',
            'recordUrl' => '',
            'pageDetailUrl' => $this->pageDetailUrl((int)$item['page_uid'], (int)$item['language_uid']),
        ];
        if ($applyRefusal === '') {
            $data['recordUrl'] = $this->recordUrl($kind, $descriptor, $item, $returnUrl, false);
            if (RemediationTargetKind::isEditorApplied($kind) && in_array($status, [RemediationStatus::APPROVED, RemediationStatus::IN_EDITOR], true)) {
                $data['editUrl'] = $this->recordUrl($kind, $descriptor, $item, $returnUrl, true);
            }
        }
        if ($detailed) {
            $data['promptVersion'] = (string)$item['prompt_version'];
            $data['sent'] = array_values(array_map('strval', (array)($descriptor['sent'] ?? [])));
            $data['element'] = RemediationTargetKind::isEditorApplied($kind) ? [
                'tag' => (string)($descriptor['tag'] ?? ''),
                'occurrence' => (int)($descriptor['occurrence'] ?? -1),
                'attribute' => (string)($descriptor['attribute'] ?? ''),
            ] : null;
            $data['history'] = array_map(fn (array $event): array => [
                'action' => (string)$event['action'],
                'actionLabel' => $this->label('remediation.history.' . (string)$event['action'], (string)$event['action']),
                'by' => $this->userName((int)$event['be_user']),
                'at' => (int)$event['crdate'],
                'from' => (string)$event['from_status'],
                'to' => (string)$event['to_status'],
                'detail' => (string)$event['detail'],
                'version' => (int)$event['proposal_version'],
            ], $this->items->findEvents((int)$item['uid']));
            $data['findingUid'] = (int)$item['finding_uid'];
            $data['previewUrl'] = $this->previewUrl($kind, $descriptor);
            // Atlas 08·B: base / saved now / suggested, read only. Applying stays blocked; the drawer offers only the
            // existing steps (Regenerate, Discard, open the record).
            $data['conflict'] = $status === RemediationStatus::CONFLICT ? $this->conflictView->compare($item) : null;
        }

        return $data;
    }

    /**
     * The text the queue search looks in: page title, finding, field, target and both values. Built only for rows the
     * person may see (RemediationQueueService filters visibility first).
     *
     * @param array<string,mixed> $item
     */
    public function searchText(array $item): string
    {
        $kind = (string)$item['target_kind'];
        $descriptor = RemediationItemData::descriptor($item);

        return implode("\n", [
            $this->pageTitle((int)$item['page_uid']),
            $this->ruleTitle($item, $this->languageService->getCurrentLanguageCode()),
            (string)$item['rule_id'],
            $this->fieldLabel($kind, $descriptor),
            $this->targetLabel($kind, $descriptor, (int)$item['target_uid']),
            (string)$item['original_value'],
            RemediationItemData::finalValue($item),
        ]);
    }

    /**
     * One change-log row (Atlas 08·E). Events store codes and value hashes only, so the row shows the step, the status
     * before → after and the short hash as stored; it never reconstructs a value from them.
     *
     * @param array<string,mixed> $event a tx_a11y_remediation_event row
     * @param array<string,mixed> $item the item it belongs to, visible to the person
     * @return array<string,mixed>
     */
    public function presentLogEntry(array $event, array $item): array
    {
        $kind = (string)$item['target_kind'];
        $descriptor = RemediationItemData::descriptor($item);
        $from = (string)$event['from_status'];
        $to = (string)$event['to_status'];
        $outcome = self::eventOutcome($from, $to);

        return [
            'uid' => (int)$event['uid'],
            'itemUid' => (int)$item['uid'],
            'at' => (int)$event['crdate'],
            'by' => $this->userName((int)$event['be_user']),
            'action' => (string)$event['action'],
            'actionLabel' => $this->label('remediation.history.' . (string)$event['action'], (string)$event['action']),
            'from' => $from,
            'fromLabel' => $from !== '' ? $this->label('remediation.status.' . $from, $from) : '',
            'to' => $to,
            'toLabel' => $to !== '' ? $this->label('remediation.status.' . $to, $to) : '',
            'valueHash' => self::shortHash((string)$event['value_hash']),
            'version' => (int)$event['proposal_version'],
            'pageUid' => (int)$item['page_uid'],
            'pageTitle' => $this->pageTitle((int)$item['page_uid']),
            'ruleTitle' => $this->ruleTitle($item, $this->languageService->getCurrentLanguageCode()),
            'fieldLabel' => $this->fieldLabel($kind, $descriptor),
            'model' => (string)$item['model'],
            'modelLabel' => $this->modelLabel($item),
            'providerLabel' => (string)$item['provider'] !== '' ? AiProviderCatalog::label((string)$item['provider']) : '',
            'origin' => (string)$item['origin'],
            'originLabel' => $this->label('remediation.origin.' . (string)$item['origin'], (string)$item['origin']),
            // The outcome this event reached (Atlas 08·E), not the item's final state repeated on every row.
            'outcome' => $outcome,
            'outcomeLabel' => $outcome !== '' ? $this->label('remediation.status.' . $outcome, $outcome) : '',
        ];
    }

    /**
     * The outcome an event reached: only a step into an outcome state (Saved, Verified, Not verified, Conflict,
     * Failed, Rejected) has one; preparing, viewing, editing or approving has none.
     */
    public static function eventOutcome(string $from, string $to): string
    {
        return $to !== $from && in_array($to, self::LOG_OUTCOMES, true) ? $to : '';
    }

    /** "3f2a…c19e": enough to compare two rows, never the full digest in the table. */
    public static function shortHash(string $hash): string
    {
        $hash = trim($hash);

        return strlen($hash) > 12 ? substr($hash, 0, 4) . '…' . substr($hash, -4) : $hash;
    }

    public function personName(int $uid): string
    {
        return $this->userName($uid);
    }

    /**
     * The rule's plain name as Page Detail shows it (`rule.<id>.title` in the backend language); the stored finding
     * message only for a rule without any translated title, never a humanised rule id ("File reference alt").
     */
    private function ruleTitle(array $item, string $language): string
    {
        $ruleId = (string)$item['rule_id'];
        $title = RemediationRuleTitle::localized($this->ruleMetadata, $ruleId, $language);
        if ($title !== '') {
            return $title;
        }
        $message = trim((string)($this->issues->findByUid((int)$item['finding_uid'])['message'] ?? ''));

        return $message !== '' ? $message : RemediationRuleTitle::plain($this->ruleMetadata, $ruleId, $language);
    }

    /** "Claude Haiku 5.5" for `claude-haiku-5-5`, as AI settings name it; an unknown id stays as stored. */
    private function modelLabel(array $item): string
    {
        $model = (string)$item['model'];
        if ($model === '') {
            return '';
        }

        return $this->modelLabels[$model] ??= (string)($this->models->find($model)['label'] ?? $model);
    }

    /**
     * A small backend preview of the referenced image (Atlas 08·B): only for an image file of a public storage that
     * this person may read; anything else shows no preview rather than a broken or private one.
     *
     * @param array<string,mixed> $descriptor
     */
    private function previewUrl(string $kind, array $descriptor): string
    {
        $fileUid = (int)($descriptor['fileUid'] ?? 0);
        if ($kind !== RemediationTargetKind::IMAGE_REFERENCE_ALT || $fileUid <= 0) {
            return '';
        }
        try {
            $file = $this->resourceFactory->getFileObject($fileUid);
            if ($file->isMissing() || !$file->exists() || !$file->getStorage()->isPublic() || !$file->checkActionPermission('read')) {
                return '';
            }
            $url = $file->getMimeType() === 'image/svg+xml'
                ? $file->getPublicUrl()
                : ($file->isImage() ? $file->process(ProcessedFile::CONTEXT_IMAGEPREVIEW, ['width' => 160, 'height' => 160])->getPublicUrl() : '');
        } catch (\Throwable) {
            return '';
        }

        return is_string($url) ? $url : '';
    }

    private function label(string $key, string $fallback): string
    {
        $value = $this->languageService->translate($key);

        return $value !== '' && $value !== $key ? $value : $fallback;
    }

    private function userName(int $uid): string
    {
        if ($uid <= 0) {
            return '';
        }
        if (!isset($this->userNames[$uid])) {
            $user = BackendUtility::getRecord('be_users', $uid, 'username,realName');
            $name = is_array($user) ? trim((string)($user['realName'] ?? '')) : '';
            $this->userNames[$uid] = $name !== '' ? $name : (is_array($user) ? (string)($user['username'] ?? '') : '');
        }

        return $this->userNames[$uid];
    }

    private function pageTitle(int $pageUid): string
    {
        if (!isset($this->pageTitles[$pageUid])) {
            $page = $pageUid > 0 ? BackendUtility::getRecordWSOL(Tables::PAGES, $pageUid) : null;
            $this->pageTitles[$pageUid] = is_array($page) ? BackendUtility::getRecordTitle(Tables::PAGES, $page) : '';
        }

        return $this->pageTitles[$pageUid];
    }

    /** @param array<string,mixed> $descriptor */
    private function fieldLabel(string $kind, array $descriptor): string
    {
        if ($kind === RemediationTargetKind::IMAGE_REFERENCE_ALT) {
            return $this->label('remediation.field.imageAlt', 'Image · Alternative text (this use only)');
        }
        if ($kind === RemediationTargetKind::RICH_TEXT) {
            return (string)($descriptor['cType'] ?? '') === 'html'
                ? $this->label('remediation.field.plainHtml', 'Plain HTML')
                : $this->label('remediation.field.richText', 'Text (rich text)');
        }

        return $this->label('remediation.field.manual', 'Not editable here');
    }

    /** @param array<string,mixed> $descriptor */
    private function targetLabel(string $kind, array $descriptor, int $targetUid): string
    {
        if ($kind === RemediationTargetKind::IMAGE_REFERENCE_ALT) {
            $fileUid = (int)($descriptor['fileUid'] ?? 0);
            $file = $fileUid > 0 ? BackendUtility::getRecord(Tables::SYS_FILE, $fileUid, 'name') : null;
            $parent = BackendUtility::getRecord((string)($descriptor['parentTable'] ?? Tables::TT_CONTENT), (int)($descriptor['parentUid'] ?? 0), 'uid,header');
            $parentLabel = is_array($parent) ? trim((string)($parent['header'] ?? '')) : '';

            return trim(($parentLabel !== '' ? $parentLabel . ' [' . (int)($descriptor['parentUid'] ?? 0) . '] › ' : '') . (is_array($file) ? (string)$file['name'] : ''));
        }
        if ($kind === RemediationTargetKind::RICH_TEXT) {
            $record = BackendUtility::getRecord(Tables::TT_CONTENT, $targetUid, 'uid,header');
            $header = is_array($record) ? trim((string)($record['header'] ?? '')) : '';

            return ($header !== '' ? $header . ' ' : '') . '[' . $targetUid . ']';
        }

        return '';
    }

    /**
     * The record editor for the item. With $staged the record opens with the approved change staged for the
     * Smart Editor (aqgRemediation = item uid; the editor asks the server for the change, nothing travels in the URL).
     *
     * @param array<string,mixed> $descriptor
     * @param array<string,mixed> $item
     */
    private function recordUrl(string $kind, array $descriptor, array $item, string $returnUrl, bool $staged): string
    {
        [$table, $uid] = $kind === RemediationTargetKind::IMAGE_REFERENCE_ALT
            ? [(string)($descriptor['parentTable'] ?? ''), (int)($descriptor['parentUid'] ?? 0)]
            : [(string)$item['target_table'], (int)$item['target_uid']];
        if ($table === '' || $uid <= 0) {
            return '';
        }
        $parameters = ['edit' => [$table => [$uid => 'edit']]];
        if ($returnUrl !== '') {
            $parameters['returnUrl'] = $returnUrl;
        }
        if ($staged) {
            $parameters['aqgRemediation'] = (int)$item['uid'];
        }

        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', $parameters);
    }

    private function pageDetailUrl(int $pageUid, int $languageUid): string
    {
        if ($pageUid <= 0) {
            return '';
        }

        return (string)$this->uriBuilder->buildUriFromRoute('web_a11y.pageDetail', ['id' => $pageUid, 'pageUid' => $pageUid, 'language' => $languageUid]);
    }
}
