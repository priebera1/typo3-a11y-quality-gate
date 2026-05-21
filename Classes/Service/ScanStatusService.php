<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Scan\ScanResult;
use TYPO3\CMS\Core\Core\Environment;
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
        $status = is_array($status) ? $status : [
            'running' => false,
        ];

        if ((bool)($status['running'] ?? false) && $this->cancellationTokenExists()) {
            $status['cancelRequested'] = true;
        }

        return $status;
    }

    public function isRunning(): bool
    {
        return (bool)($this->getStatus()['running'] ?? false);
    }

    public function markRunning(string $trigger, string $triggeredBy, ?int $pageUid = null, ?int $rootPid = null, int $languageUid = -1): void
    {
        $this->clearCancellationToken();

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'running' => true,
            'startedAt' => time(),
            'finishedAt' => null,
            'trigger' => $trigger,
            'triggeredBy' => $triggeredBy,
            'pageUid' => $pageUid,
            'rootPid' => $rootPid,
            'languageUid' => $languageUid,
            'scanUid' => null,
            'summary' => null,
            'error' => null,
            'cancelRequested' => false,
        ]);
    }

    public function markScanRunStarted(int $scanUid): void
    {
        if ($scanUid <= 0) {
            return;
        }

        $current = $this->getStatus();
        $current['scanUid'] = $scanUid;

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $current);
    }

    public function markFinished(ScanResult $scanResult): void
    {
        $current = $this->getStatus();
        $this->clearCancellationToken();

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'running' => false,
            'startedAt' => $current['startedAt'] ?? null,
            'finishedAt' => time(),
            'trigger' => $current['trigger'] ?? null,
            'triggeredBy' => $current['triggeredBy'] ?? null,
            'pageUid' => $current['pageUid'] ?? null,
            'rootPid' => $current['rootPid'] ?? null,
            'languageUid' => $current['languageUid'] ?? -1,
            'scanUid' => $current['scanUid'] ?? null,
            'summary' => [
                'scanUid' => $scanResult->scanUid,
                'pagesScanned' => $scanResult->pagesScanned,
                'recordsScanned' => $scanResult->recordsScanned,
                'issuesNew' => $scanResult->issuesNew,
                'issuesResolved' => $scanResult->issuesResolved,
                'issuesIgnored' => $scanResult->issuesIgnored,
            ],
            'error' => null,
            'cancelRequested' => false,
        ]);
    }

    public function markFailed(string $message): void
    {
        $current = $this->getStatus();
        $this->clearCancellationToken();

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'running' => false,
            'startedAt' => $current['startedAt'] ?? null,
            'finishedAt' => time(),
            'trigger' => $current['trigger'] ?? null,
            'triggeredBy' => $current['triggeredBy'] ?? null,
            'pageUid' => $current['pageUid'] ?? null,
            'rootPid' => $current['rootPid'] ?? null,
            'languageUid' => $current['languageUid'] ?? -1,
            'scanUid' => $current['scanUid'] ?? null,
            'summary' => $current['summary'] ?? null,
            'error' => $message,
            'cancelRequested' => false,
        ]);
    }

    public function requestCancellation(): void
    {
        $current = $this->getStatus();

        if (!((bool)($current['running'] ?? false))) {
            $this->clearCancellationToken();
            return;
        }

        $current['cancelRequested'] = true;

        $this->writeCancellationToken();
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $current);
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancellationTokenExists() || (bool)($this->getStatus()['cancelRequested'] ?? false);
    }

    public function markCancelled(): void
    {
        $current = $this->getStatus();
        $this->clearCancellationToken();

        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, [
            'running' => false,
            'startedAt' => $current['startedAt'] ?? null,
            'finishedAt' => time(),
            'trigger' => $current['trigger'] ?? null,
            'triggeredBy' => $current['triggeredBy'] ?? null,
            'pageUid' => $current['pageUid'] ?? null,
            'rootPid' => $current['rootPid'] ?? null,
            'languageUid' => $current['languageUid'] ?? -1,
            'scanUid' => $current['scanUid'] ?? null,
            'summary' => $current['summary'] ?? null,
            'error' => null,
            'cancelRequested' => false,
            'cancelled' => true,
        ]);
    }

    private function writeCancellationToken(): void
    {
        $file = $this->getCancellationTokenFile();
        $directory = dirname($file);

        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        @file_put_contents($file, (string)time(), LOCK_EX);
    }

    private function clearCancellationToken(): void
    {
        $file = $this->getCancellationTokenFile();

        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function cancellationTokenExists(): bool
    {
        return is_file($this->getCancellationTokenFile());
    }

    private function getCancellationTokenFile(): string
    {
        return Environment::getVarPath() . '/transient/a11y_quality_gate/local_scan_cancel.token';
    }
}
