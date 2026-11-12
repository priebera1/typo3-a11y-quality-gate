<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\SourceStateRepository;
use Priebera\A11yQualityGate\Exception\ScanCancelledException;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Rendered\RenderedPageScanner;
use Priebera\A11yQualityGate\Rendered\RenderedPageTypeGuard;
use Priebera\A11yQualityGate\Service\FieldConfigurationBootstrapService;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Site\SiteFinder;

final class ScanOrchestrator
{
    /**
     * Rendered checks are executed by the rendered HTML analyzer instead of the
     * local RuleRegistry. Keep their rule IDs here so a completed rendered scan
     * can still safely resolve stale rendered findings without allowing failed
     * or skipped local rules to resolve unrelated issues.
     *
     * @var string[]
     */
    private const RENDERED_RULE_IDS = [
        'rendered.duplicate_id',
        'rendered.empty_button',
        'rendered.empty_heading',
        'rendered.empty_link',
        'rendered.form_control_missing_label',
        'rendered.html_lang_missing',
        'rendered.iframe_missing_title',
        'rendered.img_missing_alt',
        // Missing until 3.0.0 (company acceptance verification): its findings were never resolved by a rescan.
        'rendered.landmark_unique',
        'rendered.main_landmark_missing',
        'rendered.page_title_missing',
        'rendered.svg_missing_accessible_name',
        'rendered.table_empty_header',
        'rendered.table_missing_header',
    ];

    /**
     * Rich-text findings of the page being scanned, for the rendered check's duplicate test (audit C6).
     *
     * @var list<array{ruleId: string, table: string, uid: int, snippet: string, path: string}>
     */
    private array $richTextFindingsForPage = [];

    public function __construct(
        private readonly PageCollector $pageCollector,
        private readonly ContentCollector $contentCollector,
        private readonly ContentHashCalculator $contentHashCalculator,
        private readonly RuleRegistry $ruleRegistry,
        private readonly RenderedPageScanner $renderedPageScanner,
        private readonly RenderedPageTypeGuard $renderedPageTypeGuard,
        private readonly RuleConfigurationService $ruleConfigurationService,
        private readonly IssuePersistenceCoordinator $issuePersistenceCoordinator,
        private readonly IssueResolutionService $issueResolutionService,
        private readonly ScanRepository $scanRepository,
        private readonly SourceStateRepository $sourceStateRepository,
        private readonly LoggerInterface $logger,
        private readonly FieldConfigurationBootstrapService $fieldConfigurationBootstrapService,
        private readonly ?SiteFinder $siteFinder = null,
        private readonly ?RenderedTranslationRecords $renderedTranslationRecords = null,
    ) {
    }

    /**
     * @return list<string>
     */
    private function configuredSiteIdentifiers(): array
    {
        try {
            return array_values(array_map(static fn ($site): string => (string)$site->getIdentifier(), $this->siteFinder?->getAllSites() ?? []));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array{uid:int,name:string,username:string}|null $resolvedBy
     * @param (\Closure(int): bool)|null $pageFilter Keeps only the pages it accepts. A scan a backend user starts
     *        passes their page permission, so pages they may not scan are neither read, fetched nor resolved; their
     *        stored findings stay as they are. CLI and Scheduler scans pass none.
     */
    public function scanSubtree(
        string $siteIdentifier,
        int $rootPid,
        int $depth = 99,
        int $languageUid = -1,
        bool $changedOnly = false,
        ?array $resolvedBy = null,
        ?\Closure $shouldCancel = null,
        ?\Closure $onRunStarted = null,
        bool $includeRenderedPageCheck = false,
        ?\Closure $pageFilter = null,
    ): ScanResult {
        $plan = $this->planSubtree($rootPid, $depth, $languageUid, $pageFilter);

        return $this->runScan(
            siteIdentifier: $siteIdentifier,
            pageUids: $plan['pageUids'],
            rootPid: $rootPid,
            languageUid: $languageUid,
            scope: 'subtree',
            changedOnly: $changedOnly,
            resolvedBy: $resolvedBy,
            shouldCancel: $shouldCancel,
            onRunStarted: $onRunStarted,
            includeRenderedPageCheck: $includeRenderedPageCheck,
            pagesWithoutTranslation: $plan['pagesWithoutTranslation'],
            notPermittedPageUids: $plan['notPermittedPageUids'],
            restricted: $pageFilter !== null,
        );
    }

    /**
     * Which pages of a subtree a scan checks. AUTH-01: pages the filter refuses (a backend user's Content scan admission
     * per page and language) are neither read, rendered nor resolved, and are reported as not permitted — the scan never
     * counts them as checked. N-18: a scan of one translation checks the pages that exist in it; the rest is reported as
     * without translation.
     *
     * @param (\Closure(int): bool)|null $pageFilter
     * @return array{pageUids: list<int>, permittedPages: int, notPermittedPageUids: list<int>, pagesWithoutTranslation: int}
     */
    public function planSubtree(int $rootPid, int $depth, int $languageUid, ?\Closure $pageFilter): array
    {
        $pageUids = array_values(array_map('intval', $this->pageCollector->collectSubtree($rootPid, $depth)));
        $notPermitted = [];
        if ($pageFilter !== null) {
            $permitted = [];
            foreach ($pageUids as $pageUid) {
                if ($pageFilter($pageUid)) {
                    $permitted[] = $pageUid;
                } else {
                    $notPermitted[] = $pageUid;
                }
            }
            $pageUids = $permitted;
        }
        $translated = array_values(array_map('intval', $this->pageCollector->withTranslationIn($pageUids, $languageUid)));

        return [
            'pageUids' => $translated,
            'permittedPages' => count($pageUids),
            'notPermittedPageUids' => $notPermitted,
            'pagesWithoutTranslation' => count($pageUids) - count($translated),
        ];
    }

    /**
     * @param array{uid:int,name:string,username:string}|null $resolvedBy
     */
    public function scanPage(
        string $siteIdentifier,
        int $pageUid,
        int $languageUid = -1,
        bool $changedOnly = false,
        ?array $resolvedBy = null,
        ?\Closure $shouldCancel = null,
        ?\Closure $onRunStarted = null,
    ): ScanResult {
        $pageUids = $this->pageCollector->collectPage($pageUid);

        return $this->runScan(
            siteIdentifier: $siteIdentifier,
            pageUids: $pageUids,
            rootPid: $pageUid,
            languageUid: $languageUid,
            scope: 'page',
            changedOnly: $changedOnly,
            resolvedBy: $resolvedBy,
            shouldCancel: $shouldCancel,
            onRunStarted: $onRunStarted,
            includeRenderedPageCheck: true,
        );
    }

    /**
     * @param int[] $pageUids
     * @param array{uid:int,name:string,username:string}|null $resolvedBy
     */
    private function runScan(
        string $siteIdentifier,
        array $pageUids,
        int $rootPid,
        int $languageUid,
        string $scope,
        bool $changedOnly,
        ?array $resolvedBy,
        ?\Closure $shouldCancel,
        ?\Closure $onRunStarted,
        bool $includeRenderedPageCheck,
        int $pagesWithoutTranslation = 0,
        array $notPermittedPageUids = [],
        bool $restricted = false,
    ): ScanResult {
        // A first scan on a fresh installation (module, Page module, CLI or Scheduler) must check content.
        $this->fieldConfigurationBootstrapService->initializeIfUnconfigured();

        $scanUid = $this->scanRepository->createScanRun(
            siteIdentifier: $siteIdentifier,
            rootPid: $rootPid,
            languageUid: $languageUid,
            scope: $scope,
            notPermittedPageUids: $notPermittedPageUids,
        );

        if ($onRunStarted !== null) {
            $onRunStarted($scanUid);
        }

        $result = new ScanResult(scanUid: $scanUid);
        // F-05: with every content field switched off the scan checks no content. Said so — never a clean result.
        if (!$this->fieldConfigurationBootstrapService->hasEnabledFields()) {
            $result->addWarning(ScanResult::WARNING_NO_SCAN_FIELDS, 'No content fields are enabled for scanning, so no content was checked. Enable fields in Settings › Scan fields.');
        }
        $result->pagesWithoutTranslation = max(0, $pagesWithoutTranslation);
        $result->notPermittedPageUids = array_values(array_map('intval', $notPermittedPageUids));

        try {
            foreach ($pageUids as $pageUid) {
                $this->throwIfCancellationRequested($shouldCancel, $scanUid);
                $this->scanSinglePage(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $pageUid,
                    languageUid: $languageUid,
                    scanUid: $scanUid,
                    result: $result,
                    changedOnly: $changedOnly,
                    resolvedBy: $resolvedBy,
                    shouldCancel: $shouldCancel,
                    includeRenderedPageCheck: $includeRenderedPageCheck,
                );
            }

            $this->throwIfCancellationRequested($shouldCancel, $scanUid);

            // U-09: a full site/subtree scan retires open findings whose page or record no longer exists.
            // AUTH-01: only a scan without a page restriction (administrator, CLI, Scheduler) does this site-wide sweep; an
            // editor's scan changes no finding outside the pages it was admitted to.
            if (!$changedOnly && $scope !== 'page' && !$restricted) {
                $result->issuesResolved += $this->issueResolutionService->resolveOpenOfDeletedSources($siteIdentifier, $this->configuredSiteIdentifiers(), $scanUid);
            }

            $this->scanRepository->finishScanRun(
                scanUid: $scanUid,
                pagesScanned: $result->pagesScanned,
                recordsScanned: $result->recordsScanned,
                issuesNew: $result->issuesNew,
                issuesResolved: $result->issuesResolved,
                issuesIgnored: $result->issuesIgnored,
                renderedCoverage: $result->renderedCoverage(),
            );
        } catch (ScanCancelledException $e) {
            $this->scanRepository->cancelScanRun(
                scanUid: $scanUid,
                pagesScanned: $result->pagesScanned,
                recordsScanned: $result->recordsScanned,
                issuesNew: $result->issuesNew,
                issuesResolved: $result->issuesResolved,
                issuesIgnored: $result->issuesIgnored,
            );

            $this->logger->info('Scan cancelled', [
                'scanUid' => $scanUid,
                'pagesScanned' => $result->pagesScanned,
                'recordsScanned' => $result->recordsScanned,
            ]);

            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Scan failed', [
                'scanUid' => $scanUid,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->scanRepository->failScanRun($scanUid);

            throw $e;
        }

        $this->logger->info('Scan completed', [
            'scanUid' => $scanUid,
            'pagesScanned' => $result->pagesScanned,
            'recordsScanned' => $result->recordsScanned,
            'recordsSkipped' => $result->recordsSkipped,
            'issuesNew' => $result->issuesNew,
            'issuesResolved' => $result->issuesResolved,
            'issuesIgnored' => $result->issuesIgnored,
            'changedOnly' => $changedOnly,
        ]);

        return $result;
    }

    /**
     * @param array{uid:int,name:string,username:string}|null $resolvedBy
     */
    private function scanSinglePage(
        string $siteIdentifier,
        int $pageUid,
        int $languageUid,
        int $scanUid,
        ScanResult $result,
        bool $changedOnly,
        ?array $resolvedBy,
        ?\Closure $shouldCancel,
        bool $includeRenderedPageCheck,
    ): void {
        $records = $this->contentCollector->collectForPage($pageUid, $languageUid);
        $result->pagesScanned++;

        $seenFingerprintsForPage = [];
        $evaluatedRuleIdsForPage = [];
        $this->richTextFindingsForPage = [];
        $pendingHeaderLevelIsH1Violations = [];
        $renderedCheckCompleted = false;
        $suppressStructuredHeaderLevelIsH1 = false;
        $suppressStructuredHeaderLevelIsH1LanguageUid = $languageUid >= 0 ? $languageUid : 0;

        foreach ($records as $recordEnvelope) {
            $this->throwIfCancellationRequested($shouldCancel, $scanUid);
            $result->recordsScanned++;

            $tableName = (string)$recordEnvelope['tableName'];
            $record = is_array($recordEnvelope['record']) ? $recordEnvelope['record'] : [];
            $rteFields = is_array($recordEnvelope['rteFields']) ? $recordEnvelope['rteFields'] : [];
            $fileReferenceFields = is_array($recordEnvelope['fileReferenceFields']) ? $recordEnvelope['fileReferenceFields'] : [];
            $structuredFields = is_array($recordEnvelope['structuredFields']) ? $recordEnvelope['structuredFields'] : [];
            $languageField = (string)($recordEnvelope['languageField'] ?? '');
            $cTypeField = (string)($recordEnvelope['cTypeField'] ?? '');

            $recordUid = (int)($record['uid'] ?? 0);
            $recordLangUid = $languageField !== '' ? (int)($record[$languageField] ?? 0) : 0;
            $recordCType = $cTypeField !== '' ? (string)($record[$cTypeField] ?? '') : '';
            $recordHadProcessedField = false;

            foreach ($rteFields as $field) {
                $this->throwIfCancellationRequested($shouldCancel, $scanUid);
                $html = (string)($record[$field] ?? '');
                if (trim($html) === '') {
                    continue;
                }

                $contentHash = $this->contentHashCalculator->forRteField($html);

                if (
                    $changedOnly
                    && $this->sourceStateRepository->isUnchanged(
                        $siteIdentifier,
                        $tableName,
                        $recordUid,
                        $field,
                        $recordLangUid,
                        $contentHash
                    )
                ) {
                    continue;
                }

                $recordHadProcessedField = true;

                $ctx = new CheckContext(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $pageUid,
                    sourceLangUid: $recordLangUid,
                    sourceTable: $tableName,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    content: $html,
                    cType: $recordCType,
                    contextPath: sprintf(
                        'Page:%d > %s:%d > %s',
                        $pageUid,
                        $tableName,
                        $recordUid,
                        $field
                    ),
                );

                $this->processViolations(
                    ctx: $ctx,
                    scanUid: $scanUid,
                    result: $result,
                    seenFingerprintsForPage: $seenFingerprintsForPage,
                    evaluatedRuleIdsForPage: $evaluatedRuleIdsForPage,
                );

                $this->sourceStateRepository->upsertHash(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $pageUid,
                    sourceTable: $tableName,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    sourceLangUid: $recordLangUid,
                    hash: $contentHash,
                    scanUid: $scanUid,
                );
            }

            foreach ($structuredFields as $field) {
                $this->throwIfCancellationRequested($shouldCancel, $scanUid);
                $value = $record[$field] ?? null;
                $shouldProcessEmptyValue = $field === 'header' && $recordCType === 'header';

                if ($value === null) {
                    continue;
                }

                if ($value === '' && !$shouldProcessEmptyValue) {
                    continue;
                }

                $contentHash = $this->contentHashCalculator->forStructuredField($value);

                if (
                    $changedOnly
                    && $this->sourceStateRepository->isUnchanged(
                        $siteIdentifier,
                        $tableName,
                        $recordUid,
                        $field,
                        $recordLangUid,
                        $contentHash
                    )
                ) {
                    continue;
                }

                $recordHadProcessedField = true;

                $ctx = new CheckContext(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $pageUid,
                    sourceLangUid: $recordLangUid,
                    sourceTable: $tableName,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    content: $value,
                    cType: $recordCType,
                    contextPath: sprintf(
                        'Page:%d > %s:%d > %s',
                        $pageUid,
                        $tableName,
                        $recordUid,
                        $field
                    ),
                );

                $violations = $this->runRulesFor($ctx, $evaluatedRuleIdsForPage);
                $nonDeferredViolations = [];
                foreach ($violations as $violation) {
                    if ($violation->ruleId === 'structured.header_level_is_h1') {
                        $pendingHeaderLevelIsH1Violations[] = [$ctx, $violation];
                        continue;
                    }

                    $nonDeferredViolations[] = $violation;
                }

                $this->issuePersistenceCoordinator->persistViolations(
                    violations: $nonDeferredViolations,
                    context: $ctx,
                    scanUid: $scanUid,
                    result: $result,
                    seenFingerprintsForPage: $seenFingerprintsForPage,
                );

                $this->sourceStateRepository->upsertHash(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $pageUid,
                    sourceTable: $tableName,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    sourceLangUid: $recordLangUid,
                    hash: $contentHash,
                    scanUid: $scanUid,
                );
            }

            foreach ($fileReferenceFields as $field) {
                $this->throwIfCancellationRequested($shouldCancel, $scanUid);
                $ctx = new CheckContext(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $pageUid,
                    sourceLangUid: $recordLangUid,
                    sourceTable: $tableName,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    content: $recordUid,
                    cType: $recordCType,
                    contextPath: sprintf(
                        'Page:%d > %s:%d > %s',
                        $pageUid,
                        $tableName,
                        $recordUid,
                        $field
                    ),
                );

                $this->processViolations(
                    ctx: $ctx,
                    scanUid: $scanUid,
                    result: $result,
                    seenFingerprintsForPage: $seenFingerprintsForPage,
                    evaluatedRuleIdsForPage: $evaluatedRuleIdsForPage,
                );

                $recordHadProcessedField = true;
            }

            if ($changedOnly && !$recordHadProcessedField) {
                $result->recordsSkipped++;
            }
        }

        if ($includeRenderedPageCheck && !$changedOnly) {
            $renderedSettings = $this->ruleConfigurationService->getRenderedCheckSettingsForSite($siteIdentifier);
            if (!$renderedSettings['enabled']) {
                $this->logger->debug('Rendered page check skipped: disabled in ruleset settings.', [
                    'siteIdentifier' => $siteIdentifier,
                    'pageUid' => $pageUid,
                    'scanUid' => $scanUid,
                ]);
            } else {
                // D-02: an "all languages" scan renders every existing translation of the page in its own language (the
                // page collector no longer returns translation rows as pages). The rendered rules count as evaluated only
                // when every language was checked, so a failed translation never resolves its rendered findings.
                $renderedLanguageUids = $languageUid >= 0
                    ? [$languageUid]
                    : [0, ...array_values(array_unique($this->pageCollector->translationsOf($pageUid)))];
                $renderedAllCompleted = true;
                $renderedFailure = null;
                foreach ($renderedLanguageUids as $renderedLanguageUid) {
                    $languageCheckCompleted = false;
                    $pageDoktype = $this->renderedPageTypeGuard->getDoktypeForPage($pageUid);

                    if (!$this->renderedPageTypeGuard->supportsDoktype($pageDoktype)) {
                        $languageCheckCompleted = true;
                        $result->addWarning(
                            code: 'rendered_check_skipped_unsupported_doktype',
                            message: BackendLabelUtility::translate(
                                'renderedCheck.warning.unsupportedDoktype',
                                'Rendered page check was skipped because this page type does not render a standard frontend page. Local content checks were still completed.'
                            ),
                            context: [
                                'pageUid' => $pageUid,
                                'languageUid' => $renderedLanguageUid,
                                'doktype' => $pageDoktype ?? 0,
                                'source' => 'rendered_check',
                            ]
                        );
                        $this->logger->info('Rendered page check skipped for unsupported page doktype.', [
                            'siteIdentifier' => $siteIdentifier,
                            'pageUid' => $pageUid,
                            'languageUid' => $renderedLanguageUid,
                            'doktype' => $pageDoktype,
                            'scanUid' => $scanUid,
                        ]);
                    } else {
                        $this->throwIfCancellationRequested($shouldCancel, $scanUid);
                        $renderedContext = new CheckContext(
                            siteIdentifier: $siteIdentifier,
                            pageUid: $pageUid,
                            sourceLangUid: $renderedLanguageUid,
                            sourceTable: Tables::PAGES,
                            sourceUid: $pageUid,
                            sourceField: '__rendered_html',
                            content: '',
                            cType: '',
                            contextPath: sprintf('Page:%d > rendered HTML', $pageUid),
                            sourceType: 'rendered',
                        );

                        $renderedResult = $this->renderedPageScanner->scanPageWithResult(
                            $siteIdentifier,
                            $pageUid,
                            $renderedLanguageUid,
                            $renderedSettings['allowPrivateHosts']
                        );
                        $languageCheckCompleted = $renderedResult->completed;
                        // A hidden, scheduled or group-restricted page is not shown to the visitor the check requests it as.
                        $visitorRestriction = $languageCheckCompleted
                            ? ''
                            : $this->renderedPageTypeGuard->visitorRestriction($pageUid, $renderedLanguageUid);
                        if (!$languageCheckCompleted && $renderedFailure === null) {
                            $renderedFailure = [$renderedResult->statusCode, $renderedResult->warning, $visitorRestriction];
                        }
                        if ($renderedLanguageUid === $suppressStructuredHeaderLevelIsH1LanguageUid) {
                            $suppressStructuredHeaderLevelIsH1 = $languageCheckCompleted && $renderedResult->h1Count === 1;
                        }
                        $this->logger->debug('Rendered H1 suppression evaluation finished.', [
                            'siteIdentifier' => $siteIdentifier,
                            'pageUid' => $pageUid,
                            'languageUid' => $renderedLanguageUid,
                            'scanUid' => $scanUid,
                            'renderedCheckCompleted' => $languageCheckCompleted,
                            'h1Count' => $renderedResult->h1Count,
                            'suppressedStructuredH1' => $suppressStructuredHeaderLevelIsH1,
                            'failureReason' => $renderedResult->failureReason,
                        ]);
                        if (!$languageCheckCompleted && $renderedResult->warning !== '') {
                            $result->addWarning(
                                code: 'rendered_check_skipped',
                                message: $renderedResult->warning,
                                context: [
                                    'pageUid' => $pageUid,
                                    'languageUid' => $renderedLanguageUid,
                                    'source' => 'rendered_check',
                                    'statusCode' => $renderedResult->statusCode,
                                    'reason' => $visitorRestriction,
                                ]
                            );
                        }

                        if ($renderedResult->failureReason === 'error_page') {
                            $resolvedTechnicalErrorIssues = $this->issueResolutionService->resolveRenderedTechnicalErrorSnippetIssues(
                                pageUid: $pageUid,
                                siteIdentifier: $siteIdentifier,
                                sourceLangUid: $renderedLanguageUid,
                                scanUid: $scanUid,
                            );
                            $result->issuesResolved += $resolvedTechnicalErrorIssues;
                        }

                        // Audit C6: an element both scopes report is one finding, kept under its rich-text rule.
                        // N-02: a translated page's content markers name the default record; its own translation
                        // is the record the finding belongs to (and the one its rich-text twin was stored for).
                        $renderedLanguageViolations = $this->renderedTranslationRecords?->forLanguage($renderedResult->violations, $renderedLanguageUid)
                            ?? $renderedResult->violations;
                        $this->issuePersistenceCoordinator->keepIdentityOfRemapped($renderedResult->violations, $renderedLanguageViolations, $renderedContext);
                        [$renderedViolations, $renderedDuplicates] = RenderedRichTextDuplicates::split(
                            $renderedLanguageViolations,
                            $this->richTextFindingsForPage
                        );
                        if ($renderedDuplicates !== []) {
                            $this->issuePersistenceCoordinator->retireRenderedDuplicates($renderedDuplicates, $renderedContext);
                            $this->logger->debug('Rendered findings already reported by a rich-text rule were not stored twice.', [
                                'siteIdentifier' => $siteIdentifier,
                                'pageUid' => $pageUid,
                                'scanUid' => $scanUid,
                                'duplicates' => count($renderedDuplicates),
                            ]);
                        }

                        $this->issuePersistenceCoordinator->persistViolations(
                            violations: $renderedViolations,
                            context: $renderedContext,
                            scanUid: $scanUid,
                            result: $result,
                            seenFingerprintsForPage: $seenFingerprintsForPage,
                        );
                    }
                    $renderedAllCompleted = $renderedAllCompleted && $languageCheckCompleted;
                }
                $renderedCheckCompleted = $renderedAllCompleted;
                [$failureStatus, $failureWarning, $failureRestriction] = $renderedFailure ?? [0, '', ''];
                $result->recordRenderedCheck($pageUid, $renderedCheckCompleted, $failureStatus, $failureWarning, $failureRestriction);
                if ($renderedCheckCompleted) {
                    array_push($evaluatedRuleIdsForPage, ...self::RENDERED_RULE_IDS);
                    // Rendered findings that older releases stored on a translation row (as "page <uid>, language 0").
                    $result->issuesResolved += $this->issueResolutionService->resolveRenderedOnTranslationRows(
                        pageUids: array_keys($this->pageCollector->translationsOf($pageUid)),
                        siteIdentifier: $siteIdentifier,
                        scanUid: $scanUid,
                    );
                }
            }
        }

        if ($suppressStructuredHeaderLevelIsH1) {
            $resolvedSuppressedH1Issues = $this->issueResolutionService->resolveOpenRuleForPage(
                pageUid: $pageUid,
                siteIdentifier: $siteIdentifier,
                sourceLangUid: $suppressStructuredHeaderLevelIsH1LanguageUid,
                ruleId: 'structured.header_level_is_h1',
                scanUid: $scanUid,
            );
            $result->issuesResolved += $resolvedSuppressedH1Issues;

            $this->logger->debug('Structured H1 review issue suppressed because rendered HTML contains exactly one H1.', [
                'siteIdentifier' => $siteIdentifier,
                'pageUid' => $pageUid,
                'languageUid' => $suppressStructuredHeaderLevelIsH1LanguageUid,
                'scanUid' => $scanUid,
                'resolvedExistingIssues' => $resolvedSuppressedH1Issues,
            ]);
        } else {
            foreach ($pendingHeaderLevelIsH1Violations as [$pendingContext, $pendingViolation]) {
                if (!$pendingContext instanceof CheckContext || !$pendingViolation instanceof \Priebera\A11yQualityGate\Rule\RuleViolation) {
                    continue;
                }

                $this->issuePersistenceCoordinator->persistViolations(
                    violations: [$pendingViolation],
                    context: $pendingContext,
                    scanUid: $scanUid,
                    result: $result,
                    seenFingerprintsForPage: $seenFingerprintsForPage,
                );
            }
        }

        if (!$changedOnly && $siteIdentifier !== '') {
            // D-03: findings of this page under a site identifier that no longer exists (renamed or removed site).
            $result->issuesResolved += $this->issueResolutionService->resolveOpenOfUnconfiguredSitesForPage(
                $pageUid,
                $this->configuredSiteIdentifiers(),
                $scanUid,
            );
        }

        if (!$changedOnly && $siteIdentifier !== '') {
            // ML-02: a record deleted from this page (in the scanned language) no longer has findings, even when no
            // record of that language is left to evaluate a rule on.
            $result->issuesResolved += $this->issueResolutionService->resolveOpenOfDeletedRecordsOnPage(
                $siteIdentifier,
                $pageUid,
                $languageUid,
                $scanUid,
            );
        }

        if (!$changedOnly) {
            $resolved = $this->issueResolutionService->resolveUnseenForPage(
                pageUid: $pageUid,
                siteIdentifier: $siteIdentifier,
                sourceLangUid: $languageUid,
                seenFingerprintsForPage: $seenFingerprintsForPage,
                scanUid: $scanUid,
                resolvedBy: $resolvedBy,
                excludeSourceTypes: ($includeRenderedPageCheck && !$renderedCheckCompleted) ? ['rendered'] : [],
                includeRuleIds: $evaluatedRuleIdsForPage,
            );

            $result->issuesResolved += $resolved;
            return;
        }

        $this->logger->debug('Changed-only scan finished without full-page resolve.', [
            'pageUid' => $pageUid,
            'scanUid' => $scanUid,
            'changedFingerprintsCount' => count(array_unique($seenFingerprintsForPage)),
        ]);
    }

    private function throwIfCancellationRequested(?\Closure $shouldCancel, int $scanUid): void
    {
        if ($this->scanRepository->isScanCancellationRequested($scanUid)) {
            throw new ScanCancelledException();
        }

        if ($shouldCancel !== null && $shouldCancel($scanUid) === true) {
            throw new ScanCancelledException();
        }
    }

    /**
     * @param array<int, string> $seenFingerprintsForPage
     */
    private function processViolations(
        CheckContext $ctx,
        int $scanUid,
        ScanResult $result,
        array &$seenFingerprintsForPage,
        array &$evaluatedRuleIdsForPage,
    ): void {
        $violations = $this->runRulesFor($ctx, $evaluatedRuleIdsForPage);
        foreach ($violations as $violation) {
            if (str_starts_with($violation->ruleId, 'rte.')) {
                $this->richTextFindingsForPage[] = RenderedRichTextDuplicates::richTextFinding($violation, $ctx->sourceTable, $ctx->sourceUid);
            }
        }

        $this->issuePersistenceCoordinator->persistViolations(
            violations: $violations,
            context: $ctx,
            scanUid: $scanUid,
            result: $result,
            seenFingerprintsForPage: $seenFingerprintsForPage,
        );
    }

    /**
     * @return array<int, \Priebera\A11yQualityGate\Rule\RuleViolation>
     */
    private function runRulesFor(CheckContext $ctx, array &$evaluatedRuleIds = []): array
    {
        $violations = [];

        foreach ($this->ruleRegistry->getRulesFor($ctx) as $rule) {
            $ruleId = $rule->getRuleId();
            if (!$this->ruleConfigurationService->isRuleEnabledForSite($ctx->siteIdentifier, $ruleId)) {
                continue;
            }

            try {
                $ruleViolations = $rule->check($ctx);
                $evaluatedRuleIds[] = $ruleId;
                array_push($violations, ...$ruleViolations);
            } catch (\Throwable $e) {
                $this->logger->warning('Rule check failed', [
                    'ruleId' => $ruleId,
                    'sourceUid' => $ctx->sourceUid,
                    'field' => $ctx->sourceField,
                    'sourceTable' => $ctx->sourceTable,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $violations;
    }
}
