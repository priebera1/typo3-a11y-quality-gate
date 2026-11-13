<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Configuration;

use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ProSettings
{
    public function __construct(
        private readonly ExtensionConfiguration $extensionConfiguration,
        private readonly RulesetRepository $rulesetRepository,
        private readonly RuleConfigurationService $ruleConfigurationService,
    ) {
    }

    public function getLicenceKey(): string
    {
        try {
            return trim((string)$this->extensionConfiguration->get('a11y_quality_gate', 'licenceKey'));
        } catch (\Throwable) {
            return '';
        }
    }

    public function isConfigured(): bool
    {
        return $this->getLicenceKey() !== '';
    }

    public function isTrialKey(?string $licenceKey = null): bool
    {
        $licenceKey ??= $this->getLicenceKey();

        return $licenceKey !== ''
            && (
                str_starts_with($licenceKey, 'aqg_trial_')
                || str_starts_with($licenceKey, 'aqg_test_')
            );
    }

    public static function resolveApiBaseUrl(): string
    {
        return self::acceptedServiceOverride(getenv('A11Y_QUALITY_GATE_PRO_API_BASE_URL')) ?? ProConstants::API_BASE_URL;
    }

    /**
     * An AQG service URL from the environment, only when it is safe to send credentials to (P3-11): https, or plain http
     * on a development host (loopback, a single-label Docker service name, the reserved *.localhost / *.test / *.invalid /
     * *.example, *.ddev.site / *.local / *.internal). Licence keys, the MCP connector secret and pairing secrets travel to this URL, so an http URL on a
     * public host is ignored and the AQG service is used instead — never a downgrade to plain text over the internet.
     */
    public static function acceptedServiceOverride(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $url = rtrim(trim($value), '/');
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $scheme = strtolower((string)$parts['scheme']);
        if ($scheme === 'https') {
            return $url;
        }
        if ($scheme !== 'http') {
            return null;
        }
        $host = strtolower(trim((string)$parts['host'], '[]'));
        $development = $host === 'localhost' || $host === '::1' || preg_match('/^127\.\d+\.\d+\.\d+$/', $host) === 1
            || !str_contains($host, '.')
            || preg_match('/\.(localhost|test|invalid|example|ddev\.site|local|internal)$/', $host) === 1
            || $host === 'host.docker.internal';

        return $development ? $url : null;
    }

    /**
     * A development or staging setup that points AQG at its own service. Such a crawler may be configured to
     * reach development hosts, so the backend does not predict what it can scan.
     */
    public static function usesCustomServiceEndpoint(): bool
    {
        foreach (['A11Y_QUALITY_GATE_PRO_API_BASE_URL', 'A11Y_QUALITY_GATE_PRO_CRAWLER_BASE_URL'] as $variable) {
            $value = getenv($variable);
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    public static function resolveCrawlerBaseUrl(): string
    {
        return self::acceptedServiceOverride(getenv('A11Y_QUALITY_GATE_PRO_CRAWLER_BASE_URL')) ?? ProConstants::API_BASE_URL;
    }
}
