<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Mcp\Http\McpContinuationClient;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;

/**
 * Release-gate finding 2 (AQG 3.0.0): the authorization of a call's deferred AI request, right before it starts.
 *
 * The call's own authorization (its attestation, checked at begin) is not enough: the agent token may have been revoked,
 * expired or replaced, the consent revoked, the licence ended or the site's capability switched off in the seconds since.
 * So a job runs only after, in this order:
 *   1. continuation() — the licence API re-reads token, consent redemption, installation, licence and scopes now and
 *      signs a single-use continuation for exactly this call and job (backend migration 103), which is verified with the
 *      pinned keys (McpAttestation::verifyContinuation());
 *   2. assertLocal() — inside McpUserContext::runAs(): this installation's consent ledger and local revocation, the
 *      continued scopes and sites, and the site's MCP capability read now (no memo).
 * Every failure is a McpCallRefused; the caller then claims nothing and sends no provider request. There is no
 * fallback: an unreachable Gateway, a refusal, an older backend (404) or anything that does not verify means no job.
 */
final class McpDeferredAuthorization
{
    /** Core refusals passed on as they are; anything else from the Gateway is `continuation_unavailable`. */
    private const CORE_REFUSALS = [
        'token_revoked', 'token_expired', 'consent_replaced', 'scope_not_granted', 'licence_inactive',
        'installation_disconnected', 'continuation_used', 'continuation_refused', 'continuation_expired',
    ];
    /** The answer must leave this long before the call's deadline (McpDeferredWork::START_BEFORE_DEADLINE_SECONDS). */
    private const DEADLINE_MARGIN_SECONDS = 1.0;
    private const MAX_TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private readonly McpContinuationClient $client,
        private readonly McpAttestation $attestation,
        private readonly McpConnectionStore $connectionStore,
        private readonly McpConsentLedger $consentLedger,
        private readonly McpSiteSettingsRepository $siteSettings,
        private readonly McpAuditRepository $auditRepository,
    ) {
    }

    /**
     * Step 1: the licence API's continuation of this call for this job, verified. Not retried.
     *
     * @param array<string, mixed> $callContext McpDeferredWork::begin()
     * @return array{jti: string, tokenId: string, beUserUid: int, consentRefHash: string, clientId: string, scopes: list<string>, sites: list<string>, tokenExpiresAt: int, exp: int}
     * @throws McpCallRefused
     */
    public function continuation(array $callContext, string $subject): array
    {
        $callId = (string)($callContext['callId'] ?? '');
        $attestation = (string)($callContext['attestation'] ?? '');
        $claims = is_array($callContext['claims'] ?? null) ? $callContext['claims'] : [];
        if ($callId === '' || $attestation === '' || ($claims['jti'] ?? '') === '') {
            throw new McpCallRefused('continuation_unavailable', 'This call cannot be continued.', 'refused');
        }
        $timeout = min(self::MAX_TIMEOUT_SECONDS, (float)($callContext['deadline'] ?? 0.0) - microtime(true) - self::DEADLINE_MARGIN_SECONDS);
        if ($timeout <= 0.0) {
            throw new McpCallRefused('continuation_too_late', 'Too close to the call\'s deadline to continue it.', 'refused');
        }
        try {
            $answer = $this->client->continueCall($callId, $attestation, $subject, $timeout);
        } catch (ApiRequestFailedException $exception) {
            $code = in_array($exception->apiErrorCode, self::CORE_REFUSALS, true) ? $exception->apiErrorCode : 'continuation_unavailable';
            throw new McpCallRefused($code, 'The AQG licence API did not continue this call; nothing was generated.', 'refused');
        } catch (\Throwable) {
            throw new McpCallRefused('continuation_unavailable', 'The AQG licence API did not continue this call; nothing was generated.', 'refused');
        }

        return $this->attestation->verifyContinuation($answer['continuation'], $claims, $subject, $this->connectionStore->attestationTrust());
    }

    /**
     * Step 2 (inside runAs()): this installation's own state, read now.
     *
     * @param array{tokenId: string, beUserUid: int, consentRefHash: string, clientId: string, scopes: list<string>, sites: list<string>} $continuation
     * @throws McpCallRefused
     */
    public function assertLocal(array $continuation, string $siteIdentifier): void
    {
        // Local revocation first (written before the Gateway is told): it never trusts core alone (D7).
        if ($this->consentLedger->isTokenRevoked($continuation['tokenId'])) {
            throw new McpCallRefused('token_revoked', 'The agent token was revoked on this installation.', 'refused');
        }
        $verdict = $this->consentLedger->checkAttested(
            strtolower($continuation['consentRefHash']),
            $continuation['beUserUid'],
            $continuation['clientId'],
            $continuation['tokenId'],
            $continuation['scopes'],
            $continuation['sites'],
        );
        if ($verdict !== '') {
            throw new McpCallRefused($verdict === 'token_revoked' ? 'consent_revoked' : $verdict, 'This installation no longer has the consent for this agent token.', 'refused');
        }
        if (!in_array(McpScopes::SUGGESTIONS_PREPARE, McpScopes::normalize($continuation['scopes']), true)) {
            throw new McpCallRefused('scope_not_granted', 'The consent no longer covers preparing suggestions.', 'refused');
        }
        if ($siteIdentifier === '' || !in_array($siteIdentifier, $continuation['sites'], true)) {
            throw new McpCallRefused('site_not_available', 'This site is not available to this agent.', 'refused');
        }
        $capabilities = $this->siteSettings->effectiveCapabilities($siteIdentifier);
        if ($capabilities === []) {
            throw new McpCallRefused('site_not_available', 'Agent access is off for this site.', 'refused');
        }
        if (!in_array(McpScopes::SUGGESTIONS_PREPARE, $capabilities, true)) {
            throw new McpCallRefused('capability_off', 'Preparing suggestions is not enabled for agents on this site.', 'refused');
        }
    }

    /**
     * After the provider request: whether this agent's access ended meanwhile (it cannot be interrupted, so it is
     * reported as generated before the revocation, never as cancelled).
     *
     * @param array{tokenId: string, beUserUid: int, consentRefHash: string, clientId: string, scopes: list<string>, sites: list<string>} $continuation
     */
    public function revokedSince(array $continuation): bool
    {
        return $this->consentLedger->isTokenRevoked($continuation['tokenId'])
            || $this->consentLedger->checkAttested(strtolower($continuation['consentRefHash']), $continuation['beUserUid'], $continuation['clientId'],
                $continuation['tokenId'], $continuation['scopes'], $continuation['sites']) !== '';
    }

    /**
     * One audit row per job: deferred_generated, deferred_refused (reason), deferred_skipped (reason) or
     * generated_before_revocation.
     *
     * @param array<string, mixed> $callContext
     */
    public function audit(string $result, array $callContext, string $siteIdentifier, int $itemUid, string $reason = ''): void
    {
        $principal = is_array($callContext['principal'] ?? null) ? $callContext['principal'] : [];
        $this->auditRepository->record('deferred_generation', $result, 'connector', [
            'beUserUid' => (int)($callContext['claims']['beUserUid'] ?? 0),
            'beUserName' => (string)($principal['beUserName'] ?? ''),
            'clientName' => (string)($principal['clientName'] ?? ''),
            'tokenSuffix' => (string)($principal['tokenSuffix'] ?? ''),
            'siteIdentifier' => $siteIdentifier,
            'target' => 'item ' . $itemUid,
            'reason' => $reason,
        ]);
    }
}
