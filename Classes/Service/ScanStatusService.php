<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Concurrency\DatabaseMutex;
use Priebera\A11yQualityGate\Scan\ScanResult;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ScanStatusService
{
    private const REGISTRY_NAMESPACE = 'a11y_quality_gate';
    private const REGISTRY_KEY = 'scan_status';

    /** NEW-06: the installation-wide content scan lock is its own sys_registry row (UNIQUE namespace + key). */
    private const LOCK_NAMESPACE = 'a11y_quality_gate_lock';
    private const LOCK_KEY = 'content_scan';
    /** A lock of a process that died frees itself after this many seconds (no permanent "running" state). */
    public const LOCK_TTL = 1800;

    public function __construct(
        private readonly Registry $registry,
        private readonly ?ConnectionPool $connectionPool = null,
    ) {
    }

    /**
     * NEW-06: atomically claims the one content scan allowed at a time. Returns a token for releaseScanLock(), or
     * null when another scan holds the lock. The claim is an INSERT on the unique sys_registry key, so two PHP
     * processes can never both win; an expired lock (its process died) is taken over with a compare-and-delete.
     */
    public function acquireScanLock(): ?string
    {
        $token = bin2hex(random_bytes(16));
        $value = serialize(['token' => $token, 'expiresAt' => time() + self::LOCK_TTL]);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $this->lockConnection()->insert('sys_registry', ['entry_namespace' => self::LOCK_NAMESPACE, 'entry_key' => self::LOCK_KEY, 'entry_value' => $value], DatabaseMutex::ROW_TYPES);

                return $token;
            } catch (UniqueConstraintViolationException) {
                $current = $this->readLockRow();
                if ($current === null) {
                    continue;
                }
                if ((int)($current['lock']['expiresAt'] ?? 0) > time()) {
                    return null;
                }
                // Expired: remove exactly the stale row we read (another process may be taking it over right now).
                $this->deleteLockRow($current);
            }
        }

        return null;
    }

    public function releaseScanLock(string $token): void
    {
        $current = $this->readLockRow();
        if ($current !== null && (string)($current['lock']['token'] ?? '') === $token) {
            $this->deleteLockRow($current);
        }
    }

    /**
     * Keeps the lock of a scan that is still working (independent review P2-1): a site scan may run longer than
     * LOCK_TTL. Called once per scanned page; renews for a full TTL once half of it is used. Only the owner renews, by
     * compare-and-swap on the row it read, so a lock that expired and was taken over is never taken back.
     */
    public function renewScanLock(string $token): bool
    {
        $current = $this->readLockRow();
        if ($current === null || (string)($current['lock']['token'] ?? '') !== $token) {
            return false;
        }
        $expiresAt = (int)($current['lock']['expiresAt'] ?? 0);
        if ($expiresAt <= time()) {
            return false;
        }
        if ($expiresAt - time() > intdiv(self::LOCK_TTL, 2)) {
            return true;
        }

        return $this->lockConnection()->update(
            'sys_registry',
            ['entry_value' => serialize(['token' => $token, 'expiresAt' => time() + self::LOCK_TTL])],
            ['uid' => $current['uid'], 'entry_value' => $current['raw']],
            // Fable E-1: entry_value is a BLOB; bound as TEXT the compare-and-swap never matched on SQLite.
            ['uid' => \TYPO3\CMS\Core\Database\Connection::PARAM_INT, 'entry_value' => \TYPO3\CMS\Core\Database\Connection::PARAM_LOB]
        ) === 1;
    }

    public function isScanLockHeld(): bool
    {
        $current = $this->readLockRow();

        return $current !== null && (int)($current['lock']['expiresAt'] ?? 0) > time();
    }

    /** @return array{uid:int, raw:string, lock:array<string, mixed>}|null */
    private function readLockRow(): ?array
    {
        $row = $this->lockConnection()->select(['uid', 'entry_value'], 'sys_registry', ['entry_namespace' => self::LOCK_NAMESPACE, 'entry_key' => self::LOCK_KEY])->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }
        $raw = DatabaseMutex::blobToString($row['entry_value']);
        $lock = @unserialize($raw, ['allowed_classes' => false]);

        return ['uid' => (int)$row['uid'], 'raw' => $raw, 'lock' => is_array($lock) ? $lock : []];
    }

    /**
     * Compare-and-delete of the lock row as read, the value bound as a LOB like the INSERT (Fable E-1: SQLite never
     * equals a BLOB with TEXT, so release and takeover deleted nothing and every later scan was "already running").
     *
     * @param array{uid:int, raw:string, lock:array<string, mixed>} $row
     */
    private function deleteLockRow(array $row): int
    {
        return (int)$this->lockConnection()->delete(
            'sys_registry',
            ['uid' => $row['uid'], 'entry_value' => $row['raw']],
            ['uid' => \TYPO3\CMS\Core\Database\Connection::PARAM_INT, 'entry_value' => \TYPO3\CMS\Core\Database\Connection::PARAM_LOB]
        );
    }

    private function lockConnection(): \TYPO3\CMS\Core\Database\Connection
    {
        return ($this->connectionPool ?? GeneralUtility::makeInstance(ConnectionPool::class))->getConnectionForTable('sys_registry');
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

        // NEW-06: "running" is only true while a live scan lock exists. A scan whose process died (fatal error, killed
        // PHP worker) leaves the flag set; once its lock expired it no longer blocks or misinforms anybody.
        if ((bool)($status['running'] ?? false) && !$this->isScanLockHeld()) {
            $status['running'] = false;
            $status['interrupted'] = true;
        }

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
                'warnings' => $scanResult->warnings,
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
