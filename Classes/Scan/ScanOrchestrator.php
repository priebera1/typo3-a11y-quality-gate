<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Domain\Repository\SourceStateRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Psr\Log\LoggerInterface;

final class ScanOrchestrator
{
    public function __construct(
        private readonly PageCollector $pageCollector,
        private readonly ContentCollector $contentCollector,
        private readonly ContentHashCalculator $contentHashCalculator,
        private readonly RuleRegistry $ruleRegistry,
        private readonly IssueRepository $issueRepository,
        private readonly ScanRepository $scanRepository,
        private readonly SourceStateRepository $sourceStateRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function scanSubtree(
        string $siteIdentifier,
        int $rootPid,
        int $depth = 99,
        int $languageUid = -1,
        bool $changedOnly = false,
    ): ScanResult {
        $pageUids = $this->pageCollector->collectSubtree($rootPid, $depth);

        return $this->runScan(
            siteIdentifier: $siteIdentifier,
            pageUids: $pageUids,
            rootPid: $rootPid,
            languageUid: $languageUid,
            scope: 'subtree',
            changedOnly: $changedOnly,
        );
    }

    public function scanPage(
        string $siteIdentifier,
        int $pageUid,
        int $languageUid = -1,
        bool $changedOnly = false,
    ): ScanResult {
        $pageUids = $this->pageCollector->collectPage($pageUid);

        return $this->runScan(
            siteIdentifier: $siteIdentifier,
            pageUids: $pageUids,
            rootPid: $pageUid,
            languageUid: $languageUid,
            scope: 'page',
            changedOnly: $changedOnly,
        );
    }

    /**
     * @param int[] $pageUids
     */
    private function runScan(
        string $siteIdentifier,
        array $pageUids,
        int $rootPid,
        int $languageUid,
        string $scope,
        bool $changedOnly,
    ): ScanResult {
        $scanUid = $this->scanRepository->createScanRun(
            siteIdentifier: $siteIdentifier,
            rootPid: $rootPid,
            languageUid: $languageUid,
            scope: $scope,
        );

        $result = new ScanResult(scanUid: $scanUid);

        try {
            foreach ($pageUids as $pageUid) {
                $this->scanSinglePage(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $pageUid,
                    languageUid: $languageUid,
                    scanUid: $scanUid,
                    result: $result,
                    changedOnly: $changedOnly,
                );
            }

            $this->scanRepository->finishScanRun(
                scanUid: $scanUid,
                pagesScanned: $result->pagesScanned,
                recordsScanned: $result->recordsScanned,
                issuesNew: $result->issuesNew,
                issuesResolved: $result->issuesResolved,
                issuesIgnored: $result->issuesIgnored,
            );
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

    private function scanSinglePage(
        string $siteIdentifier,
        int $pageUid,
        int $languageUid,
        int $scanUid,
        ScanResult $result,
        bool $changedOnly,
    ): void {
        $records = $this->contentCollector->collectForPage($pageUid, $languageUid);
        $result->pagesScanned++;

        $seenFingerprintsForPage = [];

        foreach ($records as $record) {
            $result->recordsScanned++;

            $recordUid = (int)($record['uid'] ?? 0);
            $recordLangUid = (int)($record['sys_language_uid'] ?? 0);
            $recordCType = (string)($record['CType'] ?? '');
            $recordHadProcessedField = false;

            foreach ($this->contentCollector->getRteFields() as $field) {
                $html = (string)($record[$field] ?? '');
                if (trim($html) === '') {
                    continue;
                }

                $contentHash = $this->contentHashCalculator->forRteField($html);

                if (
                    $changedOnly
                    && $this->sourceStateRepository->isUnchanged(
                        $siteIdentifier,
                        Tables::TT_CONTENT,
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
                    sourceTable: Tables::TT_CONTENT,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    content: $html,
                    cType: $recordCType,
                    contextPath: sprintf(
                        'Page:%d > tt_content:%d > %s',
                        $pageUid,
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
                    sourceTable: Tables::TT_CONTENT,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    sourceLangUid: $recordLangUid,
                    hash: $contentHash,
                    scanUid: $scanUid,
                );
            }

            foreach ($this->contentCollector->getStructuredFields() as $field) {
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
                        Tables::TT_CONTENT,
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
                    sourceTable: Tables::TT_CONTENT,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    content: $value,
                    cType: $recordCType,
                    contextPath: sprintf(
                        'Page:%d > tt_content:%d > %s',
                        $pageUid,
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
                    sourceTable: Tables::TT_CONTENT,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    sourceLangUid: $recordLangUid,
                    hash: $contentHash,
                    scanUid: $scanUid,
                );
            }

            foreach ($this->contentCollector->getFileReferenceFields() as $field) {
                $ctx = new CheckContext(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $pageUid,
                    sourceLangUid: $recordLangUid,
                    sourceTable: Tables::TT_CONTENT,
                    sourceUid: $recordUid,
                    sourceField: $field,
                    content: $recordUid,
                    cType: $recordCType,
                    contextPath: sprintf(
                        'Page:%d > tt_content:%d > %s',
                        $pageUid,
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

        if (!$changedOnly) {
            $resolved = $this->issueRepository->resolveUnseen(
                pageUid: $pageUid,
                siteIdentifier: $siteIdentifier,
                sourceLangUid: $languageUid,
                seenFingerprints: array_values(array_unique($seenFingerprintsForPage)),
                scanUid: $scanUid,
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

        foreach ($violations as $violation) {
            $fingerprint = $violation->fingerprint($ctx);
            $seenFingerprintsForPage[] = $fingerprint;

            $upsertResult = $this->issueRepository->upsert($violation, $ctx, $scanUid);

            match ($upsertResult) {
                'inserted' => $result->issuesNew++,
                'protected' => $result->issuesIgnored++,
                default => null,
            };
        }
    }

    /**
     * @return array<int, \Priebera\A11yQualityGate\Rule\RuleViolation>
     */
    private function runRulesFor(CheckContext $ctx): array
    {
        $violations = [];

        foreach ($this->ruleRegistry->getRulesFor($ctx) as $rule) {
            try {
                $ruleViolations = $rule->check($ctx);
                array_push($violations, ...$ruleViolations);
            } catch (\Throwable $e) {
                $this->logger->warning('Rule check failed', [
                    'ruleId' => $rule->getRuleId(),
                    'sourceUid' => $ctx->sourceUid,
                    'field' => $ctx->sourceField,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $violations;
    }
}
