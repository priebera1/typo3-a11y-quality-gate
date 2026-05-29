<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use GuzzleHttp\Utils;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
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
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
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

        $issues = $this->issueRepository->findOpenForRecord(
            sourceTable: Tables::TT_CONTENT,
            sourceUid: $recordUid,
            sourceField: $fieldName,
        );
        if ($issues !== []) {
            $context = $this->buildRteContext($recordUid, $fieldName, '', $pageUid);
            if ($context instanceof CheckContext) {
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
            $data = Utils::jsonDecode($body, true) ?? [];
        } catch (\InvalidArgumentException) {
            return $this->badRequestResponse('Invalid JSON body');
        }

        $recordUid = (int)($data['recordUid'] ?? 0);
        $fieldName = trim((string)($data['fieldName'] ?? 'bodytext'));
        $pageUid = (int)($data['pageUid'] ?? 0);
        $html = $this->normalizeLiveValidationHtml((string)($data['html'] ?? ''));

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

                $issues[] = $this->formatLiveViolation($violation, $ctx);
            }
        }

        return $this->jsonResponse([
            'success' => true,
            'live' => true,
            'languageUid' => $ctx->sourceLangUid,
            'issues' => $issues,
        ]);
    }

    private function normalizeLiveValidationHtml(string $html): string
    {
        $html = (string)preg_replace('/<!--.*?-->/s', '', $html);

        // CKEditor normally sends real HTML through editor.getData(). In some source/editing flows,
        // however, the payload can contain escaped HTML fragments wrapped in normal paragraph tags:
        // <p>&lt;img href="nic" /&gt;</p>. The previous fallback did not decode this because
        // the wrapper <p> counted as a real HTML element, so the RTE rules only saw text.
        if ($this->containsEscapedHtmlElement($html) && $this->countActionableHtmlElements($html) === 0) {
            $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($this->countActionableHtmlElements($decoded) > 0) {
                $html = $decoded;
            }
        }

        return $html;
    }

    private function containsEscapedHtmlElement(string $html): bool
    {
        return preg_match('/&lt;\s*(a|img|table|thead|tbody|tr|th|td|button|iframe|svg|h[1-6]|ul|ol|li)\b/i', $html) === 1;
    }

    private function countActionableHtmlElements(string $html): int
    {
        preg_match_all('/<\s*(a|img|table|thead|tbody|tr|th|td|button|iframe|svg|h[1-6]|ul|ol|li)\b/i', $html, $matches);

        return count($matches[0] ?? []);
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
            $data = Utils::jsonDecode($body, true) ?? [];
        } catch (\InvalidArgumentException) {
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
            if (!$this->isIssueForRequestedRecord($issue, $recordUid, $fieldName)) {
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

        $html = $this->normalizeLiveValidationHtml((string)($data['html'] ?? ''));

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
     * @return array<string, mixed>
     */
    private function formatLiveViolation(RuleViolation $violation, CheckContext $ctx): array
    {
        $fingerprint = $violation->fingerprint($ctx);

        return [
            'fingerprint' => 'live:' . $fingerprint,
            'persistedFingerprint' => $fingerprint,
            'ruleId' => $violation->ruleId,
            'severity' => $violation->severity->key(),
            'message' => $violation->message,
            'hint' => $violation->hint,
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

        return [
            'fingerprint' => $row['fingerprint'] ?? '',
            'ruleId' => $row['rule_id'] ?? '',
            'severity' => $severity->key(),
            'message' => $row['message'] ?? '',
            'hint' => $row['hint'] ?? '',
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
