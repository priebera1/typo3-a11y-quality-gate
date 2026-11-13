<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Service\InstallationIdentityService;
use Priebera\A11yQualityGate\Service\SecretEncryptionService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Registry;

/**
 * This installation's pairing with the AQG MCP Gateway, kept in sys_registry. The connector secret and the pairing's
 * poll secret are stored encrypted (SecretEncryptionService) and never rendered; the server URL is not a secret.
 *
 * Pairing transitions are compare-and-set on the pairing id under an exclusive TYPO3 lock (Sol audit F4): a completion
 * that arrives after the pairing was cancelled or replaced is not stored, so it can never become connected. State is
 * read from the database on every call, not from the Registry's per-process cache, because the backend (web) and the
 * long-running connector (CLI) both change it. The lock covers every process of one host; a TYPO3 cluster needs a
 * shared locking strategy for these transitions (the Gateway's activation step still refuses a cancelled pairing).
 *
 * Every stored connector secret has its own credential id (second Sol audit A5). An answer to a request made with one
 * credential — activation success or refusal, a disconnect — changes the stored state only while that same credential
 * is still stored, so a late answer about connection A can never alter connection B that replaced it meanwhile.
 *
 * Clone safety (N2): the secrets are sealed with this host's key (McpHostKey) inside TYPO3's encryption, and the
 * connection records the installation fingerprint and the host key id it was made with. A stored connection is usable
 * only on a host with that key and while the installation keeps that identity; otherwise it is reported as unusable
 * (`unusableReason`) and no secret is handed out — no Gateway call is possible from a database copy, and nothing is
 * wiped or regenerated automatically. resetLocal() forgets the connection here without asking the Gateway (a copy must
 * never disconnect: it holds the original's credential and would end the original's connection).
 */
final class McpConnectionStore
{
    private const REGISTRY_NAMESPACE = 'a11y_quality_gate';
    private const REGISTRY_KEY = 'mcp_connection';
    private const LOCK_KEY = 'a11y_quality_gate_mcp_connection';
    /** Connections sealed with the host key and bound to the installation identity (N2). Older ones must be paired again. */
    private const FORMAT = 2;
    private const SEAL_PREFIX = 'mcphk:v1:';

    public function __construct(
        private readonly Registry $registry,
        private readonly SecretEncryptionService $secretEncryptionService,
        private readonly ConnectionPool $connectionPool,
        private readonly LockFactory $lockFactory,
        private readonly McpHostKey $hostKey,
        private readonly InstallationIdentityService $installationIdentityService,
    ) {
    }

    /**
     * @return array{status: string, slug: string, serverUrl: string, pairedAt: int, pairedBy: string, pairingId: string, credentialId: string, code: string, expiresAt: int, confirmUrl: string, disconnectedAt: int, disconnectedBy: string, activationPending: bool, unusableReason: string}
     */
    public function get(): array
    {
        $stored = $this->read();

        return [
            'status' => in_array($stored['status'] ?? '', ['pairing', 'connected', 'disconnected'], true) ? (string)$stored['status'] : 'none',
            'slug' => (string)($stored['slug'] ?? ''),
            'serverUrl' => (string)($stored['serverUrl'] ?? ''),
            'pairedAt' => (int)($stored['pairedAt'] ?? 0),
            'pairedBy' => (string)($stored['pairedBy'] ?? ''),
            'pairingId' => (string)($stored['pairingId'] ?? ''),
            'credentialId' => (string)($stored['credentialId'] ?? ''),
            'code' => (string)($stored['code'] ?? ''),
            'expiresAt' => (int)($stored['expiresAt'] ?? 0),
            'confirmUrl' => (string)($stored['confirmUrl'] ?? ''),
            'disconnectedAt' => (int)($stored['disconnectedAt'] ?? 0),
            'disconnectedBy' => (string)($stored['disconnectedBy'] ?? ''),
            'activationPending' => (bool)($stored['activationPending'] ?? false),
            'unusableReason' => $this->unusableReason($stored),
        ];
    }

    public function getConnectorSecret(): string
    {
        $stored = $this->read();
        $secret = (string)($stored['connectorSecret'] ?? '');
        if ($secret === '' || ($stored['status'] ?? '') !== 'connected' || $this->unusableReason($stored) !== '') {
            return '';
        }

        return $this->open($secret);
    }

    /**
     * The stored connection's credential as one consistent read: its id, the secret and whether it awaits activation.
     * A request sent with this secret reports back with this id.
     *
     * @return array{credentialId: string, secret: string, activationPending: bool}|null null while not connected
     */
    public function currentCredential(): ?array
    {
        $stored = $this->read();
        $secret = (string)($stored['connectorSecret'] ?? '');
        if ($secret === '' || ($stored['status'] ?? '') !== 'connected' || $this->unusableReason($stored) !== '') {
            return null;
        }
        $opened = $this->open($secret);
        if ($opened === '') {
            return null;
        }

        return [
            'credentialId' => (string)($stored['credentialId'] ?? ''),
            'secret' => $opened,
            'activationPending' => (bool)($stored['activationPending'] ?? false),
        ];
    }

    public function getPollSecret(): string
    {
        $stored = $this->read();
        $secret = (string)($stored['pollSecret'] ?? '');

        return $secret !== '' && $this->unusableReason($stored) === '' ? $this->open($secret) : '';
    }

    /** Refused (false) while this installation is connected: its secret must not be overwritten by a new pairing. */
    public function startPairing(string $pairingId, string $code, string $pollSecret, int $expiresAt, string $confirmUrl, string $by): bool
    {
        return $this->locked(function () use ($pairingId, $code, $pollSecret, $expiresAt, $confirmUrl, $by): bool {
            if ($this->get()['status'] === 'connected') {
                return false;
            }
            // The key is created with the first pairing of this host (never while another connection is stored).
            $key = $this->hostKey->ensure();
            $this->write($this->binding($key) + [
                'status' => 'pairing',
                'pairingId' => $pairingId,
                'code' => $code,
                'pollSecret' => $this->seal($pollSecret, $key),
                'expiresAt' => $expiresAt,
                'confirmUrl' => $confirmUrl,
                'pairedBy' => $by,
            ]);

            return true;
        });
    }

    /**
     * Stores the connector secret only while this exact pairing is still the one this installation waits for. The
     * installation stays pending activation at the Gateway until markActivatedIfCurrent().
     */
    public function completePairingIfCurrent(string $pairingId, string $connectorSecret, string $slug, string $serverUrl): bool
    {
        return $this->locked(function () use ($pairingId, $connectorSecret, $slug, $serverUrl): bool {
            $current = $this->get();
            if ($pairingId === '' || $current['status'] !== 'pairing' || $current['pairingId'] !== $pairingId || $current['unusableReason'] !== '') {
                return false;
            }
            $key = $this->hostKey->current();
            if ($key === null) {
                return false;
            }
            $this->write($this->binding($key) + [
                'status' => 'connected',
                'connectorSecret' => $this->seal($connectorSecret, $key),
                'slug' => $slug,
                'serverUrl' => $serverUrl,
                'pairedAt' => time(),
                'pairedBy' => $current['pairedBy'],
                'pairingId' => $pairingId,
                'credentialId' => bin2hex(random_bytes(16)),
                'activationPending' => true,
            ]);

            return true;
        });
    }

    /** The Gateway activated the credential `$credentialId`; a connection stored meanwhile keeps its own state. */
    public function markActivatedIfCurrent(string $credentialId): bool
    {
        return $this->locked(function () use ($credentialId): bool {
            $stored = $this->read();
            if (($stored['status'] ?? '') !== 'connected' || (string)($stored['credentialId'] ?? '') !== $credentialId) {
                return false;
            }
            if ($stored['activationPending'] ?? false) {
                $stored['activationPending'] = false;
                $this->write($stored);
            }

            return true;
        });
    }

    /** Ends the wait for this exact pairing; a newer pairing or a connection made meanwhile is left alone. */
    public function cancelPairingIfCurrent(string $pairingId): bool
    {
        return $this->locked(function () use ($pairingId): bool {
            $current = $this->get();
            if ($current['status'] !== 'pairing' || $current['pairingId'] !== $pairingId) {
                return false;
            }
            $this->write($current['disconnectedAt'] > 0
                ? ['status' => 'disconnected', 'disconnectedAt' => $current['disconnectedAt'], 'disconnectedBy' => $current['disconnectedBy']]
                : ['status' => 'none']);

            return true;
        });
    }

    /** After an emergency disconnect the secret is gone; reconnecting needs a new pairing. */
    public function markDisconnected(string $by): void
    {
        $this->locked(function () use ($by): void {
            $this->write(['status' => 'disconnected', 'disconnectedAt' => time(), 'disconnectedBy' => $by]);
        });
    }

    /**
     * Ends the stored connection only while it still holds the credential the Gateway answered about (its disconnect,
     * or its refused activation). Returns false when another connection is stored by now; that one is left alone.
     */
    public function markDisconnectedIfCurrent(string $credentialId, string $by): bool
    {
        return $this->locked(function () use ($credentialId, $by): bool {
            $stored = $this->read();
            if (($stored['status'] ?? '') !== 'connected' || (string)($stored['credentialId'] ?? '') !== $credentialId) {
                return false;
            }
            $this->write(['status' => 'disconnected', 'disconnectedAt' => time(), 'disconnectedBy' => $by]);

            return true;
        });
    }

    /**
     * Forgets the connection on this installation only, without any Gateway call: for a copy of an installation (its
     * credential is the original's), after a new installation identity, or for a connection unusable on this host. The
     * Gateway keeps the original's connection and tokens. Returns the status that was stored.
     */
    public function resetLocal(string $by): string
    {
        return $this->locked(function () use ($by): string {
            $previous = $this->get()['status'];
            $this->write(['status' => 'none', 'resetAt' => time(), 'resetBy' => $by]);

            return $previous;
        });
    }

    /**
     * Why a stored pairing or connection cannot be used on this host now; '' when it can (or nothing is stored).
     *   legacy_format     — made before clone safety N2: pair again
     *   identity_changed  — the installation was given a new identity (a copy): reset locally, pair the copy on its own
     *   host_key_missing  — this host has no MCP connector key (another host, or the key file was lost)
     *   host_key_mismatch — this host has a different MCP connector key
     *
     * @param array<string, mixed> $stored
     */
    private function unusableReason(array $stored): string
    {
        if (!in_array($stored['status'] ?? '', ['pairing', 'connected'], true)) {
            return '';
        }
        if ((int)($stored['format'] ?? 0) !== self::FORMAT) {
            return 'legacy_format';
        }
        $installationId = $this->installationIdentityService->findInstallationId();
        if ($installationId === null || !hash_equals((string)($stored['installationFingerprint'] ?? ''), InstallationIdentityService::fingerprint($installationId))) {
            return 'identity_changed';
        }
        $key = $this->hostKey->current();
        if ($key === null) {
            return 'host_key_missing';
        }

        return hash_equals((string)($stored['hostKeyId'] ?? ''), McpHostKey::idOf($key)) ? '' : 'host_key_mismatch';
    }

    /**
     * @return array{format: int, hostKeyId: string, installationFingerprint: string}
     */
    private function binding(string $key): array
    {
        return [
            'format' => self::FORMAT,
            'hostKeyId' => McpHostKey::idOf($key),
            'installationFingerprint' => InstallationIdentityService::fingerprint($this->installationIdentityService->getOrCreateInstallationId()),
        ];
    }

    /** Seals a secret with the host key, inside TYPO3's encryption (both are needed to open it). */
    private function seal(string $secret, string $key): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $inner = self::SEAL_PREFIX . base64_encode($nonce . sodium_crypto_secretbox($secret, $nonce, self::sealKey($key)));

        return $this->secretEncryptionService->encrypt($inner);
    }

    /** '' when the secret cannot be opened on this host. */
    private function open(string $sealed): string
    {
        $key = $this->hostKey->current();
        $inner = $this->secretEncryptionService->decrypt($sealed);
        if ($key === null || !str_starts_with($inner, self::SEAL_PREFIX)) {
            return '';
        }
        $payload = base64_decode(substr($inner, strlen(self::SEAL_PREFIX)), true);
        if (!is_string($payload) || strlen($payload) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $opened = sodium_crypto_secretbox_open(
            substr($payload, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($payload, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            self::sealKey($key),
        );

        return is_string($opened) ? $opened : '';
    }

    private static function sealKey(string $key): string
    {
        return hash('sha256', "aqg-mcp-connection-seal:v1\n" . $key, true);
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function locked(callable $callback): mixed
    {
        $lock = $this->lockFactory->createLocker(self::LOCK_KEY, LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        $lock->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE);
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function read(): array
    {
        $value = $this->connectionPool->getConnectionForTable('sys_registry')->select(
            ['entry_value'],
            'sys_registry',
            ['entry_namespace' => self::REGISTRY_NAMESPACE, 'entry_key' => self::REGISTRY_KEY],
        )->fetchOne();
        if (is_resource($value)) {
            $value = (string)stream_get_contents($value);
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = @unserialize($value, ['allowed_classes' => false]);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $value
     */
    private function write(array $value): void
    {
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $value);
    }
}
