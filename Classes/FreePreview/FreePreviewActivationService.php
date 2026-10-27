<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use TYPO3\CMS\Core\Information\Typo3Version;

/**
 * Activation of the FREE Remote Preview for a site (Settings › Licence › FREE Remote Preview).
 *
 * FREE scans are sent to the AQG cloud crawler only after a TYPO3 administrator has accepted the published FREE terms
 * and DPA for the site, and only after the AQG service has recorded that acceptance: the extension sends it
 * (installation, domain, organisation, the accepting administrator, the document versions and hashes, the statement and
 * its hash) and keeps the service's confirmation — acceptance id, versions and the service's own UTC timestamp. A ticked
 * checkbox, a local flag or a failed request never activates anything (fail closed). Before activation the FREE status
 * is not requested from the service either, so nothing about the site leaves the installation before acceptance.
 *
 * The confirmation is kept per domain and installation: another installation (a different installation id), another
 * domain or a new version of the terms needs its own acceptance. Switching FREE off stops new FREE scans and keeps the
 * confirmation, so switching it on again reuses an acceptance that is still valid for the same terms. The acceptance id
 * is sent with every FREE token request and submit so that the AQG service can enforce the same rules (contract:
 * Documentation/Developer/FreeRemotePreviewAcceptanceApi.rst); its refusal is recorded here.
 *
 * The data retention of FREE results on the AQG service is the service's (described in its privacy information); this
 * installation's own scan history follows Settings › Scan history.
 */
class FreePreviewActivationService
{
    public const STATE_NOT_ACTIVATED = 'not_activated';
    public const STATE_ACTIVE = 'active';
    public const STATE_DISABLED = 'disabled';
    public const STATE_RENEWAL_REQUIRED = 'renewal_required';
    public const STATE_REVOKED = 'revoked';
    public const STATE_EXPIRED = 'expired';
    public const STATE_IDENTITY_CHANGED = 'identity_changed';

    private const TERMS_CACHE_KEY = 'aqg_free_terms_v1';
    private const TERMS_CACHE_TTL = 3600;
    /** Unpublished or unreachable terms are asked for again after ten minutes, not on every Settings view. */
    private const TERMS_FAILURE_CACHE_KEY = 'aqg_free_terms_failure_v1';
    private const TERMS_FAILURE_CACHE_TTL = 600;
    /**
     * The cached terms and the cached refusal, dropped on every TYPO3 cache flush (FreeTermsCacheFlushListener): a full
     * flush must ask the AQG service again. Only these: the licence answers and their offline grace copies in the same
     * cache must survive a deployment's `cache:flush`.
     */
    public const TERMS_CACHE_KEYS = [self::TERMS_CACHE_KEY, self::TERMS_FAILURE_CACHE_KEY];
    private const TEXT_LIMIT = 200;

    public function __construct(
        private readonly AqgApiClient $apiClient,
        private readonly FreePreviewActivationStore $store,
        private readonly InstallationIdentityServiceInterface $installationIdentityService,
        private readonly DomainNormalizer $domainNormalizer,
        private readonly ProCacheManager $cacheManager,
        private readonly PublicSiteAddressClassifier $publicSiteAddressClassifier,
    ) {
    }

    /**
     * The published FREE terms, from the AQG service (cached for an hour; only a complete answer is cached).
     *
     * @return array{terms:?FreeTermsDocuments, error:string} error: '', 'not_published' or 'unavailable'
     */
    public function getTerms(string $language, bool $forceRefresh = false): array
    {
        if (!$forceRefresh) {
            $cached = $this->cacheManager->getDisplayPayload(self::TERMS_CACHE_KEY);
            $terms = is_array($cached) ? FreeTermsDocuments::fromApi($cached) : null;
            if ($terms !== null) {
                return ['terms' => $terms, 'error' => ''];
            }
            $failure = (string)($this->cacheManager->getDisplayPayload(self::TERMS_FAILURE_CACHE_KEY)['error'] ?? '');
            if (in_array($failure, ['not_published', 'unavailable'], true)) {
                return ['terms' => null, 'error' => $failure];
            }
        }

        try {
            $terms = FreeTermsDocuments::fromApi($this->apiClient->fetchFreeTerms($language));
            $error = $terms === null ? 'not_published' : '';
        } catch (ApiRequestFailedException $exception) {
            $terms = null;
            $error = $exception->httpStatus === 404 || $exception->apiErrorCode === 'route_not_found' ? 'not_published' : 'unavailable';
        } catch (\Throwable) {
            $terms = null;
            $error = 'unavailable';
        }
        if ($terms === null) {
            $this->cacheManager->setDisplayPayload(self::TERMS_FAILURE_CACHE_KEY, ['error' => $error], self::TERMS_FAILURE_CACHE_TTL);

            return ['terms' => null, 'error' => $error];
        }
        $this->cacheManager->removeDisplayPayload(self::TERMS_FAILURE_CACHE_KEY);
        $this->cacheManager->setDisplayPayload(self::TERMS_CACHE_KEY, $terms->toArray(), self::TERMS_CACHE_TTL);

        return ['terms' => $terms, 'error' => ''];
    }

    /**
     * The activation state of a site and its confirmation. `$terms` (when known) detects a newer version of the terms.
     *
     * @return array{state:string, acceptance:?FreeTermsAcceptance, domain:string}
     */
    public function status(string $siteBase, ?FreeTermsDocuments $terms = null, ?int $now = null): array
    {
        $domain = $this->domainNormalizer->normalizeFromSiteBase($siteBase);
        $acceptance = $domain !== '' ? $this->store->find($domain) : null;

        return ['state' => $this->stateOf($acceptance, $terms, $now ?? time()), 'acceptance' => $acceptance, 'domain' => $domain];
    }

    /**
     * The acceptance id to send with FREE requests, or '' when FREE is not active for the site. Uses the cached terms
     * only (no request): the AQG service checks the acceptance itself on every token and submit.
     */
    public function activeAcceptanceId(string $siteBase): string
    {
        $cached = $this->cacheManager->getDisplayPayload(self::TERMS_CACHE_KEY);
        $status = $this->status($siteBase, is_array($cached) ? FreeTermsDocuments::fromApi($cached) : null);

        return $status['state'] === self::STATE_ACTIVE && $status['acceptance'] !== null ? $status['acceptance']->acceptanceId : '';
    }

    /**
     * Records the acceptance on the AQG service and keeps its confirmation. Returns the outcome code ('accepted', or why
     * nothing was activated) and the confirmation.
     *
     * @param array{organisation:string, name:string, email:string, authorityConfirmed:bool, termsVersion:string, language:string, userUid:int} $input
     * @return array{code:string, acceptance:?FreeTermsAcceptance}
     */
    public function accept(string $siteBase, string $siteIdentifier, array $input, string $extensionVersion, ?int $now = null): array
    {
        $now ??= time();
        $refused = static fn (string $code): array => ['code' => $code, 'acceptance' => null];
        $organisation = self::singleLine($input['organisation'] ?? '');
        $name = self::singleLine($input['name'] ?? '');
        $email = strtolower(self::singleLine($input['email'] ?? ''));
        $language = FreePreviewStatement::languageFor((string)($input['language'] ?? 'en'));

        if (($input['authorityConfirmed'] ?? false) !== true) {
            return $refused('confirmation_required');
        }
        if (mb_strlen($organisation) < 2 || mb_strlen($organisation) > self::TEXT_LIMIT
            || mb_strlen($name) < 2 || mb_strlen($name) > self::TEXT_LIMIT
            || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            return $refused('invalid_input');
        }
        $domain = $this->domainNormalizer->normalizeFromSiteBase($siteBase);
        $installationId = trim($this->installationIdentityService->getOrCreateInstallationId());
        if ($domain === '' || $installationId === '' || trim($siteIdentifier) === '') {
            return $refused('invalid_site');
        }
        if ($this->publicSiteAddressClassifier->isClearlyNotPublic($siteBase)) {
            return $refused('site_not_public');
        }

        // Always the terms in force now: the administrator must have seen exactly this version.
        $termsResult = $this->getTerms($language, true);
        $terms = $termsResult['terms'];
        if ($terms === null) {
            return $refused($termsResult['error'] === 'not_published' ? 'terms_not_published' : 'unavailable');
        }
        if (!hash_equals($terms->version, (string)($input['termsVersion'] ?? ''))) {
            return $refused('terms_changed');
        }
        if (!$terms->acceptsStatement($language)) {
            return $refused('statement_mismatch');
        }

        $documents = array_map(
            static fn (array $document): array => [
                'type' => $document['type'],
                'language' => $document['language'],
                'version' => $document['version'],
                'sha256' => $document['sha256'],
            ],
            $terms->documentsFor($language)
        );
        $request = [
            'installationId' => $installationId,
            'siteUrl' => rtrim($siteBase, '/') . '/',
            'siteIdentifier' => trim($siteIdentifier),
            'domain' => $domain,
            'termsVersion' => $terms->version,
            'documents' => $documents,
            'statement' => [
                'version' => FreePreviewStatement::VERSION,
                'language' => $language,
                'text' => FreePreviewStatement::text($language),
                'sha256' => FreePreviewStatement::sha256($language),
            ],
            'organisation' => ['name' => $organisation],
            'acceptedBy' => [
                'name' => $name,
                'email' => $email,
                'typo3UserId' => (int)($input['userUid'] ?? 0),
                'role' => 'typo3_administrator',
                'authorityConfirmed' => true,
            ],
            // The same acceptance submitted again (a retry, a double click) is the same record on the AQG service.
            'idempotencyKey' => hash('sha256', implode('|', [
                'aqg-free-terms-acceptance:v1', $installationId, $domain, $terms->version, FreePreviewStatement::sha256($language),
                mb_strtolower($organisation), mb_strtolower($name), $email,
            ])),
            // Informational only: the AQG service records its own UTC time as the acceptance time.
            'clientTimestamp' => gmdate('Y-m-d\TH:i:s\Z', $now),
            'extension' => [
                'version' => $extensionVersion,
                'typo3Version' => (new Typo3Version())->getVersion(),
            ],
        ];

        try {
            $response = $this->apiClient->acceptFreeTerms($request);
        } catch (ApiRequestFailedException $exception) {
            return $refused(self::acceptanceErrorCode($exception));
        } catch (\Throwable) {
            return $refused('unavailable');
        }

        $acceptance = FreeTermsAcceptance::fromConfirmation($response, $request, (int)($input['userUid'] ?? 0), $now);
        if ($acceptance === null) {
            return $refused('invalid_confirmation');
        }
        $this->store->save($acceptance);

        return ['code' => 'accepted', 'acceptance' => $acceptance];
    }

    /**
     * Switches FREE scans on or off for a site without touching the recorded acceptance. Switching on needs an
     * acceptance that is still valid for this installation and the terms in force.
     */
    public function setEnabled(string $siteBase, bool $enabled, ?FreeTermsDocuments $terms = null, ?int $now = null): string
    {
        $status = $this->status($siteBase, $terms, $now);
        $acceptance = $status['acceptance'];
        if ($acceptance === null || $status['state'] === self::STATE_IDENTITY_CHANGED) {
            return 'not_activated';
        }
        if ($enabled && !in_array($status['state'], [self::STATE_ACTIVE, self::STATE_DISABLED], true)) {
            return $status['state'];
        }
        $this->store->save($acceptance->withEnabled($enabled));

        return $enabled ? 'enabled' : 'disabled';
    }

    /**
     * Asks the AQG service for the current state of the recorded acceptance. Only its answer for the same acceptance
     * id changes the local state; an unreachable service changes nothing.
     */
    public function refresh(string $siteBase, string $siteIdentifier, ?int $now = null): string
    {
        $now ??= time();
        $status = $this->status($siteBase, null, $now);
        $acceptance = $status['acceptance'];
        if ($acceptance === null || $status['state'] === self::STATE_IDENTITY_CHANGED) {
            return 'not_activated';
        }

        try {
            $response = $this->apiClient->freeTermsAcceptanceStatus([
                'installationId' => $acceptance->installationId,
                'siteUrl' => rtrim($siteBase, '/') . '/',
                'siteIdentifier' => trim($siteIdentifier),
                'acceptanceId' => $acceptance->acceptanceId,
            ]);
        } catch (ApiRequestFailedException $exception) {
            if (in_array($exception->apiErrorCode, ['free_terms_acceptance_not_found', 'free_terms_acceptance_mismatch'], true)) {
                $this->store->save($acceptance->withStatus(FreeTermsAcceptance::STATUS_REVOKED, $now));

                return FreeTermsAcceptance::STATUS_REVOKED;
            }

            return 'unavailable';
        } catch (\Throwable) {
            return 'unavailable';
        }

        $reported = is_array($response['acceptance'] ?? null) ? $response['acceptance'] : [];
        if ((string)($reported['id'] ?? '') !== $acceptance->acceptanceId) {
            return 'invalid_confirmation';
        }
        $reportedStatus = strtolower(trim((string)($reported['status'] ?? '')));
        $currentVersion = trim((string)($response['currentTermsVersion'] ?? ''));
        if ($reportedStatus === FreeTermsAcceptance::STATUS_VALID && $currentVersion !== '' && $currentVersion !== $acceptance->termsVersion) {
            $reportedStatus = FreeTermsAcceptance::STATUS_OUTDATED;
        }
        // An answer without validUntil keeps the known expiry; only an explicit time changes it.
        $updated = $acceptance->withStatus($reportedStatus, $now, FreeTermsAcceptance::utcTimestamp($reported['validUntil'] ?? null));
        $this->store->save($updated);

        return $updated->status;
    }

    /**
     * The AQG service refused a FREE token or submit because of the terms acceptance: keep that, so the Overview asks
     * for a new acceptance instead of retrying.
     */
    public function recordServiceRefusal(string $siteBase, string $errorCode, ?int $now = null): void
    {
        $status = $this->status($siteBase, null, $now);
        $acceptance = $status['acceptance'];
        if ($acceptance === null || !in_array($errorCode, FreePreviewException::TERMS_ACCEPTANCE_CODES, true)) {
            return;
        }
        // A request sent without the acceptance (renewal required or expired) is refused as "required": that confirms
        // the known state and must not turn it into "revoked".
        if ($errorCode === 'free_terms_acceptance_required'
            && in_array($acceptance->status, [FreeTermsAcceptance::STATUS_OUTDATED, FreeTermsAcceptance::STATUS_EXPIRED], true)
        ) {
            return;
        }
        $this->store->save($acceptance->withStatus(match ($errorCode) {
            'free_terms_outdated' => FreeTermsAcceptance::STATUS_OUTDATED,
            'free_terms_acceptance_expired' => FreeTermsAcceptance::STATUS_EXPIRED,
            default => FreeTermsAcceptance::STATUS_REVOKED,
        }, $now ?? time()));
    }

    /** A local, development or private address the AQG crawler can never reach (guidance only, no request). */
    public function isClearlyNotPublic(string $siteBase): bool
    {
        return $this->publicSiteAddressClassifier->isClearlyNotPublic($siteBase);
    }

    private function stateOf(?FreeTermsAcceptance $acceptance, ?FreeTermsDocuments $terms, int $now): string
    {
        if ($acceptance === null) {
            return self::STATE_NOT_ACTIVATED;
        }
        if (!hash_equals($acceptance->installationId, trim($this->installationIdentityService->getOrCreateInstallationId()))) {
            return self::STATE_IDENTITY_CHANGED;
        }

        return match (true) {
            $acceptance->status === FreeTermsAcceptance::STATUS_REVOKED => self::STATE_REVOKED,
            $acceptance->status === FreeTermsAcceptance::STATUS_EXPIRED, $acceptance->isExpiredAt($now) => self::STATE_EXPIRED,
            $acceptance->status === FreeTermsAcceptance::STATUS_OUTDATED,
            $terms !== null && $terms->version !== $acceptance->termsVersion => self::STATE_RENEWAL_REQUIRED,
            !$acceptance->enabled => self::STATE_DISABLED,
            default => self::STATE_ACTIVE,
        };
    }

    private static function acceptanceErrorCode(ApiRequestFailedException $exception): string
    {
        $code = trim($exception->apiErrorCode);

        return match (true) {
            $code === 'free_terms_outdated' => 'terms_changed',
            $code === 'free_terms_statement_mismatch' => 'statement_mismatch',
            $code === 'idempotency_key_reused' => 'conflict',
            str_starts_with($code, 'installation_proof') || $code === 'invalid_installation_proof' => 'proof_failed',
            in_array($code, ['free_domain_mismatch', 'site_identity_mismatch', 'private_network_blocked', 'dns_lookup_failed'], true) => 'site_not_public',
            $code === 'route_not_found' || $exception->httpStatus === 404 => 'not_available',
            in_array($code, FreePreviewException::RATE_LIMIT_CODES, true) || $exception->httpStatus === 429 => 'rate_limited',
            $exception->httpStatus === 0 || $exception->httpStatus >= 500 => 'unavailable',
            default => 'rejected',
        };
    }

    private static function singleLine(mixed $value): string
    {
        return trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)$value));
    }
}
