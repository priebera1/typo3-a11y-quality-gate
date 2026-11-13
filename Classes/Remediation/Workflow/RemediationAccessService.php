<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Contract\LocalFindingSourceAccessInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;

/**
 * Who may see, prepare, approve and apply remediation items. Layered on AQG's existing model and never wider than it
 * (Atlas "Roles and permissions"): module access and page show access to see an item; PRO/Agency for the queue; the
 * Smart Editor's TSconfig flags (ai.editor, ai.images, ai.bulk) for AI work; record and field edit access, the
 * existing allowImageRemediation for AQG's server write, and the same workspace for applying.
 *
 * Decisions the Atlas leaves open are kept closed here: an item prepared by an agent can never be approved
 * (agent-originated approval is not authorized), and there is no two-step approval (creator ≠ approver) yet — the
 * creator, reviewer and applier are recorded separately so it can be added without a data change.
 */
final class RemediationAccessService
{
    public const ORIGIN_EDITOR_REQUEST = 'editor_request';
    public const ORIGIN_BULK = 'bulk';
    public const ORIGIN_AGENT = 'agent';

    /** @var array<int,bool> */
    private array $readablePages = [];

    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly BackendRecordAccessService $recordAccessService,
        private readonly EditorAiAccessService $editorAiAccessService,
        private readonly AiFeatureAccessServiceInterface $featureAccessService,
        private readonly AccessControlService $accessControlService,
        private readonly ?LocalFindingSourceAccessInterface $sourceAccessService = null,
    ) {}

    public function assertLoggedIn(): void
    {
        if (!$this->backendUserService->isLoggedIn()) {
            throw new RemediationException('session_expired', 'Your TYPO3 session has expired.', 401);
        }
        if (!$this->backendUserService->canAccessAccessibilityModule()) {
            throw new RemediationException('permission_denied', 'No access to the accessibility module.', 403);
        }
    }

    public function userUid(): int
    {
        return $this->backendUserService->getBackendUserUid();
    }

    public function isAdmin(): bool
    {
        return $this->backendUserService->isAdmin();
    }

    public function currentWorkspace(): int
    {
        return (int)($this->backendUserService->getBackendUser()?->workspace ?? 0);
    }

    /** PRO / Agency (not Trial, not Free) for this site: the queue is a paid feature. */
    public function hasLicence(string $siteIdentifier): bool
    {
        return $siteIdentifier !== '' && $this->featureAccessService->isAvailable($siteIdentifier);
    }

    /** The editor AI state of the site for this user: ready, no_licence, no_permission, not_configured, paused. */
    public function aiState(string $siteIdentifier): string
    {
        return $this->editorAiAccessService->state($siteIdentifier);
    }

    /**
     * "Provider · model" of the site's AI for the queue header, or '' when AI is not ready for this person. Names only:
     * no credential, cost or prompt data.
     */
    public function aiModelLine(string $siteIdentifier): string
    {
        $configuration = $this->readyAiConfiguration($siteIdentifier);
        if ($configuration === null) {
            return '';
        }

        return implode(' · ', array_filter([(string)($configuration['providerLabel'] ?? ''), (string)($configuration['modelLabel'] ?? '')], static fn (string $part): bool => $part !== ''));
    }

    /**
     * Provider and model ids of the site's AI when it is ready for this person, else null.
     *
     * @return array{provider:string,model:string,providerLabel:string,modelLabel:string}|null
     */
    public function readyAiConfiguration(string $siteIdentifier): ?array
    {
        $configuration = $this->editorAiAccessService->editorConfiguration($siteIdentifier);
        if (($configuration['state'] ?? '') !== EditorAiAccessService::STATE_READY) {
            return null;
        }

        return [
            'provider' => (string)($configuration['provider'] ?? ''),
            'model' => (string)($configuration['model'] ?? ''),
            'providerLabel' => (string)($configuration['providerLabel'] ?? ''),
            'modelLabel' => (string)($configuration['modelLabel'] ?? ''),
        ];
    }

    public function mayUseBulk(): bool
    {
        return $this->editorAiAccessService->userAllows(EditorAiAccessService::FLAG_BULK);
    }

    public function mayUseImages(): bool
    {
        return $this->editorAiAccessService->userAllows(EditorAiAccessService::FLAG_IMAGES);
    }

    public function canReadPage(int $pageUid): bool
    {
        return $this->readablePages[$pageUid] ??= $this->scopeAccessService->canReadPage($pageUid);
    }

    public function canEditPage(int $pageUid): bool
    {
        return $this->scopeAccessService->canEditPage($pageUid);
    }

    public function canReadSiteIdentifier(string $siteIdentifier): bool
    {
        return $this->scopeAccessService->canReadSiteIdentifier($siteIdentifier);
    }

    /**
     * A site may appear in the AI fixes queue and change log when the person may read any page of it (N-09: editors
     * mounted below the root saw an empty queue). Each item is still filtered by canSee() and visibleScope().
     */
    public function canListSiteIdentifier(string $siteIdentifier): bool
    {
        return $this->scopeAccessService->canReadWithinSiteIdentifier($siteIdentifier);
    }

    /**
     * Visibility in the queue: the page must be readable, the item must belong to the current workspace, and without
     * the queue permission (ai.bulk) a person sees only what they requested themselves.
     *
     * @param array<string,mixed> $item
     */
    public function canSee(array $item): bool
    {
        if (!$this->canReadPage((int)$item['page_uid']) || (int)$item['workspace_id'] !== $this->currentWorkspace()) {
            return false;
        }
        // The item shows the finding and the proposed text of its record: it needs current read access to that record
        // (table, language, live version, page), not only to the page it was prepared on (Sol QA-02 review).
        if (!$this->canReadTargetRecord($item)) {
            return false;
        }

        return $this->mayUseBulk() || (int)$item['created_by'] === $this->userUid();
    }

    /**
     * Applying (or approving for the editor) one item. $bulk applies only with the queue permission; a single item
     * also by the person who requested it with the ai.editor right they used to request it. Record and field rights
     * are checked again by the write path itself.
     *
     * @param array<string,mixed> $item
     */
    public function applyRefusal(array $item, bool $bulk): string
    {
        if (!$this->canSee($item)) {
            return 'permission_denied';
        }
        if ((string)$item['origin'] === self::ORIGIN_AGENT) {
            return 'agent_approval_disabled';
        }
        if (!$this->hasLicence((string)$item['site_identifier'])) {
            return 'no_licence';
        }
        $ownRequest = (int)$item['created_by'] === $this->userUid() && (string)$item['origin'] === self::ORIGIN_EDITOR_REQUEST;
        if ($bulk ? !$this->mayUseBulk() : !($this->mayUseBulk() || $ownRequest)) {
            return 'permission_denied';
        }
        if (!$this->canEditTarget($item)) {
            return 'no_edit_access';
        }

        return '';
    }

    /** @param array<string,mixed> $item */
    public function canReadTargetRecord(array $item): bool
    {
        if (!$this->sourceAccessService instanceof LocalFindingSourceAccessInterface) {
            return false;
        }
        if ($this->isAdmin()) {
            return true;
        }
        if ((string)($item['target_kind'] ?? '') === RemediationTargetKind::IMAGE_REFERENCE_ALT) {
            $descriptor = RemediationItemData::descriptor($item);

            return $this->sourceAccessService->canReadRecord((string)($descriptor['parentTable'] ?? ''), (int)($descriptor['parentUid'] ?? 0));
        }

        return $this->sourceAccessService->canReadRecord((string)($item['target_table'] ?? ''), (int)($item['target_uid'] ?? 0));
    }

    /** @param array<string,mixed> $item */
    public function canEditTarget(array $item): bool
    {
        $descriptor = RemediationItemData::descriptor($item);
        $kind = (string)$item['target_kind'];
        if ($kind === RemediationTargetKind::IMAGE_REFERENCE_ALT) {
            return $this->accessControlService->canRemediateImages()
                && $this->recordAccessService->canEditRecordFields((string)($descriptor['parentTable'] ?? ''), (int)($descriptor['parentUid'] ?? 0), [(string)($descriptor['fieldName'] ?? '')])
                && $this->recordAccessService->canEditRecordFields('sys_file_reference', (int)$item['target_uid'], ['alternative', 'tx_a11y_is_decorative']);
        }
        if ($kind === RemediationTargetKind::RICH_TEXT) {
            return $this->recordAccessService->canEditRecordFields((string)$item['target_table'], (int)$item['target_uid'], [(string)$item['target_field']]);
        }

        return false;
    }

    /**
     * Preparing AI suggestions: a single one with the ai.editor right (plus ai.images for images), several at once
     * only with ai.bulk. Returns a refusal code or ''.
     */
    public function prepareRefusal(string $siteIdentifier, string $aiKind, bool $bulk): string
    {
        $state = $this->aiState($siteIdentifier);
        if ($state !== EditorAiAccessService::STATE_READY) {
            return $state;
        }
        if ($bulk && !$this->mayUseBulk()) {
            return 'permission_denied';
        }
        if ($aiKind === 'image_alt' && !$this->mayUseImages()) {
            return 'permission_denied';
        }
        // Link text and iframe titles need the site's AI link/iframe switch, which the editor fix path enforces too:
        // without it the suggestion would only fail later (link_text_disabled).
        if (in_array($aiKind, ['link_text', 'iframe_title'], true)
            && !(bool)($this->editorAiAccessService->editorConfiguration($siteIdentifier)['linkText'] ?? false)) {
            return 'link_text_disabled';
        }

        return '';
    }
}
