<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteInventoryService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\InstallationIdentityService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Pairing with the AQG MCP Gateway (atlas 13 · A "Connecting"): the installation proves its licence exactly like a
 * crawler token request (licence key + installation id + site inventory), the Gateway answers with a code that the
 * licence owner confirms in the AQG account, and only then does this installation collect its connector secret.
 */
final class McpPairingService
{
    /** The Gateway's activation window after a completed pairing (MCP_ACTIVATION_TTL_MS). */
    private const ACTIVATION_GRACE_SECONDS = 600;
    /** Gateway error codes that mean this credential can never be activated (routes/mcp-gateway.ts, installations.ts). */
    private const ACTIVATION_REFUSALS = ['connector_unauthorized', 'installation_not_found', 'pairing_state_changed', 'activation_expired'];

    public function __construct(
        private readonly McpGatewayClient $gatewayClient,
        private readonly McpConnectionStore $connectionStore,
        private readonly ProSettings $proSettings,
        private readonly InstallationIdentityService $installationIdentityService,
        private readonly ProSiteFingerprintService $siteFingerprintService,
        private readonly ProSiteInventoryService $siteInventoryService,
        private readonly ExtensionContextService $extensionContextService,
        private readonly UriBuilder $uriBuilder,
        private readonly McpHostKey $hostKey,
    ) {
    }

    /**
     * Starts a pairing unless this installation is connected (returns false then; disconnect first).
     *
     * @throws ApiRequestFailedException
     */
    public function start(Site $licenceSite, string $startedBy): bool
    {
        if ($this->connectionStore->get()['status'] === 'connected') {
            return false;
        }
        // The host key first (McpHostKeyUnavailable): without one, no pairing is opened at the Gateway.
        $this->hostKey->ensure();
        $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase((string)$licenceSite->getBase());
        $base = (string)$licenceSite->getBase();
        $siteUrl = str_contains($base, '://') ? $base : 'https://' . $domain . '/';
        $response = $this->gatewayClient->startPairing([
            'licenceKey' => $this->proSettings->getLicenceKey(),
            'projectInstallationId' => $this->installationIdentityService->getOrCreateInstallationId(),
            'siteUrl' => $siteUrl,
            'allSites' => $this->siteFingerprintService->collectValidationSites($domain, false),
            'siteInventory' => $this->siteInventoryService->collect(),
            'version' => $this->extensionContextService->getExtensionVersion(),
            'label' => mb_substr(trim((string)($GLOBALS['TYPO3_CONF_VARS']['SYS']['sitename'] ?? '')) ?: $domain, 0, 120),
            'consentUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_a11y.mcpConsent', [], UriBuilder::SHAREABLE_URL),
        ]);

        $stored = $this->connectionStore->startPairing(
            (string)($response['pairingId'] ?? ''),
            (string)($response['code'] ?? ''),
            (string)($response['pollSecret'] ?? ''),
            (int)strtotime((string)($response['expiresAt'] ?? '')),
            self::accountUrl($response['confirmUrl'] ?? null),
            $startedBy,
        );
        if (!$stored) {
            // Connected meanwhile (another request): the code just issued must not stay confirmable.
            try {
                $this->gatewayClient->cancelPairing((string)($response['pairingId'] ?? ''), (string)($response['pollSecret'] ?? ''));
            } catch (ApiRequestFailedException) {
                // It expires unconfirmed after 10 minutes.
            }
        }

        return $stored;
    }

    /**
     * Asks the Gateway whether the owner confirmed the code; stores the connector secret once it is handed out and
     * activates the installation. A completion for a pairing that was cancelled or replaced meanwhile is not stored:
     * its Gateway installation is disconnected right away (and would expire unactivated anyway), Sol audit F4.
     *
     * @return string pending | connected | expired | cancelled | none
     * @throws ApiRequestFailedException
     */
    public function poll(): string
    {
        $connection = $this->connectionStore->get();
        if ($connection['status'] === 'connected') {
            return $connection['activationPending'] && !$this->ensureActivated() ? 'cancelled' : 'connected';
        }
        if ($connection['status'] !== 'pairing') {
            return 'none';
        }
        $pairingId = $connection['pairingId'];
        $response = $this->gatewayClient->completePairing($pairingId, $this->connectionStore->getPollSecret());
        $status = (string)($response['status'] ?? '');
        if ($status === 'completed' && is_string($response['connectorSecret'] ?? null)) {
            $secret = $response['connectorSecret'];
            if (!$this->connectionStore->completePairingIfCurrent($pairingId, $secret, (string)($response['slug'] ?? ''), (string)($response['serverUrl'] ?? ''))) {
                $this->discardLostPairing($secret);

                return $this->connectionStore->get()['status'] === 'connected' ? 'connected' : 'cancelled';
            }

            return $this->ensureActivated() ? 'connected' : 'cancelled';
        }
        if ($status === 'completed') {
            // No secret: another poll of this installation collected it and is storing it right now — or its response
            // was lost. Leave the state to that poll; only once the pairing and its activation window are over is the
            // wait ended here (the Gateway disconnects an installation nobody activated).
            if (time() > $connection['expiresAt'] + self::ACTIVATION_GRACE_SECONDS) {
                $this->connectionStore->cancelPairingIfCurrent($pairingId);

                return 'cancelled';
            }

            return 'pending';
        }
        if (in_array($status, ['expired', 'cancelled'], true)) {
            $this->connectionStore->cancelPairingIfCurrent($pairingId);

            return $status;
        }

        return 'pending';
    }

    /**
     * Cancels the open pairing at the Gateway first (authoritative), then locally. Returns whether the Gateway confirmed
     * it: `cancelled` (confirmed, or nothing was open), `unconfirmed` (the Gateway could not be reached or refused — the
     * local cancel still makes a late completion fail its compare-and-set, that installation is discarded and expires
     * unactivated) or `none` (no pairing was open here). A failure is reported to the caller, never swallowed (A3).
     */
    public function cancel(): string
    {
        $connection = $this->connectionStore->get();
        if ($connection['status'] !== 'pairing') {
            return 'none';
        }
        $confirmed = true;
        $pollSecret = $this->connectionStore->getPollSecret();
        if ($connection['pairingId'] !== '' && $pollSecret !== '') {
            try {
                $this->gatewayClient->cancelPairing($connection['pairingId'], $pollSecret);
            } catch (ApiRequestFailedException $exception) {
                // Unknown at the Gateway (expired and cleaned up): nothing is open there either.
                $confirmed = $exception->apiErrorCode === 'pairing_not_found';
            }
        }
        $this->connectionStore->cancelPairingIfCurrent($connection['pairingId']);

        return $confirmed ? 'cancelled' : 'unconfirmed';
    }

    /**
     * Activates the stored connection at the Gateway once, with exactly the credential read here. A transport error
     * keeps it pending (retried by the next poll or connector start); a refusal (cancelled, replaced or expired at the
     * Gateway) ends it locally. Either answer only applies while that credential is still stored (second Sol audit
     * A5): a late answer about an earlier connection never touches the one that replaced it. Returns false only when
     * this activation was refused and ended the stored connection.
     */
    public function ensureActivated(): bool
    {
        $credential = $this->connectionStore->currentCredential();
        if ($credential === null || !$credential['activationPending']) {
            return true;
        }
        try {
            $this->gatewayClient->activate($credential['secret']);
        } catch (ApiRequestFailedException $exception) {
            // Only the Gateway's own refusals end the connection. A rate limit, a proxy's or WAF's answer, a disabled
            // Gateway or any transport problem keeps it pending, to be activated by the next poll or connector start.
            if (!in_array($exception->apiErrorCode, self::ACTIVATION_REFUSALS, true)) {
                return true;
            }

            return !$this->connectionStore->markDisconnectedIfCurrent($credential['credentialId'], '');
        }
        $this->connectionStore->markActivatedIfCurrent($credential['credentialId']);

        return true;
    }

    /**
     * Where "Open AQG account" leads: the AQG account page the Gateway names for this environment (`confirmUrl`; the
     * Gateway knows which customer area serves the pairing, TYPO3 does not). Only an absolute http(s) URL without
     * credentials is kept — it is rendered as a link in the backend — else the tab shows the code without the button.
     */
    public static function accountUrl(mixed $value): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return '';
        }
        $parts = parse_url($value);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['https', 'http'], true)
            || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
            return '';
        }

        return $value;
    }

    private function discardLostPairing(string $connectorSecret): void
    {
        try {
            $this->gatewayClient->disconnectWithSecret($connectorSecret, 'Pairing cancelled in TYPO3');
        } catch (ApiRequestFailedException) {
            // The Gateway disconnects an installation that is not activated in time.
        }
    }
}
