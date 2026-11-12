<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Concurrency;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * A short-lived mutual exclusion shared by every PHP process of the installation (resilience audit NEW-09).
 *
 * The lock is a sys_registry row: its UNIQUE (entry_namespace, entry_key) index makes the INSERT atomic across
 * PHP-FPM workers and web servers that share the database, unlike a file or in-memory lock. A holder that died
 * (fatal error, killed worker) frees the lock after its TTL; the takeover deletes exactly the stale row it read.
 */
final class DatabaseMutex
{
    private const NAMESPACE = 'a11y_quality_gate_mutex';
    /** Column types of a lock row: entry_value is a BLOB on every platform (see deleteRow()). */
    public const ROW_TYPES = ['entry_namespace' => Connection::PARAM_STR, 'entry_key' => Connection::PARAM_STR, 'entry_value' => Connection::PARAM_LOB];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * Claims $key, waiting up to $waitMilliseconds for a current holder. Returns a token for release(), or null.
     */
    public function acquire(string $key, int $ttlSeconds, int $waitMilliseconds = 0): ?string
    {
        $key = substr($key, 0, 128);
        $token = bin2hex(random_bytes(16));
        $deadline = microtime(true) + max(0, $waitMilliseconds) / 1000;
        // Bounded even when every attempt finds an expired row it cannot remove (Fable E-1: the takeover used to
        // `continue` past the deadline check and spun forever when its compare-and-delete matched nothing).
        $takeovers = 0;
        do {
            $value = serialize(['token' => $token, 'expiresAt' => time() + max(1, $ttlSeconds)]);
            try {
                $this->connection()->insert('sys_registry', ['entry_namespace' => self::NAMESPACE, 'entry_key' => $key, 'entry_value' => $value], self::ROW_TYPES);

                return $token;
            } catch (UniqueConstraintViolationException) {
                $current = $this->read($key);
                if ($current !== null && (int)($current['lock']['expiresAt'] ?? 0) <= time()) {
                    // Remove exactly the stale row we read; one immediate retry per removal, never past the deadline.
                    if ($this->deleteRow($current) === 1 && $takeovers++ < 3) {
                        continue;
                    }
                } elseif ($current === null && $takeovers++ < 3) {
                    // Released between the INSERT and the read: try again at once.
                    continue;
                }
            }
            if (microtime(true) >= $deadline) {
                return null;
            }
            usleep(25000);
        } while (true);
    }

    public function release(string $key, string $token): void
    {
        $current = $this->read(substr($key, 0, 128));
        if ($current !== null && (string)($current['lock']['token'] ?? '') === $token) {
            $this->deleteRow($current);
        }
    }

    /**
     * Compare-and-delete of the row as read. entry_value is a BLOB column: the value is bound as a LOB here as on the
     * INSERT, because SQLite never equals a BLOB with TEXT (Fable E-1: every release and takeover matched nothing there,
     * so the first lock stayed until its TTL) and PostgreSQL compares bytea only with bytea.
     *
     * @param array{uid:int, raw:string, lock:array<string, mixed>} $row
     */
    private function deleteRow(array $row): int
    {
        return (int)$this->connection()->delete(
            'sys_registry',
            ['uid' => $row['uid'], 'entry_value' => $row['raw']],
            ['uid' => Connection::PARAM_INT, 'entry_value' => Connection::PARAM_LOB]
        );
    }

    /** @return array{uid:int, raw:string, lock:array<string, mixed>}|null */
    private function read(string $key): ?array
    {
        $row = $this->connection()->select(['uid', 'entry_value'], 'sys_registry', ['entry_namespace' => self::NAMESPACE, 'entry_key' => $key])->fetchAssociative();
        if (!is_array($row)) {
            return null;
        }
        $raw = self::blobToString($row['entry_value']);
        $lock = @unserialize($raw, ['allowed_classes' => false]);

        return ['uid' => (int)$row['uid'], 'raw' => $raw, 'lock' => is_array($lock) ? $lock : []];
    }

    /** PostgreSQL (bytea) returns a stream, the other platforms a string. */
    public static function blobToString(mixed $value): string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }

        return is_string($value) ? $value : '';
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable('sys_registry');
    }
}
