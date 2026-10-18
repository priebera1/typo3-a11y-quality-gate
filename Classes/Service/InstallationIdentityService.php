<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use TYPO3\CMS\Core\Registry;

/**
 * The identity of this TYPO3 installation towards the AQG service: a random id kept in sys_registry.
 *
 * The id lives in the database on purpose. A deployment, a domain or site change, a TYPO3 or AQG update, a moved
 * server, a restored backup or a staging copy of the same client keep it, and with it the installation's Agency
 * project, scan history and evidence. Nothing here ever replaces it on its own — not a new host name, site
 * configuration or encryption key: any of those also happens to the same installation.
 *
 * A database clone that becomes a *different* installation (a new client site built from an existing one) still
 * presents its source's id, and the AQG service cannot tell the two apart. Such a copy is given a new id only
 * deliberately, with `a11y:installation:identity --new-installation` (InstallationIdentityCommand).
 */
final class InstallationIdentityService implements InstallationIdentityServiceInterface
{
    private const REGISTRY_NAMESPACE = 'a11y_quality_gate';
    private const REGISTRY_KEY = 'anonymous_installation_id';
    private const REASSIGNMENT_KEY = 'installation_id_reassignment';

    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    public function getOrCreateInstallationId(): string
    {
        $installationId = $this->findInstallationId();
        if ($installationId !== null) {
            return $installationId;
        }

        $installationId = $this->generateUuidV4();
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $installationId);

        return $installationId;
    }

    /**
     * The stored id, without creating one.
     */
    public function findInstallationId(): ?string
    {
        $installationId = trim((string)$this->registry->get(
            self::REGISTRY_NAMESPACE,
            self::REGISTRY_KEY,
            ''
        ));

        return $installationId !== '' ? $installationId : null;
    }

    /**
     * A short, non-secret label of an installation id. The id itself authenticates the installation's Agency project
     * and its Free Remote Preview proof, so it is never printed; the fingerprint is safe to show, compare between two
     * installations and quote to support. Domain-separated from every hash the AQG service keeps of the id.
     */
    public static function fingerprint(string $installationId): string
    {
        $hash = hash('sha256', "aqg-installation-fingerprint:v1\n" . trim($installationId));

        return implode('-', str_split(substr($hash, 0, 16), 4));
    }

    /**
     * Replaces the id with a new random one. Only for a database copy that is a new, separate installation: the AQG
     * service treats it as a TYPO3 installation it has never seen. Keeps no copy of the previous id — the copy must
     * not go on holding its source's credential — only its fingerprint and the time, for support.
     *
     * @return array{previousFingerprint: ?string, fingerprint: string}
     */
    public function assignNewInstallationId(): array
    {
        $previous = $this->findInstallationId();
        do {
            $installationId = $this->generateUuidV4();
        } while ($installationId === $previous);

        $previousFingerprint = $previous !== null ? self::fingerprint($previous) : null;
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $installationId);
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REASSIGNMENT_KEY, [
            'reassignedAt' => time(),
            'previousFingerprint' => $previousFingerprint,
        ]);

        return ['previousFingerprint' => $previousFingerprint, 'fingerprint' => self::fingerprint($installationId)];
    }

    /**
     * When this installation was last given a new id, and the fingerprint of the id it had before.
     *
     * @return array{reassignedAt: int, previousFingerprint: ?string}|null
     */
    public function findLastReassignment(): ?array
    {
        $entry = $this->registry->get(self::REGISTRY_NAMESPACE, self::REASSIGNMENT_KEY);
        if (!is_array($entry) || (int)($entry['reassignedAt'] ?? 0) <= 0) {
            return null;
        }

        $previous = $entry['previousFingerprint'] ?? null;

        return [
            'reassignedAt' => (int)$entry['reassignedAt'],
            'previousFingerprint' => is_string($previous) && $previous !== '' ? $previous : null,
        ];
    }

    private function generateUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
