<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;

/**
 * The Licence tab's domain manager. The AQG service holds the licence's domain state — the customer portal shows
 * and changes the same state — so this class only asks it, with the verified installation's identity and site
 * inventory, and keeps no list of its own. Domains coming from the browser are a request: the service activates
 * only hosts this installation reports and enforces the plan's limit and locks.
 */
final class ProLicenceDomainService
{
    private const STATES = ['active', 'active_not_detected', 'available', 'unavailable'];
    private const REASONS = ['plan_limit', 'trial_single_domain'];
    private const RESULTS = [
        'activated', 'already_active', 'not_detected', 'plan_limit', 'development_host', 'invalid_domain',
        'deactivated', 'not_active', 'locked', 'last_domain',
    ];
    private const MAX_DOMAINS_PER_REQUEST = 200;

    public function __construct(
        private readonly AqgApiClient $apiClient,
        private readonly ProSettings $proSettings,
        private readonly ProSiteFingerprintService $proSiteFingerprintService,
        private readonly ProCacheManager $cacheManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(string $domain, string $version): array
    {
        if (!$this->proSettings->isConfigured()) {
            return $this->failure('not_configured');
        }

        try {
            $payload = $this->apiClient->listDomains(
                $this->proSettings->getLicenceKey(),
                $domain,
                $version,
                $this->allSites($domain),
            );
        } catch (ApiRequestFailedException $exception) {
            return $this->failure($exception->httpStatus === 429 ? 'licence_rate_limited' : 'api_unreachable');
        }

        $result = $this->normalize($payload);
        if ($result['success']) {
            // The portal may have changed the domains since the last validation: cached per-site licence answers
            // and tokens are dropped, so the next request of each site asks the service again.
            $this->cacheManager->flushAll();
        }

        return $result;
    }

    /**
     * @param list<mixed> $domains
     * @return array<string, mixed>
     */
    public function change(string $action, array $domains, bool $all, string $domain, string $version): array
    {
        if (!$this->proSettings->isConfigured()) {
            return $this->failure('not_configured');
        }

        $action = $action === 'deactivate' ? 'deactivate' : 'activate';
        $requested = $this->sanitizeDomains($domains);
        if (!($all && $action === 'activate') && $requested === []) {
            return $this->failure('invalid_request');
        }

        try {
            $payload = $this->apiClient->changeDomains(
                $action,
                $this->proSettings->getLicenceKey(),
                $domain,
                $version,
                $this->allSites($domain),
                $requested,
                $all && $action === 'activate',
            );
        } catch (ApiRequestFailedException $exception) {
            return $this->failure($exception->httpStatus === 429 ? 'licence_rate_limited' : 'api_unreachable');
        }

        $result = $this->normalize($payload);
        if ($result['success']) {
            // Activation changes what each site may do right now.
            $this->cacheManager->flushAll();
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function allSites(string $domain): array
    {
        return $this->proSiteFingerprintService->collectValidationSites($domain, $this->proSettings->isTrialKey());
    }

    /**
     * @param list<mixed> $domains
     * @return list<string>
     */
    private function sanitizeDomains(array $domains): array
    {
        $sanitized = [];
        foreach ($domains as $domain) {
            $value = strtolower(trim(is_string($domain) ? $domain : ''));
            if ($value !== '' && strlen($value) <= 253 && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value) === 1) {
                $sanitized[$value] = $value;
            }
        }

        return array_slice(array_values($sanitized), 0, self::MAX_DOMAINS_PER_REQUEST);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload): array
    {
        if (($payload['success'] ?? false) !== true || !is_array($payload['domainManagement'] ?? null)) {
            $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];

            return $this->failure(trim((string)($error['code'] ?? '')) ?: 'invalid_response');
        }

        $overview = $payload['domainManagement'];
        $domains = [];
        foreach (is_array($overview['domains'] ?? null) ? $overview['domains'] : [] as $item) {
            if (!is_array($item) || !in_array($item['state'] ?? null, self::STATES, true)) {
                continue;
            }
            $domains[] = [
                'domain' => (string)($item['domain'] ?? ''),
                'state' => (string)$item['state'],
                'reason' => in_array($item['reason'] ?? null, self::REASONS, true) ? (string)$item['reason'] : null,
                'sites' => array_values(array_map('strval', is_array($item['sites'] ?? null) ? $item['sites'] : [])),
                'lockedUntil' => is_string($item['lockedUntil'] ?? null) ? $item['lockedUntil'] : null,
                'activatedAt' => is_string($item['activatedAt'] ?? null) ? $item['activatedAt'] : null,
                'canActivate' => ($item['canActivate'] ?? false) === true,
                'canDeactivate' => ($item['canDeactivate'] ?? false) === true,
                'deactivateBlockedBy' => in_array($item['deactivateBlockedBy'] ?? null, ['locked', 'last_domain'], true) ? (string)$item['deactivateBlockedBy'] : null,
            ];
        }

        $results = [];
        foreach (is_array($payload['results'] ?? null) ? $payload['results'] : [] as $item) {
            if (is_array($item) && in_array($item['result'] ?? null, self::RESULTS, true)) {
                $results[] = ['domain' => (string)($item['domain'] ?? ''), 'result' => (string)$item['result']];
            }
        }

        $counts = is_array($overview['counts'] ?? null) ? $overview['counts'] : [];

        return [
            'success' => true,
            'plan' => (string)($overview['plan'] ?? $payload['plan'] ?? ''),
            'multiProject' => ($overview['multiProject'] ?? false) === true,
            'maxDomains' => isset($overview['maxDomains']) ? (int)$overview['maxDomains'] : null,
            'activeDomains' => (int)($overview['activeDomains'] ?? 0),
            'remainingSlots' => isset($overview['remainingSlots']) ? (int)$overview['remainingSlots'] : null,
            'activationLockDays' => isset($overview['activationLockDays']) ? max(0, (int)$overview['activationLockDays']) : null,
            'counts' => [
                'all' => (int)($counts['all'] ?? count($domains)),
                'active' => (int)($counts['active'] ?? 0),
                'activeNotDetected' => (int)($counts['activeNotDetected'] ?? 0),
                'available' => (int)($counts['available'] ?? 0),
                'unavailable' => (int)($counts['unavailable'] ?? 0),
            ],
            'domains' => $domains,
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(string $code): array
    {
        $code = preg_match('/^[a-z0-9_]{1,64}$/', $code) === 1 ? $code : 'invalid_response';
        [$key, $fallback] = match ($code) {
            'not_configured' => ['settings.licence.domains.error.notConfigured', 'Save a licence key to manage its domains.'],
            'licence_project_mismatch' => ['settings.licence.domains.error.projectMismatch', 'This licence is registered to another TYPO3 installation, so this installation cannot manage its domains.'],
            'licence_project_removed' => ['settings.licence.domains.error.projectRemoved', 'This TYPO3 project was removed from the licence. Restore it in the customer portal to manage its domains.'],
            'licence_project_limit_reached' => ['settings.licence.validation.reason.project_limit_reached', 'All project slots of this Agency licence are in use. Remove a project in the customer portal to add this installation.'],
            'licence_invalid', 'licence_not_active', 'product_mismatch', 'trial_invalid', 'trial_expired', 'trial_revoked', 'trial_not_verified' => ['settings.licence.domains.error.licenceInvalid', 'The licence is not active, so its domains cannot be changed.'],
            'trial_domain_fixed' => ['settings.licence.domains.error.trialFixed', 'A trial covers the one domain it was first used on. Choose PRO or Agency to scan more sites.'],
            'activate_all_not_available' => ['settings.licence.domains.error.activateAllAgencyOnly', 'Activating every detected domain at once is available with Agency.'],
            'licence_rate_limited' => ['settings.licence.domains.error.rateLimited', 'The licence service is limiting requests. Try again in a minute.'],
            'route_not_found' => ['settings.licence.domains.error.serviceOutdated', 'The licence service does not offer domain management yet. Try again later.'],
            'api_unreachable' => ['settings.licence.domains.error.unreachable', 'The licence service could not be reached. Try again later.'],
            'invalid_request' => ['settings.licence.domains.error.invalidRequest', 'Select at least one domain.'],
            default => ['settings.licence.domains.error.failed', 'The domains could not be loaded or changed. Try again later.'],
        };

        return [
            'success' => false,
            'code' => $code,
            'message' => BackendLabelUtility::translate($key, $fallback),
        ];
    }
}
