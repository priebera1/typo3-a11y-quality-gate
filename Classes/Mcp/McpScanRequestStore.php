<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * Durable idempotency of `aqg_start_scan` (second Sol audit A4).
 *
 * An agent's idempotencyKey is reserved in tx_a11y_mcp_scan_request (unique per TYPO3 user and key, with the request's
 * fingerprint: site, page, language) before anything is submitted, so the reservation survives restarts and is shared by
 * every connector process. The crawler submit carries an Idempotency-Key derived from that reservation's random nonce
 * (keyed with this installation's encryption key): every attempt for one reservation — a retry after a timeout, a second connector
 * process, a retry after a crash between the crawler's answer and the local record — sends the same key, and the crawler
 * keeps one run per key and licence (unique index on scan_runs, request fingerprint checked) and answers repeats with
 * that run. Attempts on one host are serialized by a TYPO3 lock; across hosts the crawler's unique key decides.
 *
 * Contract: at most one crawler run per (TYPO3 user, agent key) on this installation. This is not exactly-once
 * execution: if no attempt ever reaches the crawler, no scan runs; if the crawler accepted a run but every answer was
 * lost and the agent never retries, the run exists at the crawler without a local record until a retry with the same
 * key replays it. A reservation is kept for TTL_SECONDS; a key reused after that is a new reservation with a new nonce,
 * so it starts a new scan and never returns an old run as a new one.
 */
final class McpScanRequestStore
{
    public const TTL_SECONDS = 7 * 86400;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly LockFactory $lockFactory,
    ) {
    }

    public function requestHash(int $beUserUid, string $idempotencyKey): string
    {
        return hash('sha256', 'mcp-scan|' . $beUserUid . '|' . $idempotencyKey);
    }

    public static function fingerprint(string $siteIdentifier, int $pageUid, int $languageUid): string
    {
        return hash('sha256', $siteIdentifier . '|' . $pageUid . '|' . $languageUid);
    }

    /** The crawler Idempotency-Key of a reservation (its nonce): stable for that reservation, never the agent's own key. */
    public function crawlerKey(string $nonce): string
    {
        $secret = (string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '');

        return 'aqg-mcp-' . substr(hash_hmac('sha256', 'crawler-submit|' . $nonce, $secret), 0, 40);
    }

    public static function isCrawlerKey(string $value): bool
    {
        return preg_match('/^aqg-mcp-[a-f0-9]{40}$/', $value) === 1;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function exclusively(string $requestHash, callable $callback): mixed
    {
        $lock = $this->lockFactory->createLocker('a11y_mcp_scan_' . substr($requestHash, 0, 40), LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Reserves the key for this request, or returns what an earlier attempt left:
     *   new       — reserved now, nothing was submitted before
     *   reserved  — an earlier attempt reserved it and may or may not have reached the crawler (submit again: same key)
     *   submitted — the crawler accepted it as `jobId`
     *   conflict  — the key was used for a different request (fingerprint)
     *
     * @return array{state: 'new'|'reserved'|'submitted'|'conflict', jobId: string, nonce: string}
     */
    public function reserve(string $requestHash, string $fingerprint, int $beUserUid, int $attempt = 0): array
    {
        $connection = $this->connectionPool->getConnectionForTable(Tables::MCP_SCAN_REQUEST);
        $now = time();
        $connection->executeStatement(
            'DELETE FROM ' . Tables::MCP_SCAN_REQUEST . ' WHERE crdate < ?',
            [$now - self::TTL_SECONDS],
            [Connection::PARAM_INT],
        );
        $nonce = bin2hex(random_bytes(16));
        try {
            $connection->insert(Tables::MCP_SCAN_REQUEST, [
                'request_hash' => $requestHash,
                'nonce' => $nonce,
                'fingerprint' => $fingerprint,
                'be_user_uid' => $beUserUid,
                'status' => 'reserved',
                'job_id' => '',
                'crdate' => $now,
                'tstamp' => $now,
            ]);

            return ['state' => 'new', 'jobId' => '', 'nonce' => $nonce];
        } catch (UniqueConstraintViolationException) {
            // Reserved by an earlier attempt (this or another process): decided by what it stored.
        }
        $row = $connection->select(['fingerprint', 'status', 'job_id', 'nonce'], Tables::MCP_SCAN_REQUEST, ['request_hash' => $requestHash])->fetchAssociative();
        if (!is_array($row) || (string)$row['nonce'] === '') {
            if ($attempt === 0 && !is_array($row)) {
                // Pruned between the insert and this read: reserve it again.
                return $this->reserve($requestHash, $fingerprint, $beUserUid, 1);
            }
            throw new \RuntimeException('The scan request reservation could not be read.', 1793262301);
        }
        if (!hash_equals((string)$row['fingerprint'], $fingerprint)) {
            return ['state' => 'conflict', 'jobId' => '', 'nonce' => ''];
        }
        $jobId = (string)$row['job_id'];

        return ['state' => $row['status'] === 'submitted' && $jobId !== '' ? 'submitted' : 'reserved', 'jobId' => $jobId, 'nonce' => (string)$row['nonce']];
    }

    public function markSubmitted(string $requestHash, string $jobId): void
    {
        $this->connectionPool->getConnectionForTable(Tables::MCP_SCAN_REQUEST)->update(
            Tables::MCP_SCAN_REQUEST,
            ['status' => 'submitted', 'job_id' => mb_substr($jobId, 0, 64), 'tstamp' => time()],
            ['request_hash' => $requestHash],
        );
    }

    public function prune(): int
    {
        return (int)$this->connectionPool->getConnectionForTable(Tables::MCP_SCAN_REQUEST)->executeStatement(
            'DELETE FROM ' . Tables::MCP_SCAN_REQUEST . ' WHERE crdate < ?',
            [time() - self::TTL_SECONDS],
            [Connection::PARAM_INT],
        );
    }
}
