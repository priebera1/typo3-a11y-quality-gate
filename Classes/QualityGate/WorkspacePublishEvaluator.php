<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\QualityGate;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Scan\ContentCollector;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;

/**
 * The open issue counts a content scan of one page would store, computed without storing anything. Inside
 * PublishPreviewContext::run() the records are those a workspace publish is about to put live, so the Quality Gate can
 * decide before the publish is written.
 *
 * The checked fields follow ScanOrchestrator::scanSinglePage() (rich text, structured fields, file references); a
 * finding the site ignored or muted does not count, as in the stored results. The rendered page check cannot run on
 * unpublished content, so — as for a hidden page in the live gate — stored rendered findings of the page count instead.
 * WorkspacePublishGateTest pins that these counts equal what a content scan stores for the same content.
 */
final class WorkspacePublishEvaluator
{
    public function __construct(
        private readonly ContentCollector $contentCollector,
        private readonly RuleRegistry $ruleRegistry,
        private readonly RuleConfigurationService $ruleConfigurationService,
        private readonly IssueRepository $issueRepository,
    ) {
    }

    /** @return array{critical:int, warning:int, info:int, needs_review:int} */
    public function countsFor(string $siteIdentifier, int $pageUid, int $languageUid): array
    {
        /** @var array<string, Severity> $severities fingerprint → severity */
        $severities = [];
        foreach ($this->contentCollector->collectForPage($pageUid, $languageUid) as $envelope) {
            foreach ($this->contextsFor($siteIdentifier, $pageUid, $envelope) as $context) {
                foreach ($this->ruleRegistry->getRulesFor($context) as $rule) {
                    if (!$this->ruleConfigurationService->isRuleEnabledForSite($siteIdentifier, $rule->getRuleId())) {
                        continue;
                    }
                    try {
                        foreach ($rule->check($context) as $violation) {
                            $severities[$violation->fingerprint($context)] = $violation->severity;
                        }
                    } catch (\Throwable) {
                        // As in a scan: a failing rule reports nothing.
                    }
                }
            }
        }
        foreach ($this->issueRepository->findProtectedFingerprints($siteIdentifier, array_map('strval', array_keys($severities))) as $protected) {
            unset($severities[$protected]);
        }

        $counts = $this->issueRepository->countOpenBySeverity($pageUid, $siteIdentifier, $languageUid, 'rendered');
        foreach ($severities as $severity) {
            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
            };
            $counts[$key]++;
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $envelope a ContentCollector record envelope
     * @return list<CheckContext>
     */
    private function contextsFor(string $siteIdentifier, int $pageUid, array $envelope): array
    {
        $tableName = (string)$envelope['tableName'];
        $record = is_array($envelope['record']) ? $envelope['record'] : [];
        $languageField = (string)($envelope['languageField'] ?? '');
        $cTypeField = (string)($envelope['cTypeField'] ?? '');
        $recordUid = (int)($record['uid'] ?? 0);
        $languageUid = $languageField !== '' ? (int)($record[$languageField] ?? 0) : 0;
        $cType = $cTypeField !== '' ? (string)($record[$cTypeField] ?? '') : '';
        $context = static fn (string $field, mixed $content): CheckContext => new CheckContext(
            siteIdentifier: $siteIdentifier,
            pageUid: $pageUid,
            sourceLangUid: $languageUid,
            sourceTable: $tableName,
            sourceUid: $recordUid,
            sourceField: $field,
            content: $content,
            cType: $cType,
            contextPath: sprintf('Page:%d > %s:%d > %s', $pageUid, $tableName, $recordUid, $field),
        );

        $contexts = [];
        foreach ((array)($envelope['rteFields'] ?? []) as $field) {
            $html = (string)($record[$field] ?? '');
            if (trim($html) !== '') {
                $contexts[] = $context((string)$field, $html);
            }
        }
        foreach ((array)($envelope['structuredFields'] ?? []) as $field) {
            $value = $record[$field] ?? null;
            if ($value === null || ($value === '' && !($field === 'header' && $cType === 'header'))) {
                continue;
            }
            $contexts[] = $context((string)$field, $value);
        }
        foreach ((array)($envelope['fileReferenceFields'] ?? []) as $field) {
            $contexts[] = $context((string)$field, $recordUid);
        }

        return $contexts;
    }
}
