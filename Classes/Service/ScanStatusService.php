<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Scan\ScanResult;
use TYPO3\CMS\Core\Registry;

final class ScanStatusService
{
    private const REGISTRY_NAMESPACE = 'a11y_quality_gate';
    private const REGISTRY_KEY = 'scan_status';

    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        $status = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY);

        return is_array($status) ? $status : [
            'running' => false,
        ];
    }

    public function isRunning(): bool
    {
        return (bool)($this->getStatus()['running'] ?? false);
    }

    public function markRunning(string $trigger, string $triggeredBy, ?int $pageUid = null, ?int $rootPid = null): void
    {
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'running' => true,
            'startedAt' => time(),
            'finishedAt' => null,
            'trigger' => $trigger,
            'triggeredBy' => $triggeredBy,
            'pageUid' => $pageUid,
            'rootPid' => $rootPid,
            'summary' => null,
            'error' => null,
        ]);
    }

    public function markFinished(ScanResult $scanResult): void
    {
        $current = $this->getStatus();

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'running' => false,
            'startedAt' => $current['startedAt'] ?? null,
            'finishedAt' => time(),
            'trigger' => $current['trigger'] ?? null,
            'triggeredBy' => $current['triggeredBy'] ?? null,
            'pageUid' => $current['pageUid'] ?? null,
            'rootPid' => $current['rootPid'] ?? null,
            'summary' => [
                'scanUid' => $scanResult->scanUid,
                'pagesScanned' => $scanResult->pagesScanned,
                'recordsScanned' => $scanResult->recordsScanned,
                'issuesNew' => $scanResult->issuesNew,
                'issuesResolved' => $scanResult->issuesResolved,
                'issuesIgnored' => $scanResult->issuesIgnored,
            ],
            'error' => null,
        ]);
    }

    public function markFailed(string $message): void
    {
        $current = $this->getStatus();

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'running' => false,
            'startedAt' => $current['startedAt'] ?? null,
            'finishedAt' => time(),
            'trigger' => $current['trigger'] ?? null,
            'triggeredBy' => $current['triggeredBy'] ?? null,
            'pageUid' => $current['pageUid'] ?? null,
            'rootPid' => $current['rootPid'] ?? null,
            'summary' => $current['summary'] ?? null,
            'error' => $message,
        ]);
    }
}
