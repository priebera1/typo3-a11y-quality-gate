<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Contract\LocalFindingSourceAccessInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTargetResolver;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationRuleTitle;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\LiveValidationHtml;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

#[AsController]
final class IssueApiController extends AbstractApiController
{
    private const MAX_RTE_HTML_SIZE = 500_000;

    public function __construct(
        private readonly IssueRepository $issueRepository,
        private readonly RuleRegistry $ruleRegistry,
        private readonly RuleConfigurationService $ruleConfigurationService,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        BackendUserService $backendUserService,
        private readonly ?RuleMetadataPresentationService $ruleMetadataPresentationService = null,
        private readonly ?BackendLanguageService $backendLanguageService = null,
        private readonly ?EditorFindingTargetResolver $editorFindingTargetResolver = null,
        private readonly ?AccessControlService $accessControlService = null,
        private readonly ?LocalFindingSourceAccessInterface $sourceAccessService = null,
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
    }

    /**
     * Stored findings of a record only while the user may read that record now (language, live version, table, page):
     * edit access to the page alone does not open a record of a refused language (Sol QA-02 review).
     *
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    private function readableIssues(array $issues): array
    {
        return $this->sourceAccessService instanceof LocalFindingSourceAccessInterface ? $this->sourceAccessService->filter($issues) : [];
    }

    public function issuesAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        $params = $request->getQueryParams();
        $recordUid = (int)($params['recordUid'] ?? 0);
        $fieldName = trim((string)($params['fieldName'] ?? 'bodytext'));
        $pageUid = (int)($params['pageUid'] ?? 0);

        if ($recordUid <= 0) {
            return $this->badRequestResponse('Missing recordUid');
        }

        if (!$this->canAccessRteRecord($recordUid, $pageUid)) {
            return $this->forbiddenResponse();
        }

        if (!$this->backendRecordAccessService->recordExists(Tables::TT_CONTENT, $recordUid)) {
            return $this->jsonResponse([
                'success' => true,
                'issues' => [],
                'pendingRecord' => true,
            ]);
        }

        $issues = $this->readableIssues($this->issueRepository->findOpenForRecord(
            sourceTable: Tables::TT_CONTENT,
            sourceUid: $recordUid,
            sourceField: $fieldName,
        ));
        if ($issues !== []) {
            $context = $this->buildRteContext($recordUid, $fieldName, '', $pageUid);
            if ($context instanceof CheckContext) {
                // D-03: only findings of the record's current site; rows of a renamed site are history, not open work.
                $issues = array_values(array_filter(
                    $issues,
                    static fn (array $row): bool => $context->siteIdentifier === ''
                        || (string)($row['site_identifier'] ?? '') === $context->siteIdentifier
                ));
                $issues = array_values(array_filter(
                    $issues,
                    fn (array $row): bool => $this->ruleConfigurationService->isRuleEnabledForSite(
                        $context->siteIdentifier,
                        (string)($row['rule_id'] ?? '')
                    )
                ));
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'issues' => array_map(
                fn(array $row): array => $this->formatIssue($row),
                $issues
            ),
        ]);
    }

    public function validateRteAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Method not allowed',
            ], 405);
        }

        $body = (string)$request->getBody();

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\JsonException) {
            return $this->badRequestResponse('Invalid JSON body');
        }

        $recordUid = (int)($data['recordUid'] ?? 0);
        $fieldName = trim((string)($data['fieldName'] ?? 'bodytext'));
        $pageUid = (int)($data['pageUid'] ?? 0);
        $normalized = LiveValidationHtml::normalize((string)($data['html'] ?? ''));
        $html = $normalized['html'];

        if ($recordUid <= 0) {
            return $this->badRequestResponse('Missing recordUid');
        }

        if ($fieldName === '') {
            return $this->badRequestResponse('Missing fieldName');
        }

        if (!$this->canAccessRteRecord($recordUid, $pageUid)) {
            return $this->forbiddenResponse();
        }

        if (strlen($html) > self::MAX_RTE_HTML_SIZE) {
            return $this->badRequestResponse('HTML too large');
        }

        if (trim($html) === '') {
            return $this->jsonResponse([
                'success' => true,
                'live' => true,
                'issues' => [],
            ]);
        }

        $ctx = $this->buildRteContext($recordUid, $fieldName, $html, $pageUid);
        if (!$ctx instanceof CheckContext) {
            return $this->notFoundResponse('RTE record not found');
        }

        $issues = [];
        foreach ($this->ruleRegistry->getRulesFor($ctx) as $rule) {
            if (!str_starts_with($rule->getRuleId(), 'rte.')) {
                continue;
            }

            if (!$this->ruleConfigurationService->isRuleEnabledForSite($ctx->siteIdentifier, $rule->getRuleId())) {
                continue;
            }

            foreach ($rule->check($ctx) as $violation) {
                $fingerprint = $violation->fingerprint($ctx);
                $existing = $this->issueRepository->findByFingerprintForSite($fingerprint, $ctx->siteIdentifier);
                if ($existing !== null && IssueStatus::fromInt((int)$existing['status'])->isProtected()) {
                    continue;
                }

                $issue = $this->formatLiveViolation($violation, $ctx);
                // Editor AI actions need the exact element; decoded (escaped) markup is not the editor's source.
                if (!$normalized['decoded'] && $this->editorFindingTargetResolver !== null && EditorAiRuleCatalog::supports($violation->ruleId)) {
                    $target = $this->editorFindingTargetResolver->resolve($html, $violation);
                    if ($target !== null) {
                        $issue['aiTarget'] = $target->toArray();
                    }
                }
                $issues[] = $issue;
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'live' => true,
            'languageUid' => $ctx->sourceLangUid,
            // Lets the editor date a rule-based change by the server clock (editor AI "Saved" check).
            'serverTime' => time(),
            'issues' => $issues,
        ]);
    }

    public function ignoreAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Method not allowed',
            ], 405);
        }

        $body = (string)$request->getBody();

        try {
            $data = json_decode($body, true, 512, JSON_THROW_ON_ERROR) ?? [];
        } catch (\JsonException) {
            return $this->badRequestResponse('Invalid JSON body');
        }

        $fingerprint = trim((string)($data['fingerprint'] ?? ''));
        $reason = trim((string)($data['reason'] ?? 'Ignored via editor'));

        if ($fingerprint === '') {
            return $this->badRequestResponse('Missing fingerprint');
        }

        if ($reason === '') {
            $reason = 'Ignored via CKEditor';
        }

        $user = $this->getBackendUserSnapshot();
        $isLiveIssue = str_starts_with($fingerprint, 'live:');
        $storedFingerprint = $isLiveIssue ? substr($fingerprint, 5) : $fingerprint;
        $recordUid = (int)($data['recordUid'] ?? 0);
        $fieldName = trim((string)($data['fieldName'] ?? 'bodytext'));
        $pageUid = (int)($data['pageUid'] ?? 0);
        $siteIdentifier = trim((string)($data['siteIdentifier'] ?? ''));

        if ($recordUid <= 0) {
            return $this->badRequestResponse('Missing recordUid');
        }

        // Same rule as Ignore in Page Detail: the editRecord capability, then edit access to the record.
        $capabilityResponse = $this->ensureBackendUserAccess(
            $this->accessControlService ?? new AccessControlService($this->connectionPool),
            'editRecord'
        );
        if ($capabilityResponse !== null) {
            return $capabilityResponse;
        }

        if (!$this->canAccessRteRecord($recordUid, $pageUid)) {
            return $this->forbiddenResponse();
        }

        if ($siteIdentifier === '' && $recordUid > 0 && $fieldName !== '') {
            $contextForSite = $this->buildRteContext($recordUid, $fieldName, '', $pageUid);
            if ($contextForSite instanceof CheckContext) {
                $siteIdentifier = $contextForSite->siteIdentifier;
            }
        }

        $issue = $this->issueRepository->findByFingerprintPublic(
            $storedFingerprint,
            $siteIdentifier !== '' ? $siteIdentifier : null
        );
        if ($issue !== null) {
            if (!$this->isIssueForRequestedRecord($issue, $recordUid, $fieldName) || $this->readableIssues([$issue]) === []) {
                return $this->forbiddenResponse();
            }

            $status = IssueStatus::fromInt((int)$issue['status']);
            if ($status->isProtected()) {
                return $this->jsonResponse([
                    'success' => false,
                    'error' => 'Issue is already ignored or muted',
                ], 409);
            }

            $this->issueRepository->ignore(
                issueUid: (int)$issue['uid'],
                reason: $reason,
                backendUserUid: $user['uid'],
                backendUserName: $user['name'],
                backendUsername: $user['username'],
            );

            return $this->jsonResponse([
                'success' => true,
                'fingerprint' => $fingerprint,
                'ignoredBy' => $user,
            ]);
        }

        if (!$isLiveIssue) {
            return $this->notFoundResponse('Issue not found');
        }

        $normalized = LiveValidationHtml::normalize((string)($data['html'] ?? ''));
        $html = $normalized['html'];

        if ($recordUid <= 0 || $fieldName === '') {
            return $this->badRequestResponse('Missing live issue context');
        }

        if (strlen($html) > self::MAX_RTE_HTML_SIZE) {
            return $this->badRequestResponse('HTML too large');
        }

        $ctx = $this->buildRteContext($recordUid, $fieldName, $html, $pageUid);
        if (!$ctx instanceof CheckContext) {
            return $this->notFoundResponse('RTE record not found');
        }

        $matchedViolation = null;
        foreach ($this->ruleRegistry->getRulesFor($ctx) as $rule) {
            if (!str_starts_with($rule->getRuleId(), 'rte.')) {
                continue;
            }

            foreach ($rule->check($ctx) as $violation) {
                if ($violation->fingerprint($ctx) === $storedFingerprint) {
                    $matchedViolation = $violation;
                    break 2;
                }
            }
        }

        if (!$matchedViolation instanceof RuleViolation) {
            return $this->notFoundResponse('Live issue no longer exists');
        }

        $issueUid = $this->issueRepository->createIgnoredFromViolation(
            violation: $matchedViolation,
            ctx: $ctx,
            reason: $reason,
            backendUserUid: $user['uid'],
            backendUserName: $user['name'],
            backendUsername: $user['username'],
        );

        return $this->jsonResponse([
            'success' => true,
            'fingerprint' => $fingerprint,
            'issueUid' => $issueUid,
            'ignoredBy' => $user,
        ]);
    }

    private function isIssueForRequestedRecord(array $issue, int $recordUid, string $fieldName): bool
    {
        if ((string)($issue['source_table'] ?? '') !== Tables::TT_CONTENT) {
            return false;
        }

        if ((int)($issue['source_uid'] ?? 0) !== $recordUid) {
            return false;
        }

        $sourceField = trim((string)($issue['source_field'] ?? ''));
        return $sourceField === '' || $sourceField === $fieldName;
    }

    private function canAccessRteRecord(int $recordUid, int $fallbackPageUid = 0): bool
    {
        if ($recordUid <= 0) {
            return false;
        }

        if ($this->backendRecordAccessService->canEditRecord(Tables::TT_CONTENT, $recordUid)) {
            return true;
        }

        if ($fallbackPageUid <= 0) {
            return false;
        }

        if ($this->backendRecordAccessService->recordExists(Tables::TT_CONTENT, $recordUid)) {
            return false;
        }

        return $this->backendRecordAccessService->canEditContentOnPage($fallbackPageUid);
    }

    private function buildRteContext(int $recordUid, string $fieldName, string $html, int $fallbackPageUid = 0): ?CheckContext
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::TT_CONTENT);
        $record = $qb
            ->select('uid', 'pid', 'sys_language_uid', 'CType')
            ->from(Tables::TT_CONTENT)
            ->where(
                $qb->expr()->eq('uid', $qb->createNamedParameter($recordUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        if (!$record && $fallbackPageUid <= 0) {
            return null;
        }

        $pageUid = $record ? (int)($record['pid'] ?? 0) : $fallbackPageUid;
        if ($pageUid <= 0 && $fallbackPageUid > 0) {
            $pageUid = $fallbackPageUid;
        }

        $siteIdentifier = 'ckeditor-live';

        if ($pageUid > 0) {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierForPageId(
                $pageUid,
                'page-' . $pageUid
            );
        }

        return new CheckContext(
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            sourceLangUid: $record ? (int)($record['sys_language_uid'] ?? 0) : 0,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: $recordUid,
            sourceField: $fieldName,
            content: $html,
            cType: $record ? (string)($record['CType'] ?? '') : '',
            contextPath: sprintf(
                'Page:%d > %s:%d > %s',
                $pageUid,
                Tables::TT_CONTENT,
                $recordUid,
                $fieldName
            ),
        );
    }

    /**
     * @return array{uid:int,username:string,name:string}
     */
    private function getBackendUserSnapshot(): array
    {
        return $this->backendUserService->getBackendUserSnapshot();
    }

    /**
     * The plain rule name ("Link text is not descriptive") that titles a finding in the editor popover, line panel and
     * review dialog; the stored message ("<h3> element has no visible text.") stays the description. Empty without the
     * presentation service (the editor then falls back to the message).
     */
    private function ruleTitle(string $ruleId): string
    {
        if ($ruleId === '' || $this->ruleMetadataPresentationService === null) {
            return '';
        }

        // The same plain rule name as Page Detail, the AI fixes queue and the plan (Atlas audit F-11): the grouped
        // presentation title ("Links without discernible text") is not a rule name.
        return RemediationRuleTitle::plain(
            $this->ruleMetadataPresentationService,
            $ruleId,
            $this->backendLanguageService?->getCurrentLanguageCode() ?? 'en'
        );
    }

    /**
     * Rule messages are English. Other backend languages get the translated rule title and fix
     * where locallang.xlf has one; otherwise the original message and hint stay.
     *
     * @return array{0: string, 1: string}
     */
    private function localizeRuleCopy(string $ruleId, string $message, string $hint): array
    {
        $language = $this->backendLanguageService?->getCurrentLanguageCode() ?? 'en';
        if ($language === 'en' || $ruleId === '' || $this->ruleMetadataPresentationService === null) {
            return [$message, $hint];
        }

        $presentation = $this->ruleMetadataPresentationService->present(['rule_id' => $ruleId], $language);
        $title = trim((string)($presentation['localizedTitle'] ?? ''));
        $fix = trim((string)($presentation['localizedHowToFix'] ?? ''));

        return [$title !== '' ? $title : $message, $fix !== '' ? $fix : $hint];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatLiveViolation(RuleViolation $violation, CheckContext $ctx): array
    {
        $fingerprint = $violation->fingerprint($ctx);
        [$message, $hint] = $this->localizeRuleCopy((string)$violation->ruleId, (string)$violation->message, (string)$violation->hint);

        return [
            'fingerprint' => 'live:' . $fingerprint,
            'persistedFingerprint' => $fingerprint,
            'ruleId' => $violation->ruleId,
            'ruleTitle' => $this->ruleTitle((string)$violation->ruleId),
            'severity' => $violation->severity->key(),
            'message' => $message,
            'hint' => $hint,
            'snippet' => $violation->contextSnippet,
            'contextPath' => $violation->contextPath,
            'status' => 0,
            'live' => true,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatIssue(array $row): array
    {
        $severity = Severity::fromInt((int)$row['severity']);
        [$message, $hint] = $this->localizeRuleCopy(
            (string)($row['rule_id'] ?? ''),
            (string)($row['message'] ?? ''),
            (string)($row['hint'] ?? '')
        );

        return [
            'fingerprint' => $row['fingerprint'] ?? '',
            'ruleId' => $row['rule_id'] ?? '',
            'ruleTitle' => $this->ruleTitle((string)($row['rule_id'] ?? '')),
            'severity' => $severity->key(),
            'message' => $message,
            'hint' => $hint,
            'snippet' => $row['context_snippet'] ?? '',
            'contextPath' => $row['context_path'] ?? '',
            'status' => (int)($row['status'] ?? 0),
            'ignoredBy' => (int)($row['ignored_by'] ?? 0),
            'ignoredByName' => $row['ignored_by_name'] ?? '',
            'ignoredByUsername' => $row['ignored_by_username'] ?? '',
            'ignoredAt' => (int)($row['ignored_at'] ?? 0),
            'resolvedBy' => (int)($row['resolved_by'] ?? 0),
            'resolvedByName' => $row['resolved_by_name'] ?? '',
            'resolvedByUsername' => $row['resolved_by_username'] ?? '',
            'resolvedAt' => (int)($row['resolved_at'] ?? 0),
        ];
    }
}
