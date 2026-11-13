<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant;

use Priebera\A11yQualityGate\Contract\LocalFindingSourceAccessInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationPageSummaryService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\IssueLocationService;
use Priebera\A11yQualityGate\Service\LocalIssueGuidanceService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Service\ScanPermissionService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Priebera\A11yQualityGate\Utility\RuleAnchorUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The toolbar assistant's view of one context: what AQG analyses (page, content element, file), notes about unsaved
 * edits and workspaces, and whether AI can be used there. It also provides the permission-checked open findings of
 * that context (openIssues()), their freshness and links, which "Ask AQG" answers are built from. It lists no
 * findings by itself and calls no AI provider, the AQG API or the crawler.
 */
final class ToolbarAssistantService
{
    /** A content check older than this is reported as possibly out of date. */
    public const STALE_AFTER_SECONDS = 14 * 86400;
    private const MAX_FILE_REFERENCES = 50;

    public function __construct(
        private readonly IssueRepository $issueRepository,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly AccessControlService $accessControlService,
        private readonly BackendContextService $backendContextService,
        private readonly LocalIssueGuidanceService $localIssueGuidanceService,
        private readonly RuleConfigurationService $ruleConfigurationService,
        private readonly ImageFindingContextResolver $imageFindingContextResolver,
        private readonly ImageFindingVersionTokenService $imageFindingVersionTokenService,
        private readonly ImageAltTextValidator $imageAltTextValidator,
        private readonly AiConfigurationRepositoryInterface $aiConfigurationRepository,
        private readonly UriBuilder $uriBuilder,
        private readonly ConnectionPool $connectionPool,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ?ScanPermissionService $scanPermissionService = null,
        private readonly ?IssueLocationService $issueLocationService = null,
        private readonly ?AiSiteSettingsService $aiSiteSettingsService = null,
        private readonly ?RemediationPageSummaryService $remediationSummary = null,
        private readonly ?RemediationAccessService $remediationAccess = null,
        private readonly ?EditorAiAccessService $editorAiAccessService = null,
        private readonly ?AiActionCostEstimator $aiCostEstimator = null,
        private readonly ?LocalFindingSourceAccessInterface $sourceAccessService = null,
        private readonly ?ScanRepository $scanRepository = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(ToolbarAssistantContext $context): array
    {
        // The dropdown lists no findings by itself: it shows the context and Ask AQG. Findings, explanations and links
        // appear only in answers (AskAqgService), from openIssues() of the same resolved context.
        $siteIdentifier = $context->siteIdentifier;
        if ($siteIdentifier === '' && $context->type === ToolbarAssistantContext::TYPE_FILE) {
            $siteIdentifier = (string)($this->openIssues($context)[0]['site_identifier'] ?? '');
        }
        $view = [
            'context' => $this->describeContext($context),
            'notes' => $this->notes($context),
            'ai' => $this->aiState($siteIdentifier),
            'hasContext' => in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD, ToolbarAssistantContext::TYPE_FILE], true),
        ];
        // "Check saved page now" in the expanded Content scan section: offered for the open page (or the page of the open
        // element) only to users who may scan it (Scan now + edit access to the page). The scan action
        // (ScanAjaxController::scanPageAction) checks both again on every request.
        $view['contentScan'] = ['pageUid' => 0, 'languageUid' => max(0, $context->languageUid)];
        if ($context->pageUid > 0 && in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD], true)
            && $this->scanPermissionService !== null && $this->scanPermissionService->canScanPage($context->pageUid)) {
            $view['contentScan']['pageUid'] = $context->pageUid;
        }
        // Why AI is not used for questions here, once, wherever the context could have used it.
        $view['showAiState'] = $view['hasContext'] && !$view['ai']['ready'] && $view['ai']['state'] !== 'none';

        return $view;
    }

    /**
     * The stored open findings of the context, permission-checked and most severe first: the toolbar's priorities and
     * the "Ask AQG" page answer use exactly this set.
     *
     * @return list<array<string, mixed>>
     */
    public function openIssues(ToolbarAssistantContext $context): array
    {
        return match ($context->type) {
            ToolbarAssistantContext::TYPE_PAGE => $this->openIssuesOfPage($context),
            ToolbarAssistantContext::TYPE_RECORD => array_values(array_filter(
                $this->openIssuesOfPage($context),
                static fn (array $issue): bool => (string)$issue['source_table'] === $context->table
                    && (int)$issue['source_uid'] === $context->recordUid
            )),
            ToolbarAssistantContext::TYPE_FILE => $this->openImageIssuesOfFile($context->fileUid),
            default => [],
        };
    }

    /**
     * The open findings of the context's whole page (also for an open content element): the "whole page" target of Ask
     * AQG's repair workflow. Same access checks as openIssues() of a page context.
     *
     * @return list<array<string, mixed>>
     */
    public function openPageIssues(ToolbarAssistantContext $context): array
    {
        return in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD], true)
            ? $this->openIssuesOfPage($context)
            : [];
    }

    /**
     * Only findings whose source record this user may read now (table, language, live version, page): what the repair
     * workflow lists and prepares. Fails closed without the policy.
     *
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    public function withReadableSources(array $issues): array
    {
        return $this->sourceAccessService instanceof LocalFindingSourceAccessInterface
            ? array_values($this->sourceAccessService->filter($issues))
            : [];
    }

    /**
     * When the saved page was last checked by a content scan (page scan or a site/subtree scan covering it), and
     * whether that is old. Findings are only as current as that check.
     *
     * @return array{checkedAt: int, label: string, stale: bool, known: bool}
     */
    public function freshness(ToolbarAssistantContext $context): array
    {
        $result = ['checkedAt' => 0, 'label' => '', 'stale' => false, 'known' => false];
        if ($this->scanRepository === null || $context->pageUid <= 0 || $context->siteIdentifier === ''
            || !in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD], true)) {
            return $result;
        }
        $result['known'] = true;
        try {
            $finished = max(
                (int)($this->scanRepository->findLastCompletedPageScan($context->siteIdentifier, $context->pageUid, $context->languageUid)['finished_at'] ?? 0),
                (int)($this->scanRepository->findLastCompletedSubtreeScanCoveringPage($context->siteIdentifier, $context->pageUid, $context->languageUid)['finished_at'] ?? 0),
            );
        } catch (\Throwable) {
            return ['checkedAt' => 0, 'label' => '', 'stale' => false, 'known' => false];
        }
        if ($finished > 0) {
            $result['checkedAt'] = $finished;
            $result['label'] = BackendTimeUtility::formatDateTime($finished, 'd.m.Y H:i');
            $result['stale'] = $finished < time() - self::STALE_AFTER_SECONDS;
        }

        return $result;
    }

    public function sourceLabel(string $source): string
    {
        return match ($source) {
            ToolbarFindingGrouper::SOURCE_RENDERED => $this->t('pageDetail.sourceType.rendered', 'Rendered HTML'),
            ToolbarFindingGrouper::SOURCE_STRUCTURED => $this->t('pageDetail.sourceType.structured', 'Structured content'),
            default => $this->t('pageDetail.sourceType.content', 'Content source'),
        };
    }

    public static function tone(Severity $severity): string
    {
        return match ($severity) {
            Severity::Critical => 'critical',
            Severity::Warning => 'warning',
            default => 'info',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function describeContext(ToolbarAssistantContext $context): array
    {
        $description = [
            'type' => $context->type,
            'title' => '',
            'subtitle' => '',
            'pageTitle' => $context->pageTitle,
            'pageUid' => $context->pageUid,
            'languageUid' => $context->languageUid,
            'languageTitle' => $this->languageTitle($context),
            'editing' => $context->isEditingRecord(),
        ];
        switch ($context->type) {
            case ToolbarAssistantContext::TYPE_RECORD:
                $description['title'] = $context->recordTitle !== '' ? $context->recordTitle : sprintf($this->t('toolbar.assistant.context.recordFallback', 'Content element #%d'), $context->recordUid);
                $description['subtitle'] = $context->recordType !== ''
                    ? trim((string)BackendUtility::getProcessedValue(Tables::TT_CONTENT, 'CType', $context->recordType))
                    : '';
                break;
            case ToolbarAssistantContext::TYPE_PAGE:
                $description['title'] = $context->pageTitle !== '' ? $context->pageTitle : sprintf($this->t('toolbar.assistant.context.pageFallback', 'Page #%d'), $context->pageUid);
                break;
            case ToolbarAssistantContext::TYPE_FILE:
                $description['title'] = $context->fileName;
                $description['isImage'] = $context->fileIsImage;
                $description['hasAlternative'] = $context->fileAlternative !== '';
                break;
        }

        return $description;
    }

    /** The site language's title, only when the site has more than one language. */
    private function languageTitle(ToolbarAssistantContext $context): string
    {
        $site = $context->siteIdentifier !== '' ? $this->siteResolutionService->resolveSiteByIdentifier($context->siteIdentifier) : null;
        if ($site === null || count($site->getLanguages()) < 2) {
            return '';
        }
        try {
            return $site->getLanguageById($context->languageUid)->getTitle();
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return list<string>
     */
    private function notes(ToolbarAssistantContext $context): array
    {
        $notes = [];
        if ($context->unsaved) {
            $notes[] = 'unsaved';
        }
        // AQG scans and findings describe the live website, never a workspace draft.
        if ($context->workspace > 0 && in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD, ToolbarAssistantContext::TYPE_FILE], true)) {
            $notes[] = 'workspace';
        }

        return $notes;
    }

    /**
     * Open findings of the context's page and language, enabled rules only, most severe first.
     *
     * @return list<array<string, mixed>>
     */
    private function openIssuesOfPage(ToolbarAssistantContext $context): array
    {
        if ($context->siteIdentifier === '' || $context->pageUid <= 0 || !$this->scopeAccessService->canReadPage($context->pageUid)) {
            return [];
        }
        $issues = array_values(array_filter(
            $this->issueRepository->findAllForPage($context->siteIdentifier, $context->pageUid, $context->languageUid, true),
            fn (array $issue): bool => (int)($issue['status'] ?? -1) === IssueStatus::Open->value
                && $this->ruleConfigurationService->isRuleEnabledForSite($context->siteIdentifier, (string)($issue['rule_id'] ?? ''))
        ));

        return self::bySeverity($issues);
    }

    /**
     * Open alt-text findings of the image references of one file, on pages the user may show. Findings are stored per
     * reference (content element + image field, "ref:<uid>" in the context path), not per file.
     *
     * @return list<array<string, mixed>>
     */
    private function openImageIssuesOfFile(int $fileUid): array
    {
        // Delete and workspace columns as the TCA defines them (TYPO3 14 has no sys_file_reference.deleted).
        $referenceQuery = $this->connectionPool->getQueryBuilderForTable(Tables::SYS_FILE_REFERENCE);
        $referenceQuery->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        $referenceQuery->select('uid', 'tablenames', 'uid_foreign', 'fieldname')
            ->from(Tables::SYS_FILE_REFERENCE)
            ->where($referenceQuery->expr()->eq('uid_local', $referenceQuery->createNamedParameter($fileUid, Connection::PARAM_INT)));
        if (!empty($GLOBALS['TCA'][Tables::SYS_FILE_REFERENCE]['ctrl']['versioningWS'])) {
            $referenceQuery->andWhere($referenceQuery->expr()->eq('t3ver_wsid', $referenceQuery->createNamedParameter(0, Connection::PARAM_INT)));
        }
        $references = $referenceQuery
            ->setMaxResults(self::MAX_FILE_REFERENCES)
            ->executeQuery()
            ->fetchAllAssociative();
        if ($references === []) {
            return [];
        }
        $referencesByUid = [];
        foreach ($references as $reference) {
            $referencesByUid[(int)$reference['uid']] = $reference;
        }
        $backendUser = $this->backendContextService->getBackendUser();

        $issueQuery = $this->connectionPool->getQueryBuilderForTable(Tables::ISSUE);
        $issueQuery->getRestrictions()->removeAll();
        $rows = $issueQuery->select('*')
            ->from(Tables::ISSUE)
            ->where(
                $issueQuery->expr()->in('rule_id', $issueQuery->createNamedParameter(ImageFindingContextResolver::SUPPORTED_RULE_IDS, Connection::PARAM_STR_ARRAY)),
                $issueQuery->expr()->eq('status', $issueQuery->createNamedParameter(IssueStatus::Open->value, Connection::PARAM_INT)),
                $issueQuery->expr()->eq('deleted', $issueQuery->createNamedParameter(0, Connection::PARAM_INT)),
                $issueQuery->expr()->in('source_uid', $issueQuery->createNamedParameter(array_values(array_unique(array_map(static fn (array $row): int => (int)$row['uid_foreign'], $references))), Connection::PARAM_INT_ARRAY)),
            )
            ->executeQuery()
            ->fetchAllAssociative();

        // Only findings of exactly these references (element, field and reference uid), on pages, tables and
        // languages the user may access: the same rights the element's own findings would need.
        $issues = [];
        foreach ($rows as $issue) {
            $referenceUid = preg_match('/(?:^|\\s|>)ref:(\\d+)(?:\\s|$)/', (string)($issue['context_path'] ?? ''), $matches) === 1 ? (int)$matches[1] : 0;
            $reference = $referencesByUid[$referenceUid] ?? null;
            if ($reference === null
                || (string)$reference['tablenames'] !== (string)$issue['source_table']
                || (int)$reference['uid_foreign'] !== (int)$issue['source_uid']
                || (string)$reference['fieldname'] !== (string)$issue['source_field']
                || $backendUser === null
                || !$backendUser->check('tables_select', (string)$issue['source_table'])
                || !$backendUser->checkLanguageAccess(max(0, (int)($issue['source_lang_uid'] ?? 0)))
                || !$this->scopeAccessService->canReadPage((int)($issue['page_uid'] ?? 0))
                || !$this->ruleConfigurationService->isRuleEnabledForSite((string)$issue['site_identifier'], (string)$issue['rule_id'])) {
                continue;
            }
            $issues[] = $issue;
        }
        // The element's current record rights decide, not the page the finding was stored with (moved or deleted
        // elements, Sol QA-02 review). Without the policy nothing is shown.
        $issues = $this->sourceAccessService instanceof LocalFindingSourceAccessInterface ? $this->sourceAccessService->filter($issues) : [];

        return self::bySeverity($issues);
    }

    /**
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    private static function bySeverity(array $issues): array
    {
        usort($issues, static fn (array $a, array $b): int => [(int)$a['severity'], (int)$a['uid']] <=> [(int)$b['severity'], (int)$b['uid']]);

        return $issues;
    }

    /**
     * Whether AI actions can be offered for this site and this user, and why not (one short reason).
     *
     * @return array{state: string, ready: bool, images: bool, textSuggestions: bool, licence: bool, cost: array<string,string>}
     */
    private function aiState(string $siteIdentifier): array
    {
        // The user-aware state (licence, the user's ai.editor switch and module access, then the site's readiness):
        // the assistant offers AI only to users who may use it, never only because the site is ready.
        $state = $siteIdentifier !== '' && $this->editorAiAccessService !== null
            ? $this->editorAiAccessService->state($siteIdentifier)
            : 'none';
        $ready = $state === EditorAiAccessService::STATE_READY;
        $licence = $siteIdentifier !== '' && $this->remediationAccess !== null && $this->remediationAccess->hasLicence($siteIdentifier);

        return [
            'state' => $state,
            'ready' => $ready,
            'licence' => $licence,
            'images' => $ready
                && ($this->editorAiAccessService?->userAllows(EditorAiAccessService::FLAG_IMAGES) ?? false)
                && ($this->aiSiteSettingsService?->get($siteIdentifier)->allowImageInput ?? true),
            'textSuggestions' => $ready && $this->aiConfigurationRepository->isLinkTextSuggestionsEnabled($siteIdentifier),
            'cost' => $ready ? $this->costLabels($siteIdentifier) : [],
        ];
    }

    /**
     * @param array<string, mixed> $issue
     */
    public function pageDetailUrl(array $issue): string
    {
        $pageUid = (int)($issue['page_uid'] ?? 0);
        if ($pageUid <= 0) {
            return '';
        }

        return $this->uri('web_a11y.pageDetail', [
            'pageUid' => $pageUid,
            'id' => $pageUid,
            'site' => (string)$issue['site_identifier'],
            'language' => max(0, (int)($issue['source_lang_uid'] ?? 0)),
            'rule' => (string)$issue['rule_id'],
        ]) . '#' . RuleAnchorUtility::anchorId((string)$issue['rule_id']);
    }

    public function editUrl(string $table, int $uid): string
    {
        return $this->uri('record_edit', ['edit' => [$table => [$uid => 'edit']]]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function uri(string $route, array $parameters): string
    {
        try {
            return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * @return array<string, string>
     */
    private function costLabels(string $siteIdentifier): array
    {
        if ($this->aiCostEstimator === null) {
            return [];
        }
        $separator = $this->backendContextService->getCurrentLanguageCode() === 'de' ? ',' : '.';
        $labels = [];
        foreach ([
            'explain' => AiActionCostEstimator::ACTION_EXPLAIN,
            'fixText' => AiActionCostEstimator::ACTION_FIX_TEXT,
            'fixImage' => AiActionCostEstimator::ACTION_FIX_IMAGE,
            'linkText' => AiActionCostEstimator::ACTION_LINK_TEXT,
            'iframeTitle' => AiActionCostEstimator::ACTION_IFRAME_TITLE,
            'ask' => AiActionCostEstimator::ACTION_ASSISTANT,
        ] as $key => $action) {
            try {
                $micro = $this->aiCostEstimator->maxCostMicro($siteIdentifier, $action);
            } catch (\Throwable) {
                $micro = null;
            }
            if ($micro !== null) {
                $labels[$key] = sprintf($this->t('ai.cost.max', '<$%s'), AiActionCostEstimator::formatUpperBound($micro, $separator));
            }
        }

        return $labels;
    }

    private function t(string $key, string $fallback): string
    {
        $translated = $this->backendContextService->translate($key);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }
}
