<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use TYPO3\CMS\Core\Registry;

final class InstallationIdentityService implements InstallationIdentityServiceInterface
{
    private const REGISTRY_NAMESPACE = 'a11y_quality_gate';
    private const REGISTRY_KEY = 'anonymous_installation_id';

    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    public function getOrCreateInstallationId(): string
    {
        $installationId = trim((string)$this->registry->get(
            self::REGISTRY_NAMESPACE,
            self::REGISTRY_KEY,
            ''
        ));

        if ($installationId !== '') {
            return $installationId;
        }

        $installationId = $this->generateUuidV4();
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $installationId);

        return $installationId;
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
