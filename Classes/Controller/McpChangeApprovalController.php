<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpScopes;
use Priebera\A11yQualityGate\Mcp\McpUserContext;
use Priebera\A11yQualityGate\Mcp\Remediation\McpChangeApprovalRepository;
use Priebera\A11yQualityGate\Mcp\Remediation\McpChangePresentation;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationException;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationRuleTitle;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetKind;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessFactory;
use TYPO3\CMS\Backend\Security\SudoMode\Access\AccessStorage;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Approval of one exact agent change (remediations:apply-approved, post-3.0.0) — the only place where such a change is
 * approved. An AQG module route behind TYPO3's own login (MFA and password policies unchanged), opened by the person
 * from the link the agent received.
 *
 * Only the TYPO3 user the agent acts for decides, in their own backend session; the decision is a POST to a module route
 * (route token: no cross-site request can make it). The page never shows what the agent claims: it computes the write
 * from the stored record now (RemediationWorkflowService::directApplyPreview(), the AI Fixes engine) and shows the record,
 * field, language, workspace, the problem, the exact BEFORE and AFTER values and the limitations. Displaying it records
 * the review evidence AI Fixes requires (markViewed). The decision binds exactly what was shown (`binding`): when the
 * record, the suggestion or the item changed in between, nothing is approved and the request becomes stale.
 */
#[AsController]
final class McpChangeApprovalController extends AbstractBackendModuleController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly McpChangeApprovalRepository $approvals,
        private readonly RemediationWorkflowService $workflow,
        private readonly McpAuditRepository $auditRepository,
        private readonly RuleMetadataPresentationService $ruleMetadata,
        private readonly SiteFinder $siteFinder,
        private readonly ConnectionPool $connectionPool,
        private readonly FrontendPageUrlService $frontendPageUrls,
        private readonly ?AccessFactory $sudoAccessFactory = null,
        private readonly ?AccessStorage $sudoAccessStorage = null,
    ) {
        parent::__construct($moduleTemplateFactory, $uriBuilder, $iconFactory, $backendContextService, $siteResolutionService, $requestParameterService);
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $ref = strtolower((string)($request->getQueryParams()['approval'] ?? ''));
        [$state, $row, $user] = $this->load($ref);
        if ($state !== 'pending') {
            return $this->render($request, ['state' => $state, 'approval' => $row !== null ? $this->decidedView($row) : null]);
        }
        $preview = $this->currentPreview($row, $user);
        if ($preview === null) {
            $this->approvals->move((int)$row['uid'], McpChangeApprovalRepository::PENDING, McpChangeApprovalRepository::STALE, 'changed_before_approval');
            $this->audit($user, $row, 'stale', 'changed_before_approval');

            return $this->render($request, ['state' => 'stale']);
        }
        // No write on GET: the review evidence AI Fixes requires is recorded with the person's decision (POST, route
        // token, password step-up), never by merely loading this link.
        return $this->render($request, [
            'state' => 'pending',
            'approvalRef' => (string)$row['approval_ref'],
            'binding' => (string)$row['binding_hash'],
            'decideUrl' => $this->buildRouteUrl('web_a11y.mcpChangeApprovalDecide'),
            'rejectUrl' => $this->buildRouteUrl('web_a11y.mcpChangeApprovalReject'),
            'change' => $this->changeView($row, $preview),
        ]);
    }

    public function decideAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return $this->decision($request, $body, ($body['decision'] ?? '') === 'approve');
    }

    /**
     * Rejecting writes nothing and gives the agent nothing, so it does not ask for the password again (its own route,
     * without sudo mode; still POST-only behind the route token, still only this person's own request).
     */
    public function rejectAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return $this->decision($request, $body, false);
    }

    /** @param array<array-key, mixed> $body */
    private function decision(ServerRequestInterface $request, array $body, bool $approve): ResponseInterface
    {
        $ref = strtolower((string)($body['approval'] ?? ''));
        [$state, $row, $user] = $this->load($ref);
        if ($state !== 'pending') {
            return $this->render($request, ['state' => $state, 'approval' => $row !== null ? $this->decidedView($row) : null]);
        }
        $now = time();
        if ($approve) {
            // Exactly what the page showed, still true now: the posted binding, the stored one and the one computed from
            // the record this instant are the same, and this person displayed this proposal version.
            $preview = $this->currentPreview($row, $user);
            if ($preview === null || !hash_equals((string)$row['binding_hash'], (string)($body['binding'] ?? ''))) {
                $this->approvals->move((int)$row['uid'], McpChangeApprovalRepository::PENDING, McpChangeApprovalRepository::STALE, 'changed_before_approval');
                $this->audit($user, $row, 'stale', 'changed_before_approval');

                return $this->render($request, ['state' => 'stale']);
            }
            try {
                // The person submitted the page that displayed this exact proposal version: AI Fixes' review evidence.
                $this->workflow->markViewed((int)$row['item_uid'], (int)$row['proposal_version']);
            } catch (RemediationException) {
                return $this->render($request, ['state' => 'stale']);
            }
        }
        $this->endStepUp($request);
        if (!$this->approvals->decide((int)$row['uid'], (int)$user->user['uid'], (string)$row['binding_hash'], $approve, $now)) {
            $fresh = $this->approvals->findByRef($ref);

            return $this->render($request, ['state' => 'decided', 'approval' => $fresh !== null ? $this->decidedView($fresh) : null]);
        }
        $this->audit($user, $row, $approve ? 'approved' : 'rejected', '');

        return $this->render($request, [
            'state' => $approve ? 'approved' : 'rejected',
            'approval' => $this->decidedView($this->approvals->findByRef($ref) ?? $row),
        ]);
    }

    /**
     * Every decision needs its own password confirmation: the sudo-mode grant this decision used is dropped, so a second
     * request (also one an agent driving this browser created) cannot ride on it within the grant's lifetime.
     */
    private function endStepUp(ServerRequestInterface $request): void
    {
        if ($this->sudoAccessFactory === null || $this->sudoAccessStorage === null || $request->getAttribute('route') === null) {
            return;
        }
        try {
            $subject = $this->sudoAccessFactory->buildRouteAccessSubject($request);
            foreach ($this->sudoAccessStorage->findGrantsBySubject($subject) as $grant) {
                $this->sudoAccessStorage->removeGrant($grant);
            }
        } catch (\Throwable) {
            // No sudo-mode context (Development context, CLI, tests calling the action directly): nothing to drop.
        }
    }

    /**
     * @return array{0: string, 1: array<string, mixed>|null, 2: BackendUserAuthentication|null}
     */
    private function load(string $ref): array
    {
        $user = $this->backendContextService->getBackendUser();
        if (!McpScopes::approvedChangesEnabled()) {
            return ['notAvailable', null, null];
        }
        $row = $this->approvals->findByRef($ref);
        // Only the person the agent acts for sees or decides the request; any other answer is the same "not found".
        if (!$user instanceof BackendUserAuthentication || $row === null || (int)$row['be_user_uid'] !== (int)($user->user['uid'] ?? 0)) {
            return ['notFound', null, null];
        }
        // The person themself, not an administrator who switched to their account ("Switch to user").
        if (!McpUserContext::mayConnectAgents($user) || $user->getOriginalUserIdWhenInSwitchUserMode() !== null) {
            return ['notAllowed', null, null];
        }
        $row = $this->approvals->expireIfDue($row, time());
        if ((string)$row['status'] !== McpChangeApprovalRepository::PENDING) {
            return ['decided', $row, $user];
        }
        if ((int)($user->workspace ?? 0) !== 0) {
            return ['switchToLive', $row, $user];
        }

        return ['pending', $row, $user];
    }

    /**
     * directApplyPreview() for this person now, when it is still exactly the requested binding; otherwise null.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function currentPreview(array $row, BackendUserAuthentication $user): ?array
    {
        try {
            $preview = $this->workflow->directApplyPreview((int)$row['item_uid']);
        } catch (RemediationException) {
            return null;
        }
        if ($preview['state'] !== 'ok') {
            return null;
        }
        $binding = McpChangeApprovalRepository::bindingOf($preview, (int)$user->user['uid'], (string)$row['token_id']);

        return hash_equals((string)$row['binding_hash'], McpChangeApprovalRepository::bindingHash($binding))
            && hash_equals((string)$row['binding_hash'], McpChangeApprovalRepository::bindingHash($row)) ? $preview : null;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    private function changeView(array $row, array $preview): array
    {
        $image = (string)$preview['kind'] === RemediationTargetKind::IMAGE_REFERENCE_ALT;
        $table = $image ? Tables::SYS_FILE_REFERENCE : (string)$preview['table'];
        $record = BackendUtility::getRecord($table, (int)$preview['uid']);
        $page = BackendUtility::getRecord('pages', (int)$preview['pageUid']);
        $fieldLabel = (string)($GLOBALS['TCA'][$table]['columns'][(string)$preview['field']]['label'] ?? $preview['field']);
        $languageUid = (int)$preview['languageUid'];
        $site = null;
        try {
            $site = $this->siteFinder->getSiteByIdentifier((string)$row['site_identifier']);
        } catch (\Throwable) {
        }
        $languageTitle = '';
        try {
            $languageTitle = $site?->getLanguageById(max(0, $languageUid))->getTitle() ?? '';
        } catch (\Throwable) {
        }
        if ($languageTitle === '') {
            $languageTitle = $languageUid === -1
                ? $this->translateWithFallback('mcp.changeApproval.allLanguages', 'All languages')
                : '#' . $languageUid;
        }
        $pageUrl = '';
        if ($site !== null) {
            try {
                $pageUrl = $this->frontendPageUrls->resolveForPage($site, (int)$preview['pageUid'], max(0, $languageUid));
            } catch (\Throwable) {
            }
        }
        $lang = $GLOBALS['LANG'] ?? null;
        $change = $image ? null : McpChangePresentation::change((string)$preview['before'], (string)$preview['after']);

        return [
            'clientName' => (string)$row['client_name'],
            'siteIdentifier' => (string)$row['site_identifier'],
            'pageTitle' => is_array($page) ? (string)$page['title'] : '',
            'pageUid' => (int)$preview['pageUid'],
            'pageUrl' => preg_match('#^https?://#i', $pageUrl) === 1 ? $pageUrl : '',
            'recordTitle' => is_array($record) ? BackendUtility::getRecordTitle($table, $record) : '',
            'table' => $table,
            'tableLabel' => $lang !== null ? $lang->sL((string)($GLOBALS['TCA'][$table]['ctrl']['title'] ?? $table)) : $table,
            'uid' => (int)$preview['uid'],
            'field' => (string)$preview['field'],
            'fieldLabel' => $lang !== null ? $lang->sL($fieldLabel) : $fieldLabel,
            'languageTitle' => $languageTitle,
            'languageUid' => $languageUid,
            'workspace' => $this->translateWithFallback('mcp.changeApproval.workspaceLive', 'Live'),
            'ruleId' => (string)$preview['ruleId'],
            'ruleTitle' => RemediationRuleTitle::plain($this->ruleMetadata, (string)$preview['ruleId'], $this->getBackendLanguageCode(), (string)$preview['ruleId']),
            'value' => (string)$preview['value'],
            'valueLabel' => $this->valueLabel($image, $change),
            'diff' => $change,
            'imageBefore' => $image ? $this->imageBefore((int)$preview['uid'], (string)$preview['before']) : null,
            'before' => (string)$preview['before'],
            'after' => (string)$preview['after'],
            'explanation' => (string)$preview['explanation'],
            'recordHidden' => (bool)$preview['recordHidden'],
            'image' => $image,
            'requestedAt' => (int)$row['crdate'],
            'expiresAt' => (int)$row['expires_at'],
            'validityMinutes' => (int)(McpChangeApprovalRepository::APPROVED_TTL / 60),
        ];
    }

    /**
     * What the new value is, in the person's words: alternative text, accessible name, visible text or an attribute.
     *
     * @param array<string, mixed>|null $change McpChangePresentation::change()
     */
    private function valueLabel(bool $image, ?array $change): string
    {
        $attribute = (string)($change['attribute'] ?? '');

        return match (true) {
            $image, $attribute === 'alt' => $this->translateWithFallback('mcp.changeApproval.value.alt', 'New alternative text (alt):'),
            $attribute === 'aria-label' => $this->translateWithFallback('mcp.changeApproval.value.ariaLabel', 'New accessible name (aria-label):'),
            $attribute === 'title' => $this->translateWithFallback('mcp.changeApproval.value.title', 'New title (title):'),
            $attribute !== '' => sprintf($this->translateWithFallback('mcp.changeApproval.value.attribute', 'New value of the %s attribute:'), $attribute),
            !(bool)($change['inTag'] ?? true) => $this->translateWithFallback('mcp.changeApproval.value.text', 'New visible text:'),
            default => $this->translateWithFallback('mcp.changeApproval.value.other', 'New value:'),
        };
    }

    /**
     * BEFORE of an image reference: its own alternative text, or, when the reference has none of its own (NULL), the
     * file's alternative text that visitors get now. Display only.
     *
     * @return array{value: string, inherited: bool}
     */
    private function imageBefore(int $referenceUid, string $before): array
    {
        try {
            $reference = $this->connectionPool->getConnectionForTable(Tables::SYS_FILE_REFERENCE)->select(
                ['alternative', 'uid_local', 'sys_language_uid'],
                Tables::SYS_FILE_REFERENCE,
                ['uid' => $referenceUid],
            )->fetchAssociative();
            if (!is_array($reference) || $reference['alternative'] !== null) {
                return ['value' => $before, 'inherited' => false];
            }
            $metadata = $this->connectionPool->getConnectionForTable('sys_file_metadata');
            $fileAlternative = false;
            foreach (array_unique([max(0, (int)$reference['sys_language_uid']), 0]) as $language) {
                $fileAlternative = $metadata->select(['alternative'], 'sys_file_metadata', ['file' => (int)$reference['uid_local'], 'sys_language_uid' => $language])->fetchOne();
                if (is_string($fileAlternative) && $fileAlternative !== '') {
                    break;
                }
            }

            return ['value' => is_string($fileAlternative) ? $fileAlternative : '', 'inherited' => true];
        } catch (\Throwable) {
            return ['value' => $before, 'inherited' => false];
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decidedView(array $row): array
    {
        return [
            'status' => (string)$row['status'],
            'statusLabel' => $this->translateWithFallback('mcp.changeApproval.status.' . (string)$row['status'], (string)$row['status']),
            'reason' => (string)$row['status_reason'],
            'clientName' => (string)$row['client_name'],
            'expiresAt' => (int)$row['expires_at'],
            'validityMinutes' => (int)(McpChangeApprovalRepository::APPROVED_TTL / 60),
        ];
    }

    /** @param array<string, mixed> $row */
    private function audit(BackendUserAuthentication $user, array $row, string $result, string $reason): void
    {
        $this->auditRepository->record('change_approval', $result, 'backend', [
            'beUserUid' => (int)$user->user['uid'],
            'beUserName' => (string)($user->user['username'] ?? ''),
            'clientName' => (string)$row['client_name'],
            'siteIdentifier' => (string)$row['site_identifier'],
            'target' => sprintf('approval:%s item:%d %s:%d.%s lang:%d ws:%d', substr((string)$row['approval_ref'], 0, 12), (int)$row['item_uid'], (string)$row['target_table'], (int)$row['target_uid'], (string)$row['target_field'], (int)$row['language_uid'], (int)$row['workspace_id']),
            'reason' => $reason,
        ]);
    }

    /** @param array<string, mixed> $variables */
    private function render(ServerRequestInterface $request, array $variables): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);
        $moduleTemplate->setTitle($this->translateWithFallback('mcp.changeApproval.pageTitle', 'Approve an agent change'));
        $moduleTemplate->assignMultiple($variables);

        return $moduleTemplate->renderResponse('Mcp/ChangeApproval');
    }
}
