<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RemoteScanHistoryService
{
    public function __construct(
        private readonly ExtensionContextService $extensionContextService,
        private readonly ProCrawlerService $proCrawlerService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function loadHistory(
        string $siteBase,
        string $siteIdentifier,
        int $limit = 10,
        string $sourceType = '',
        string $startUrl = '',
        string $status = ''
    ): array {
        if (trim($siteBase) === '' || trim($siteIdentifier) === '') {
            return $this->emptyHistory('Remote scan history is not available without a site context.');
        }

        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                return $this->emptyHistory('Remote scan history is not available without a valid domain context.');
            }

            $payload = $this->proCrawlerService->getHistory(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                siteId: $siteIdentifier,
                limit: $limit,
                sourceType: $sourceType,
                startUrl: $startUrl,
                status: $status,
            );
        } catch (TokenRefreshException $exception) {
            $this->logHistoryError('AQG remote scan history request failed', $exception);
            return $this->emptyHistory($this->mapRemoteHistoryErrorMessage($exception->getMessage()));
        } catch (\Throwable $exception) {
            $this->logHistoryError('AQG remote scan history request failed unexpectedly', $exception);
            return $this->emptyHistory('Remote scan history is not available for this licence or environment.');
        }

        $items = $this->extractList($payload, ['items', 'history', 'scans', 'results']);
        $normalized = $this->normalizeHistoryItems($items);
        $normalized = $this->attachComparablePrevious($normalized);

        $siteScans = [];
        $pageScans = [];
        foreach ($normalized as $item) {
            if ($item['isSiteScan']) {
                $siteScans[] = $item;
                continue;
            }
            $pageScans[] = $item;
        }

        $hasSiteScans = $siteScans !== [];
        $hasPageScans = $pageScans !== [];
        $hasAnyRows = $hasSiteScans || $hasPageScans;

        return [
            'available' => true,
            'hasItems' => $hasAnyRows,
            'hasAnyRows' => $hasAnyRows,
            'showScopedHistory' => $hasAnyRows,
            'showGlobalEmpty' => !$hasAnyRows,
            'message' => $hasAnyRows ? '' : 'No completed remote scan history was returned yet.',
            'items' => $normalized,
            'siteScans' => $siteScans,
            'pageScans' => $pageScans,
            'siteScansCount' => count($siteScans),
            'pageScansCount' => count($pageScans),
            'hasSiteScans' => $hasSiteScans,
            'hasPageScans' => $hasPageScans,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function loadCompare(string $siteBase, string $fromJobId, string $toJobId): array
    {
        $fromJobId = trim($fromJobId);
        $toJobId = trim($toJobId);
        if (trim($siteBase) === '' || $fromJobId === '' || $toJobId === '') {
            return [
                'available' => false,
                'message' => 'Choose two compatible scans to compare.',
            ];
        }

        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                return [
                    'available' => false,
                    'message' => 'The selected scans cannot be compared without a valid domain context.',
                ];
            }

            $payload = $this->proCrawlerService->compareScans(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                fromJobId: $fromJobId,
                toJobId: $toJobId,
            );
        } catch (TokenRefreshException $exception) {
            $this->logHistoryError('AQG remote scan compare request failed', $exception);
            return [
                'available' => false,
                'message' => $this->mapRemoteCompareErrorMessage($exception->getMessage()),
            ];
        } catch (\Throwable $exception) {
            $this->logHistoryError('AQG remote scan compare request failed unexpectedly', $exception);
            return [
                'available' => false,
                'message' => 'The selected scans cannot be compared.',
            ];
        }

        return $this->normalizeComparePayload($payload, $fromJobId, $toJobId);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadRegressionAlert(
        string $siteBase,
        string $siteIdentifier,
        string $sourceType,
        string $startUrl = ''
    ): array {
        $siteIdentifier = trim($siteIdentifier);
        $sourceType = strtolower(trim($sourceType));
        $startUrl = trim($startUrl);

        if (trim($siteBase) === '' || $siteIdentifier === '') {
            return $this->emptyRegressionAlert('Regression signal is not available without a site context.');
        }

        if ($sourceType === '') {
            return $this->emptyRegressionAlert('Internal configuration issue: source type is missing.');
        }

        if (!in_array($sourceType, ['sitemap', 'crawl', 'single_page'], true)) {
            return $this->emptyRegressionAlert('Invalid scan type for regression signal.');
        }

        if ($sourceType === 'single_page' && $startUrl === '') {
            return $this->emptyRegressionAlert('Internal configuration issue: page URL is missing.');
        }

        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                return $this->emptyRegressionAlert('Regression signal is not available without a valid domain context.');
            }

            $payload = $this->proCrawlerService->getRegressionAlert(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                siteId: $siteIdentifier,
                sourceType: $sourceType,
                startUrl: $startUrl,
            );
        } catch (TokenRefreshException $exception) {
            $this->logHistoryError('AQG regression alert request failed', $exception);
            return $this->emptyRegressionAlert($this->mapRegressionAlertErrorMessage($exception->getMessage()));
        } catch (\Throwable $exception) {
            $this->logHistoryError('AQG regression alert request failed unexpectedly', $exception);
            return $this->emptyRegressionAlert('Regression signal is not available right now.');
        }

        return $this->normalizeRegressionAlertPayload($payload, $siteIdentifier, $sourceType, $startUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadRemediationPlanByJobId(string $siteBase, string $jobId, int $limit = 5): array
    {
        $jobId = trim($jobId);
        if (trim($siteBase) === '' || $jobId === '') {
            return $this->emptyRemediationPlan('Recommended remediation plan is not available for this scan.');
        }

        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                return $this->emptyRemediationPlan('Recommended remediation plan is not available for this scan.');
            }

            $payload = $this->proCrawlerService->getRemediationPlan(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                jobId: $jobId,
            );
        } catch (TokenRefreshException $exception) {
            $this->logHistoryError('AQG remediation plan request failed', $exception);
            return $this->emptyRemediationPlan($this->mapRemediationPlanErrorMessage($exception->getMessage()));
        } catch (\Throwable $exception) {
            $this->logHistoryError('AQG remediation plan request failed unexpectedly', $exception);
            return $this->emptyRemediationPlan('Recommended remediation plan is not available for this scan.');
        }

        return $this->normalizeRemediationPlanPayload($payload, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function loadLatestRemediationPlan(
        string $siteBase,
        string $siteIdentifier,
        string $sourceType,
        ?string $startUrl = null,
        int $limit = 5
    ): array {
        $siteIdentifier = trim($siteIdentifier);
        $sourceType = strtolower(trim($sourceType));
        $startUrl = $startUrl !== null ? trim($startUrl) : null;

        if (trim($siteBase) === '' || $siteIdentifier === '') {
            return $this->emptyRemediationPlan('Recommended remediation plan is not available for this scan.');
        }

        if (!in_array($sourceType, ['sitemap', 'crawl', 'single_page'], true)) {
            return $this->emptyRemediationPlan('Recommended remediation plan is not available for this scan.');
        }

        if ($sourceType === 'single_page' && ($startUrl === null || $startUrl === '')) {
            return $this->emptyRemediationPlan('Recommended remediation plan is not available for this scan.');
        }

        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                return $this->emptyRemediationPlan('Recommended remediation plan is not available for this scan.');
            }

            $payload = $this->proCrawlerService->getLatestRemediationPlan(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                siteId: $siteIdentifier,
                sourceType: $sourceType,
                startUrl: $startUrl,
            );
        } catch (TokenRefreshException $exception) {
            $this->logHistoryError('AQG latest remediation plan request failed', $exception);
            return $this->emptyRemediationPlan($this->mapRemediationPlanErrorMessage($exception->getMessage()));
        } catch (\Throwable $exception) {
            $this->logHistoryError('AQG latest remediation plan request failed unexpectedly', $exception);
            return $this->emptyRemediationPlan('Recommended remediation plan is not available for this scan.');
        }

        return $this->normalizeRemediationPlanPayload($payload, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRegressionAlert(string $message = ''): array
    {
        return [
            'available' => false,
            'message' => $message,
            'status' => '',
            'alertLevel' => '',
            'regressionDetected' => false,
            'tone' => 'neutral',
            'shouldNotify' => false,
            'title' => '',
            'summary' => '',
            'actionLabel' => '',
            'actionType' => '',
            'actionUrl' => '',
            'dedupeKey' => '',
            'recommendedAction' => [],
            'scope' => [],
            'previousJobId' => '',
            'currentJobId' => '',
            'contextLabel' => 'Compared with previous compatible frontend scan.',
            'previousScan' => $this->emptyRegressionScanMeta('Previous scan'),
            'currentScan' => $this->emptyRegressionScanMeta('Current scan'),
            'comparisonRows' => [],
            'hasComparisonRows' => false,
            'hasScanComparison' => false,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeRegressionAlertPayload(array $payload, string $siteIdentifier, string $sourceType, string $startUrl): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        $notification = is_array($payload['notification'] ?? null) ? $payload['notification'] : [];
        $recommendedAction = is_array($payload['recommendedAction'] ?? null)
            ? $payload['recommendedAction']
            : (is_array($payload['recommended_action'] ?? null) ? $payload['recommended_action'] : []);
        $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : [];

        $status = trim((string)($payload['status'] ?? ''));
        $alertLevel = strtolower(trim((string)($payload['alertLevel'] ?? $payload['alert_level'] ?? $notification['severity'] ?? '')));
        $regressionDetected = (bool)($payload['regressionDetected'] ?? $payload['regression_detected'] ?? false);
        $shouldNotify = (bool)($notification['shouldNotify'] ?? $notification['should_notify'] ?? false);
        $title = trim((string)($notification['title'] ?? ''));
        $summary = trim((string)($notification['summary'] ?? ''));
        $actionLabel = trim((string)($recommendedAction['label'] ?? $notification['actionLabel'] ?? $notification['action_label'] ?? ''));
        $actionType = trim((string)($recommendedAction['type'] ?? $notification['actionType'] ?? $notification['action_type'] ?? ''));

        if ($title === '') {
            $title = $regressionDetected ? 'Review regression before publishing' : 'No new regression detected in automated scan';
        }

        if ($summary === '') {
            $summary = $regressionDetected
                ? 'Review the change against the previous compatible frontend scan.'
                : 'No meaningful regression was detected compared with the previous compatible frontend scan.';
        }

        if ($actionLabel === '' && $actionType === 'compare') {
            $actionLabel = 'Review automated scan comparison';
        }

        $previousJobId = $this->extractRegressionJobId($payload, ['previousJobId', 'previous_job_id'], ['previousScan', 'previous_scan', 'fromScan', 'from_scan', 'previous']);
        $currentJobId = $this->extractRegressionJobId($payload, ['currentJobId', 'current_job_id'], ['currentScan', 'current_scan', 'toScan', 'to_scan', 'current']);
        $previousScan = $this->extractRegressionScanMeta($payload, ['previousScan', 'previous_scan', 'fromScan', 'from_scan', 'previous'], 'previous', 'Previous scan');
        $currentScan = $this->extractRegressionScanMeta($payload, ['currentScan', 'current_scan', 'toScan', 'to_scan', 'current'], 'current', 'Current scan');
        if ($previousScan['jobId'] === '' && $previousJobId !== '') {
            $previousScan['jobId'] = $previousJobId;
        }
        if ($currentScan['jobId'] === '' && $currentJobId !== '') {
            $currentScan['jobId'] = $currentJobId;
        }
        $comparisonRows = $this->buildRegressionComparisonRows($payload, $previousScan, $currentScan);
        $hasScanComparison = $this->hasRegressionScanMeta($previousScan)
            || $this->hasRegressionScanMeta($currentScan)
            || $comparisonRows !== [];

        $tone = 'neutral';
        if ($shouldNotify || $regressionDetected || in_array($alertLevel, ['warning', 'attention', 'regression', 'medium', 'high', 'critical', 'serious'], true)) {
            $tone = 'warning';
        }

        return [
            'available' => true,
            'message' => '',
            'status' => $status,
            'alertLevel' => $alertLevel !== '' ? $alertLevel : 'none',
            'regressionDetected' => $regressionDetected,
            'tone' => $tone,
            'shouldNotify' => $shouldNotify,
            'title' => $title,
            'summary' => $summary,
            'actionLabel' => $actionLabel !== '' ? $actionLabel : 'Review automated scan comparison',
            'actionType' => $actionType,
            'actionUrl' => '',
            'dedupeKey' => trim((string)($notification['dedupeKey'] ?? $notification['dedupe_key'] ?? '')),
            'recommendedAction' => $recommendedAction,
            'scope' => [
                'siteId' => trim((string)($scope['siteId'] ?? $scope['site_id'] ?? $siteIdentifier)),
                'sourceType' => trim((string)($scope['sourceType'] ?? $scope['source_type'] ?? $sourceType)),
                'startUrl' => trim((string)($scope['startUrl'] ?? $scope['start_url'] ?? $startUrl)),
                'domain' => trim((string)($scope['domain'] ?? '')),
            ],
            'previousJobId' => $previousJobId,
            'currentJobId' => $currentJobId,
            'contextLabel' => 'Compared with previous compatible frontend scan.',
            'previousScan' => $previousScan,
            'currentScan' => $currentScan,
            'comparisonRows' => $comparisonRows,
            'hasComparisonRows' => $comparisonRows !== [],
            'hasScanComparison' => $hasScanComparison,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRegressionScanMeta(string $label): array
    {
        return [
            'label' => $label,
            'jobId' => '',
            'finishedAt' => 0,
            'finishedAtFormatted' => '',
            'findings' => null,
            'findingsLabel' => '—',
            'score' => null,
            'scoreLabel' => '—',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     * @return array<string, mixed>
     */
    private function extractRegressionScanMeta(array $payload, array $keys, string $prefix, string $label): array
    {
        $scan = [];
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $scan = $payload[$key];
                break;
            }
        }

        $finishedAt = $this->parseTimestamp(
            $scan['finishedAt']
            ?? $scan['finished_at']
            ?? $scan['finished']
            ?? $payload[$prefix . 'FinishedAt']
            ?? $payload[$prefix . '_finished_at']
            ?? null
        );
        $jobId = trim((string)(
            $scan['jobId']
            ?? $scan['job_id']
            ?? $scan['id']
            ?? $payload[$prefix . 'JobId']
            ?? $payload[$prefix . '_job_id']
            ?? ''
        ));
        $findings = $this->normalizeRegressionInteger(
            $scan['findings']
            ?? $scan['findingsTotal']
            ?? $scan['findings_total']
            ?? $scan['issuesTotal']
            ?? $scan['issues_total']
            ?? $scan['issues']
            ?? $payload[$prefix . 'Findings']
            ?? $payload[$prefix . '_findings']
            ?? $payload[$prefix . 'IssuesTotal']
            ?? $payload[$prefix . '_issues_total']
            ?? null
        );
        $score = $this->normalizeNullableInt(
            $scan['score']
            ?? $scan['aqgScore']
            ?? $scan['aqg_score']
            ?? $payload[$prefix . 'Score']
            ?? $payload[$prefix . '_score']
            ?? null
        );

        return [
            'label' => $label,
            'jobId' => $jobId,
            'finishedAt' => $finishedAt,
            'finishedAtFormatted' => $finishedAt > 0 ? BackendTimeUtility::formatDateTime($finishedAt, 'd.m.Y H:i') : '',
            'findings' => $findings,
            'findingsLabel' => $findings !== null ? (string)$findings : '—',
            'score' => $score,
            'scoreLabel' => $score !== null ? $score . ' / 100' : '—',
        ];
    }

    /**
     * @param array<string, mixed> $scan
     */
    private function hasRegressionScanMeta(array $scan): bool
    {
        return (string)($scan['finishedAtFormatted'] ?? '') !== ''
            || (string)($scan['findingsLabel'] ?? '—') !== '—'
            || (string)($scan['scoreLabel'] ?? '—') !== '—';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $previousScan
     * @param array<string, mixed> $currentScan
     * @return list<array{label: string, value: string, tone: string}>
     */
    private function buildRegressionComparisonRows(array $payload, array $previousScan, array $currentScan): array
    {
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $change = is_array($payload['change'] ?? null) ? $payload['change'] : [];
        $comparison = is_array($payload['comparison'] ?? null) ? $payload['comparison'] : [];
        $delta = is_array($payload['delta'] ?? null) ? $payload['delta'] : [];
        $newBySeverity = is_array($summary['newBySeverity'] ?? null)
            ? $summary['newBySeverity']
            : (is_array($summary['new_by_severity'] ?? null) ? $summary['new_by_severity'] : []);

        $scoreDelta = $this->normalizeRegressionInteger(
            $summary['scoreDelta']
            ?? $summary['score_delta']
            ?? $summary['scoreChange']
            ?? $summary['score_change']
            ?? $change['scoreDelta']
            ?? $change['score_delta']
            ?? $comparison['scoreDelta']
            ?? $comparison['score_delta']
            ?? $delta['score']
            ?? null
        );
        if ($scoreDelta === null && is_int($previousScan['score'] ?? null) && is_int($currentScan['score'] ?? null)) {
            $scoreDelta = (int)$currentScan['score'] - (int)$previousScan['score'];
        }

        $findingsDelta = $this->normalizeRegressionInteger(
            $summary['findingsDelta']
            ?? $summary['findings_delta']
            ?? $summary['findingsChange']
            ?? $summary['findings_change']
            ?? $summary['issuesDelta']
            ?? $summary['issues_delta']
            ?? $summary['issuesChange']
            ?? $summary['issues_change']
            ?? $change['findingsDelta']
            ?? $change['findings_delta']
            ?? $comparison['findingsDelta']
            ?? $comparison['findings_delta']
            ?? $delta['findings']
            ?? $delta['issues']
            ?? null
        );
        if ($findingsDelta === null && is_int($previousScan['findings'] ?? null) && is_int($currentScan['findings'] ?? null)) {
            $findingsDelta = (int)$currentScan['findings'] - (int)$previousScan['findings'];
        }

        $newCritical = $this->normalizeRegressionInteger(
            $summary['newCritical']
            ?? $summary['new_critical']
            ?? $summary['criticalNew']
            ?? $summary['critical_new']
            ?? $newBySeverity['critical']
            ?? null,
            false
        );
        $newSerious = $this->normalizeRegressionInteger(
            $summary['newSerious']
            ?? $summary['new_serious']
            ?? $summary['seriousNew']
            ?? $summary['serious_new']
            ?? $newBySeverity['serious']
            ?? null,
            false
        );
        $resolvedFindings = $this->normalizeRegressionInteger(
            $summary['resolvedFindings']
            ?? $summary['resolved_findings']
            ?? $summary['resolvedIssues']
            ?? $summary['resolved_issues']
            ?? null,
            false
        );

        $rows = [];
        if ($scoreDelta !== null) {
            $rows[] = ['label' => 'Score change', 'value' => $this->formatSignedInteger($scoreDelta), 'tone' => $scoreDelta < 0 ? 'warning' : ($scoreDelta > 0 ? 'positive' : 'neutral')];
        }
        if ($findingsDelta !== null) {
            $rows[] = ['label' => 'Findings change', 'value' => $this->formatSignedInteger($findingsDelta), 'tone' => $findingsDelta > 0 ? 'warning' : ($findingsDelta < 0 ? 'positive' : 'neutral')];
        }
        if ($newCritical !== null) {
            $rows[] = ['label' => 'New critical', 'value' => (string)$newCritical, 'tone' => $newCritical > 0 ? 'warning' : 'neutral'];
        }
        if ($newSerious !== null) {
            $rows[] = ['label' => 'New serious', 'value' => (string)$newSerious, 'tone' => $newSerious > 0 ? 'warning' : 'neutral'];
        }
        if ($resolvedFindings !== null) {
            $rows[] = ['label' => 'Resolved findings', 'value' => (string)$resolvedFindings, 'tone' => $resolvedFindings > 0 ? 'positive' : 'neutral'];
        }

        return $rows;
    }

    private function normalizeRegressionInteger(mixed $value, bool $allowNegative = true): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        $integer = (int)$value;
        return $allowNegative ? $integer : max(0, $integer);
    }

    private function formatSignedInteger(int $value): string
    {
        if ($value > 0) {
            return '+' . $value;
        }

        return (string)$value;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $directKeys
     * @param list<string> $nestedKeys
     */
    private function extractRegressionJobId(array $payload, array $directKeys, array $nestedKeys): string
    {
        foreach ($directKeys as $key) {
            if (isset($payload[$key]) && is_scalar($payload[$key])) {
                $value = trim((string)$payload[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach ($nestedKeys as $key) {
            if (!isset($payload[$key]) || !is_array($payload[$key])) {
                continue;
            }

            foreach (['jobId', 'job_id', 'id'] as $jobKey) {
                if (isset($payload[$key][$jobKey]) && is_scalar($payload[$key][$jobKey])) {
                    $value = trim((string)$payload[$key][$jobKey]);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyRemediationPlan(string $message = ''): array
    {
        return [
            'available' => false,
            'message' => $message,
            'title' => 'Recommended remediation plan',
            'subtitle' => 'Automated remediation plan. Manual review may still be required.',
            'tasks' => [],
            'hasTasks' => false,
            'taskCount' => 0,
            'scope' => [],
            'status' => '',
            'generatedAt' => 0,
            'generatedAtFormatted' => '',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeRemediationPlanPayload(array $payload, int $limit): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : $payload;
        $tasksSource = $this->extractRemediationTaskList($plan);
        if ($tasksSource === [] && $plan !== $payload) {
            $tasksSource = $this->extractRemediationTaskList($payload);
        }

        $tasks = [];
        foreach ($tasksSource as $index => $task) {
            if (!is_array($task)) {
                continue;
            }
            $normalized = $this->normalizeRemediationTask($task, $index + 1);
            if ($normalized !== null) {
                $tasks[] = $normalized;
            }
        }

        $limit = max(1, min(10, $limit));
        $tasks = array_slice($tasks, 0, $limit);
        $scope = is_array($payload['scope'] ?? null) ? $payload['scope'] : (is_array($plan['scope'] ?? null) ? $plan['scope'] : []);
        $generatedAt = $this->parseTimestamp($payload['generatedAt'] ?? $payload['generated_at'] ?? $plan['generatedAt'] ?? $plan['generated_at'] ?? null);

        $message = $tasks === [] ? 'Recommended remediation plan is not available for this scan.' : '';

        return [
            'available' => true,
            'message' => $message,
            'title' => $this->normalizeShortString($payload['title'] ?? $plan['title'] ?? 'Recommended remediation plan', 120) ?: 'Recommended remediation plan',
            'subtitle' => 'Automated remediation plan. Manual review may still be required.',
            'tasks' => $tasks,
            'hasTasks' => $tasks !== [],
            'taskCount' => count($tasks),
            'scope' => [
                'siteId' => $this->normalizeShortString($scope['siteId'] ?? $scope['site_id'] ?? '', 80) ?: '',
                'sourceType' => $this->normalizeShortString($scope['sourceType'] ?? $scope['source_type'] ?? '', 40) ?: '',
                'startUrl' => $this->normalizeShortString($scope['startUrl'] ?? $scope['start_url'] ?? '', 240) ?: '',
                'domain' => $this->normalizeShortString($scope['domain'] ?? '', 160) ?: '',
            ],
            'status' => $this->normalizeShortString($payload['status'] ?? $plan['status'] ?? '', 80) ?: '',
            'generatedAt' => $generatedAt,
            'generatedAtFormatted' => $generatedAt > 0 ? BackendTimeUtility::formatDateTime($generatedAt, 'd.m.Y H:i') : '',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<mixed>
     */
    private function extractRemediationTaskList(array $payload): array
    {
        foreach (['tasks', 'items', 'recommendations', 'topTasks', 'top_tasks', 'priorities'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && array_is_list($payload[$key])) {
                return array_values($payload[$key]);
            }
        }

        foreach (['remediationPlan', 'remediation_plan', 'plan'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                foreach (['tasks', 'items', 'recommendations', 'topTasks', 'top_tasks', 'priorities'] as $nestedKey) {
                    if (isset($payload[$key][$nestedKey]) && is_array($payload[$key][$nestedKey]) && array_is_list($payload[$key][$nestedKey])) {
                        return array_values($payload[$key][$nestedKey]);
                    }
                }
            }
        }

        return [];
    }

    /**
     * @param array<string, mixed> $task
     * @return array<string, mixed>|null
     */
    private function normalizeRemediationTask(array $task, int $fallbackPriority): ?array
    {
        $title = $this->normalizeShortString($task['title'] ?? $task['label'] ?? $task['ruleTitle'] ?? $task['rule_title'] ?? null, 160);
        $summary = $this->normalizeLongString($task['summary'] ?? $task['description'] ?? $task['recommendation'] ?? null, 420);

        if ($title === null && $summary === null) {
            return null;
        }

        $owner = $this->normalizeShortString($task['owner'] ?? $task['suggestedOwner'] ?? $task['suggested_owner'] ?? $task['recommendedOwner'] ?? $task['recommended_owner'] ?? null, 80);
        $fixType = $this->normalizeShortString($task['fixType'] ?? $task['fix_type'] ?? $task['type'] ?? $task['problemType'] ?? $task['problem_type'] ?? null, 80);
        $impact = $this->normalizeShortString($task['impact'] ?? $task['severity'] ?? $task['priorityImpact'] ?? $task['priority_impact'] ?? null, 80);
        $estimatedEffort = $this->normalizeShortString($task['estimatedEffort'] ?? $task['estimated_effort'] ?? $task['effort'] ?? null, 80);
        $priority = $this->normalizeShortString($task['priority'] ?? $task['rank'] ?? $fallbackPriority, 40) ?: (string)$fallbackPriority;
        $quickWin = (bool)($task['quickWin'] ?? $task['quick_win'] ?? false);

        $steps = $this->normalizeRemediationStringList($task['steps'] ?? $task['recommendedSteps'] ?? $task['recommended_steps'] ?? [], 5, 260);
        $examples = $this->normalizeRemediationStringList($task['examples'] ?? $task['example'] ?? [], 3, 220);
        $findingsTotal = $this->normalizeNonNegativeInteger($task['findingsTotal'] ?? $task['findings_total'] ?? $task['findings'] ?? $task['issuesTotal'] ?? $task['issues_total'] ?? null);
        $affectedPagesTotal = $this->normalizeNonNegativeInteger($task['affectedPagesTotal'] ?? $task['affected_pages_total'] ?? $task['affectedPages'] ?? $task['affected_pages'] ?? $task['pages'] ?? null);

        return [
            'priority' => $priority,
            'title' => $title ?? 'Recommended remediation task',
            'summary' => $summary ?? '',
            'owner' => $owner ?? '',
            'ownerLabel' => $owner !== null ? $this->formatMachineLabel($owner) : 'Not specified',
            'fixType' => $fixType ?? '',
            'fixTypeLabel' => $fixType !== null ? $this->formatMachineLabel($fixType) : 'Not specified',
            'impact' => $impact ?? '',
            'impactLabel' => $impact !== null ? $this->formatMachineLabel($impact) : 'Not specified',
            'findingsTotal' => $findingsTotal,
            'findingsTotalLabel' => $findingsTotal !== null ? (string)$findingsTotal : '—',
            'affectedPagesTotal' => $affectedPagesTotal,
            'affectedPagesTotalLabel' => $affectedPagesTotal !== null ? (string)$affectedPagesTotal : '—',
            'quickWin' => $quickWin,
            'quickWinLabel' => $quickWin ? 'Quick win' : '',
            'estimatedEffort' => $estimatedEffort ?? '',
            'estimatedEffortLabel' => $estimatedEffort !== null ? $this->formatMachineLabel($estimatedEffort) : 'Not specified',
            'steps' => $steps,
            'hasSteps' => $steps !== [],
            'examples' => $examples,
            'hasExamples' => $examples !== [],
        ];
    }

    /**
     * @return list<string>
     */
    private function normalizeRemediationStringList(mixed $value, int $limit, int $maxLength): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['text'] ?? $item['label'] ?? $item['summary'] ?? '';
            }
            $normalized = $this->normalizeLongString($item, $maxLength);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function normalizeShortString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);
        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, max(1, $maxLength));
    }

    private function normalizeLongString(mixed $value, int $maxLength): ?string
    {
        return $this->normalizeShortString($value, $maxLength);
    }

    private function normalizeNonNegativeInteger(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return max(0, (int)$value);
    }

    private function mapRemediationPlanErrorMessage(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'remediation_plan_disabled')) {
            return 'Recommended remediation plan is not available for this scan.';
        }

        if (str_contains($normalized, '401') || str_contains($normalized, '403')) {
            return 'Recommended remediation plan is not available for this licence or environment.';
        }

        if (str_contains($normalized, '404') || str_contains($normalized, 'not_found')) {
            return 'Recommended remediation plan is not available for this scan.';
        }

        if (str_contains($normalized, 'missing_start_url') || str_contains($normalized, 'invalid_source_type_filter')) {
            return 'Recommended remediation plan is not available for this scan.';
        }

        return 'Recommended remediation plan is not available for this scan.';
    }

    private function mapRegressionAlertErrorMessage(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, 'history_disabled') || str_contains($normalized, '404')) {
            return 'Regression signal is available in PRO when enabled.';
        }

        if (str_contains($normalized, '401') || str_contains($normalized, '403')) {
            return 'Regression signal is not available for this licence or environment.';
        }

        if (str_contains($normalized, 'missing_source_type')) {
            return 'Internal configuration issue: source type is missing.';
        }

        if (str_contains($normalized, 'missing_start_url')) {
            return 'Internal configuration issue: page URL is missing.';
        }

        if (str_contains($normalized, 'invalid_source_type_filter')) {
            return 'Invalid scan type for regression signal.';
        }

        return 'Regression signal is not available right now.';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeComparePayload(array $payload, string $fromJobId, string $toJobId): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }
        $summary = is_array($payload['summary'] ?? null) ? $payload['summary'] : [];
        $byRule = $this->normalizeCompareRows($this->extractList($payload, ['byRule', 'by_rule', 'rules']));
        $byPage = $this->normalizeCompareRows($this->extractList($payload, ['byPage', 'by_page', 'pages']));
        $topChanges = $this->normalizeCompareRows($this->extractList($payload, ['topChanges', 'top_changes', 'changes']));

        $summaryData = [
            'newIssues' => max(0, (int)($summary['newIssues'] ?? $summary['new_issues'] ?? 0)),
            'resolvedIssues' => max(0, (int)($summary['resolvedIssues'] ?? $summary['resolved_issues'] ?? 0)),
            'unchangedIssues' => max(0, (int)($summary['unchangedIssues'] ?? $summary['unchanged_issues'] ?? 0)),
            'improvedPages' => max(0, (int)($summary['improvedPages'] ?? $summary['improved_pages'] ?? 0)),
            'worsenedPages' => max(0, (int)($summary['worsenedPages'] ?? $summary['worsened_pages'] ?? 0)),
            'scoreDelta' => (int)($summary['scoreDelta'] ?? $summary['score_delta'] ?? 0),
        ];
        $status = $this->resolveCompareStatus($summaryData);
        $previousScan = $this->extractCompareScanMeta($payload, ['fromScan', 'from_scan', 'previousScan', 'previous_scan', 'from', 'previous'], 'previous');
        $currentScan = $this->extractCompareScanMeta($payload, ['toScan', 'to_scan', 'currentScan', 'current_scan', 'to', 'current'], 'current');

        return [
            'available' => true,
            'fromJobId' => $fromJobId,
            'toJobId' => $toJobId,
            'contextLabel' => 'Compared with previous compatible automated scan.',
            'status' => $status['status'],
            'statusLabel' => $status['label'],
            'statusTone' => $status['tone'],
            'statusMessage' => $status['message'],
            'previousScan' => $previousScan,
            'currentScan' => $currentScan,
            'hasScanMeta' => ($previousScan['finishedAtFormatted'] ?? '') !== '' || ($currentScan['finishedAtFormatted'] ?? '') !== '',
            'summary' => $summaryData,
            'byRule' => $byRule,
            'hasByRule' => $byRule !== [],
            'byPage' => $byPage,
            'hasByPage' => $byPage !== [],
            'topChanges' => $topChanges,
            'hasTopChanges' => $topChanges !== [],
        ];
    }

    /**
     * @param array<int, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeHistoryItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $jobId = trim((string)($item['jobId'] ?? $item['job_id'] ?? ''));
            if ($jobId === '') {
                continue;
            }

            $sourceType = strtolower(trim((string)($item['sourceType'] ?? $item['source_type'] ?? '')));
            $isPageScan = $sourceType === 'single_page' || $sourceType === 'page';
            $isSiteScan = in_array($sourceType, ['site', 'sitemap', 'crawl'], true) || !$isPageScan;
            $startUrl = trim((string)($item['startUrl'] ?? $item['start_url'] ?? $item['url'] ?? ''));
            $finishedAt = $this->parseTimestamp($item['finishedAt'] ?? $item['finished_at'] ?? null);
            $createdAt = $this->parseTimestamp($item['createdAt'] ?? $item['created_at'] ?? null);
            $score = $this->normalizeNullableInt($item['score'] ?? $item['aqgScore'] ?? $item['aqg_score'] ?? null);

            $normalized[] = [
                'jobId' => $jobId,
                'jobIdShort' => substr($jobId, 0, 8) . '…',
                'siteId' => (string)($item['siteId'] ?? $item['site_id'] ?? ''),
                'status' => (string)($item['status'] ?? ''),
                'sourceType' => $sourceType,
                'sourceTypeLabel' => $isPageScan ? 'Page scan' : 'Site scan',
                'isSiteScan' => $isSiteScan,
                'isPageScan' => $isPageScan,
                'startUrl' => $startUrl,
                'normalizedStartUrl' => $this->normalizeComparableUrl($startUrl),
                'urlLabel' => $startUrl !== '' ? $startUrl : ($isSiteScan ? 'Site scan' : 'Page scan'),
                'createdAt' => $createdAt,
                'createdAtFormatted' => BackendTimeUtility::formatDateTime($createdAt, 'd.m.Y H:i'),
                'finishedAt' => $finishedAt,
                'finishedAtFormatted' => BackendTimeUtility::formatDateTime($finishedAt, 'd.m.Y H:i'),
                'pagesScanned' => max(0, (int)($item['pagesScanned'] ?? $item['pages_scanned'] ?? 0)),
                'issuesTotal' => max(0, (int)($item['issuesTotal'] ?? $item['issues_total'] ?? 0)),
                'score' => $score,
                'scoreLabel' => $score !== null ? $score . ' / 100' : '—',
                'overallImpact' => (string)($item['overallImpact'] ?? $item['overall_impact'] ?? ''),
                'critical' => max(0, (int)($item['critical'] ?? 0)),
                'serious' => max(0, (int)($item['serious'] ?? 0)),
                'moderate' => max(0, (int)($item['moderate'] ?? 0)),
                'minor' => max(0, (int)($item['minor'] ?? 0)),
                'contractVersion' => (string)($item['contractVersion'] ?? $item['contract_version'] ?? ''),
                'viewReportUrl' => '',
                'compareFromJobId' => '',
                'compareUrl' => '',
                'compareLabel' => 'No comparable previous scan found.',
                'hasComparablePrevious' => false,
            ];
        }

        usort($normalized, static function (array $a, array $b): int {
            $finished = (int)($b['finishedAt'] ?? 0) <=> (int)($a['finishedAt'] ?? 0);
            if ($finished !== 0) {
                return $finished;
            }

            return strcmp((string)($b['jobId'] ?? ''), (string)($a['jobId'] ?? ''));
        });

        return array_slice($normalized, 0, 100);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function attachComparablePrevious(array $items): array
    {
        $count = count($items);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (!$this->areHistoryItemsComparable($items[$i], $items[$j])) {
                    continue;
                }

                $items[$i]['compareFromJobId'] = (string)$items[$j]['jobId'];
                $items[$i]['hasComparablePrevious'] = true;
                $items[$i]['compareLabel'] = 'Compare with previous compatible scan';
                break;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $previous
     */
    private function areHistoryItemsComparable(array $current, array $previous): bool
    {
        if (($current['isSiteScan'] ?? false) && ($previous['isSiteScan'] ?? false)) {
            return true;
        }

        if (($current['isPageScan'] ?? false) && ($previous['isPageScan'] ?? false)) {
            return (string)($current['normalizedStartUrl'] ?? '') !== ''
                && (string)($current['normalizedStartUrl'] ?? '') === (string)($previous['normalizedStartUrl'] ?? '');
        }

        return false;
    }

    /**
     * @param array<string, int> $summary
     * @return array{status: string, label: string, tone: string, message: string}
     */
    private function resolveCompareStatus(array $summary): array
    {
        $newIssues = (int)($summary['newIssues'] ?? 0);
        $resolvedIssues = (int)($summary['resolvedIssues'] ?? 0);
        $scoreDelta = (int)($summary['scoreDelta'] ?? 0);

        if ($scoreDelta < 0 || $newIssues > $resolvedIssues) {
            return [
                'status' => 'regression',
                'label' => 'Regression signal',
                'tone' => 'warning',
                'message' => 'The latest automated scan found more issues than the previous comparable scan. Review before publishing.',
            ];
        }

        if ($scoreDelta > 0 || $resolvedIssues > $newIssues) {
            return [
                'status' => 'improved',
                'label' => 'Improved',
                'tone' => 'positive',
                'message' => 'The latest automated scan found fewer issues than the previous comparable scan. Manual review may still be required.',
            ];
        }

        return [
            'status' => 'stable',
            'label' => 'Stable',
            'tone' => 'neutral',
            'message' => 'No meaningful regression detected. Findings may have changed, but total issues and score stayed stable.',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function extractCompareScanMeta(array $payload, array $keys, string $prefix): array
    {
        $scan = [];
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $scan = $payload[$key];
                break;
            }
        }

        $finishedAt = $this->parseTimestamp(
            $scan['finishedAt']
            ?? $scan['finished_at']
            ?? $payload[$prefix . 'FinishedAt']
            ?? $payload[$prefix . '_finished_at']
            ?? null
        );
        $jobId = trim((string)(
            $scan['jobId']
            ?? $scan['job_id']
            ?? $payload[$prefix . 'JobId']
            ?? $payload[$prefix . '_job_id']
            ?? ''
        ));

        return [
            'jobId' => $jobId,
            'finishedAt' => $finishedAt,
            'finishedAtFormatted' => $finishedAt > 0 ? BackendTimeUtility::formatDateTime($finishedAt, 'd.m.Y H:i') : '',
        ];
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCompareRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = $this->normalizeNullableString(
                $row['ruleId']
                    ?? $row['rule_id']
                    ?? $row['url']
                    ?? $row['pageUrl']
                    ?? $row['page_url']
                    ?? $row['title']
                    ?? $row['label']
                    ?? $row['id']
                    ?? $row['message']
                    ?? null
            );

            $status = strtolower(trim((string)($row['status'] ?? $row['changeStatus'] ?? $row['change_status'] ?? '')));
            $apiType = strtolower(trim((string)($row['type'] ?? '')));
            $legacyChangeType = strtolower(trim((string)($row['changeType'] ?? $row['change_type'] ?? '')));
            $delta = (int)($row['delta'] ?? 0);
            $fromTotal = max(0, (int)($row['fromIssuesTotal'] ?? $row['from_issues_total'] ?? 0));
            $toTotal = max(0, (int)($row['toIssuesTotal'] ?? $row['to_issues_total'] ?? 0));
            $effectiveDelta = $delta !== 0 ? $delta : $toTotal - $fromTotal;

            $newIssues = max(0, (int)($row['newIssues'] ?? $row['new_issues'] ?? 0));
            $resolvedIssues = max(0, (int)($row['resolvedIssues'] ?? $row['resolved_issues'] ?? 0));
            $unchangedIssues = max(0, (int)($row['unchangedIssues'] ?? $row['unchanged_issues'] ?? 0));

            if ($newIssues === 0 && $resolvedIssues === 0 && $unchangedIssues === 0) {
                if ($status === 'new' || $status === 'worsened' || $effectiveDelta > 0) {
                    $newIssues = abs($effectiveDelta);
                } elseif ($status === 'resolved' || $status === 'improved' || $effectiveDelta < 0) {
                    $resolvedIssues = abs($effectiveDelta);
                } elseif ($status === 'unchanged') {
                    $unchangedIssues = $toTotal > 0 ? $toTotal : $fromTotal;
                }
            }

            $changeStatus = $status !== '' ? $status : ($legacyChangeType !== '' ? $legacyChangeType : 'changed');
            $issueSummaryLabel = $this->buildCompareIssueSummaryLabel($newIssues, $resolvedIssues, $unchangedIssues);

            $normalized[] = [
                'label' => $label ?? 'Change',
                'changeType' => $changeStatus,
                'changeTypeLabel' => $this->formatMachineLabel($changeStatus),
                'apiType' => $apiType,
                'apiTypeLabel' => $this->formatMachineLabel($apiType),
                'status' => $changeStatus,
                'statusLabel' => $this->formatMachineLabel($changeStatus),
                'fromIssuesTotal' => $fromTotal,
                'toIssuesTotal' => $toTotal,
                'delta' => $effectiveDelta,
                'newIssues' => $newIssues,
                'resolvedIssues' => $resolvedIssues,
                'unchangedIssues' => $unchangedIssues,
                'issueSummaryLabel' => $issueSummaryLabel,
                'scoreDelta' => (int)($row['scoreDelta'] ?? $row['score_delta'] ?? 0),
                'note' => $this->normalizeNullableString($row['summary'] ?? $row['message'] ?? $row['note'] ?? null),
                'tone' => $this->resolveCompareTone($changeStatus, $effectiveDelta, $newIssues, $resolvedIssues),
            ];
        }

        return array_slice($normalized, 0, 10);
    }

    private function resolveCompareTone(string $status, int $delta, int $newIssues, int $resolvedIssues): string
    {
        if (in_array($status, ['resolved', 'improved'], true) || $delta < 0 || $resolvedIssues > 0) {
            return 'positive';
        }
        if (in_array($status, ['new', 'worsened'], true) || $delta > 0 || $newIssues > 0) {
            return 'warning';
        }

        return 'neutral';
    }

    private function buildCompareIssueSummaryLabel(int $newIssues, int $resolvedIssues, int $unchangedIssues): string
    {
        $parts = [];
        if ($newIssues > 0) {
            $parts[] = $newIssues . ' new in automated scan';
        }
        if ($resolvedIssues > 0) {
            $parts[] = $resolvedIssues . ' resolved in automated scan';
        }
        if ($unchangedIssues > 0) {
            $parts[] = $unchangedIssues . ' unchanged in automated scan';
        }

        return $parts !== [] ? implode(' · ', $parts) : 'No issue count change reported.';
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $keys
     * @return array<int, mixed>
     */
    private function extractList(array $payload, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_is_list($payload[$key]) ? array_values($payload[$key]) : [];
            }
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            foreach ($keys as $key) {
                if (isset($payload['data'][$key]) && is_array($payload['data'][$key])) {
                    return array_is_list($payload['data'][$key]) ? array_values($payload['data'][$key]) : [];
                }
            }
        }

        return array_is_list($payload) ? array_values($payload) : [];
    }

    private function emptyHistory(string $message): array
    {
        return [
            'available' => false,
            'hasItems' => false,
            'hasAnyRows' => false,
            'showScopedHistory' => false,
            'showGlobalEmpty' => true,
            'message' => $message,
            'items' => [],
            'siteScans' => [],
            'pageScans' => [],
            'siteScansCount' => 0,
            'pageScansCount' => 0,
            'hasSiteScans' => false,
            'hasPageScans' => false,
        ];
    }

    private function mapRemoteHistoryErrorMessage(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'history_disabled') || str_contains($lower, '404')) {
            return 'Remote scan history is available in PRO when enabled.';
        }
        if (str_contains($lower, 'domain_mismatch') || str_contains($lower, '403')) {
            return 'The selected scan does not belong to this site/domain.';
        }
        if (str_contains($lower, '401') || str_contains($lower, 'unauthorized')) {
            return 'Scan history is not available for this licence or environment.';
        }

        return 'Remote scan history is not available for this licence or environment.';
    }

    private function mapRemoteCompareErrorMessage(string $message): string
    {
        $lower = strtolower($message);
        if (str_contains($lower, 'invalid_compare_jobs') || str_contains($lower, '400')) {
            return 'The selected scans cannot be compared.';
        }
        if (str_contains($lower, 'domain_mismatch') || str_contains($lower, '403')) {
            return 'The selected scan does not belong to this site/domain.';
        }
        if (str_contains($lower, '401') || str_contains($lower, 'unauthorized')) {
            return 'Scan history is not available for this licence or environment.';
        }

        return 'The selected scans cannot be compared.';
    }

    private function normalizeComparableUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return strtolower(rtrim($url, '/'));
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = rtrim((string)($parts['path'] ?? ''), '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . (string)$parts['query'] : '';

        return ($scheme !== '' ? $scheme . '://' : '') . $host . $path . $query;
    }

    private function parseTimestamp(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_numeric($value)) {
            return max(0, (int)$value);
        }
        $normalized = trim((string)$value);
        if ($normalized === '') {
            return 0;
        }
        $timestamp = strtotime($normalized);

        return $timestamp !== false ? $timestamp : 0;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }

        return max(0, min(100, (int)$value));
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $normalized = trim((string)$value);

        return $normalized !== '' ? mb_substr($normalized, 0, 240) : null;
    }

    private function formatMachineLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function logHistoryError(string $message, \Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->warning($message, [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
        } catch (\Throwable) {
        }
    }
}
