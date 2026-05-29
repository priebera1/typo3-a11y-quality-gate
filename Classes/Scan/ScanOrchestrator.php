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
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Psr\Log\LoggerInterface;

final class ScanOrchestrator
{
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
    ) {
    }

    /**
     * @param array{uid:int,name:string,username:string}|null $resolvedBy
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
    ): ScanResult {
        $pageUids = $this->pageCollector->collectSubtree($rootPid, $depth);

        return $this->runScan(
            siteIdentifier: $siteIdentifier,
            pageUids: $pageUids,
            rootPid: $rootPid,
            languageUid: $languageUid,
            scope: 'subtree',
            changedOnly: $changedOnly,
            resolvedBy: $resolvedBy,
            shouldCancel: $shouldCancel,
            onRunStarted: $onRunStarted,
            includeRenderedPageCheck: false,
        );
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
    ): ScanResult {
        $scanUid = $this->scanRepository->createScanRun(
            siteIdentifier: $siteIdentifier,
            rootPid: $rootPid,
            languageUid: $languageUid,
            scope: $scope,
        );

        if ($onRunStarted !== null) {
            $onRunStarted($scanUid);
        }

        $result = new ScanResult(scanUid: $scanUid);

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
                    includeRenderedPageCheck: $includeRenderedPageCheck && $scope === 'page',
                );
            }

            $this->throwIfCancellationRequested($shouldCancel, $scanUid);

            $this->scanRepository->finishScanRun(
                scanUid: $scanUid,
                pagesScanned: $result->pagesScanned,
                recordsScanned: $result->recordsScanned,
                issuesNew: $result->issuesNew,
                issuesResolved: $result->issuesResolved,
                issuesIgnored: $result->issuesIgnored,
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

                $violations = $this->runRulesFor($ctx);
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
                $renderedLanguageUid = $languageUid >= 0 ? $languageUid : 0;
                $pageDoktype = $this->renderedPageTypeGuard->getDoktypeForPage($pageUid);

                if (!$this->renderedPageTypeGuard->supportsDoktype($pageDoktype)) {
                    $renderedCheckCompleted = true;
                    $result->addWarning(
                        code: 'rendered_check_skipped_unsupported_doktype',
                        message: 'Rendered page check was skipped because this page type does not render a standard frontend page. Local content checks were still completed.',
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
                    $renderedCheckCompleted = $renderedResult->completed;
                    $suppressStructuredHeaderLevelIsH1 = $renderedCheckCompleted && $renderedResult->h1Count === 1;
                    $this->logger->debug('Rendered H1 suppression evaluation finished.', [
                        'siteIdentifier' => $siteIdentifier,
                        'pageUid' => $pageUid,
                        'languageUid' => $renderedLanguageUid,
                        'scanUid' => $scanUid,
                        'renderedCheckCompleted' => $renderedCheckCompleted,
                        'h1Count' => $renderedResult->h1Count,
                        'suppressedStructuredH1' => $suppressStructuredHeaderLevelIsH1,
                        'failureReason' => $renderedResult->failureReason,
                    ]);
                    if (!$renderedCheckCompleted && $renderedResult->warning !== '') {
                        $result->addWarning(
                            code: 'rendered_check_skipped',
                            message: $renderedResult->warning,
                            context: [
                                'pageUid' => $pageUid,
                                'languageUid' => $renderedLanguageUid,
                                'source' => 'rendered_check',
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

                    $this->issuePersistenceCoordinator->persistViolations(
                        violations: $renderedResult->violations,
                        context: $renderedContext,
                        scanUid: $scanUid,
                        result: $result,
                        seenFingerprintsForPage: $seenFingerprintsForPage,
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

        if (!$changedOnly) {
            $resolved = $this->issueResolutionService->resolveUnseenForPage(
                pageUid: $pageUid,
                siteIdentifier: $siteIdentifier,
                sourceLangUid: $languageUid,
                seenFingerprintsForPage: $seenFingerprintsForPage,
                scanUid: $scanUid,
                resolvedBy: $resolvedBy,
                excludeSourceTypes: ($includeRenderedPageCheck && !$renderedCheckCompleted) ? ['rendered'] : [],
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
    ): void {
        $violations = $this->runRulesFor($ctx);

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
    private function runRulesFor(CheckContext $ctx): array
    {
        $violations = [];

        foreach ($this->ruleRegistry->getRulesFor($ctx) as $rule) {
            if (!$this->ruleConfigurationService->isRuleEnabledForSite($ctx->siteIdentifier, $rule->getRuleId())) {
                continue;
            }

            try {
                $ruleViolations = $rule->check($ctx);
                array_push($violations, ...$ruleViolations);
            } catch (\Throwable $e) {
                $this->logger->warning('Rule check failed', [
                    'ruleId' => $rule->getRuleId(),
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
