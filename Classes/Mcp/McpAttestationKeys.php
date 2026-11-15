<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;

/**
 * The AQG licence API keys this installation trusts for call attestations and binding receipts (V3/V4).
 *
 * They are read from the licence API itself (`/licence/mcp/attestation-keys`, the path /licence/validate lives under —
 * served by the licence API, never by the MCP Gateway process) as a key set the licence API signed:
 *   - at pairing: the key set must be signed by a trust root (McpTrustRoots: the AQG production key shipped with the
 *     extension, plus AQG_MCP_ATTESTATION_KEYS). G-02: never trusted on first use for AQG's own service; only a
 *     development setup with its own service outside the Production context (or the test suite) may pin an unrooted set;
 *   - afterwards (connector start and hourly): a key set is accepted only when signed by a key already pinned, and then
 *     replaces the pins — this is how the licence API rotates (announce the next key, then switch to it). The pairing's
 *     trust chain is kept across rotations.
 * A key set that does not verify changes nothing; the pinned keys keep working until the licence API's key changes.
 */
final class McpAttestationKeys
{
    public const KEYSET_TYPE = 'aqg-mcp-keyset';
    public const TRUST_ENV = McpTrustRoots::ENV;

    public function __construct(
        private readonly McpGatewayClient $gatewayClient,
        private readonly McpConnectionStore $connectionStore,
    ) {
    }

    /**
     * The keys to pin for a new pairing. Throws when the licence API cannot be read or its key set does not verify
     * against the configured trust roots — then no pairing is started.
     *
     * @return list<array{kid: string, x: string}>
     * @throws ApiRequestFailedException
     */
    public function keysForPairing(): array
    {
        return $this->pairingTrust()['keys'];
    }

    /**
     * The keys to pin for a new pairing and the trust chain they were accepted on: `root` (signed by a trust root) or,
     * only where McpTrustRoots::allowsUnrootedTrust(), `development` (signed by a key of the same answer).
     *
     * @return array{keys: list<array{kid: string, x: string}>, trustChain: string}
     * @throws ApiRequestFailedException
     */
    public function pairingTrust(): array
    {
        $response = $this->gatewayClient->attestationKeys();
        $keySet = (string)($response['keySet'] ?? '');
        $keys = self::verifiedKeySet($keySet, McpTrustRoots::roots());
        if ($keys !== null) {
            return ['keys' => $keys, 'trustChain' => McpTrustRoots::CHAIN_ROOT];
        }
        // A configured root is an explicit trust decision: with one, an unrooted key set is refused in every context.
        if (McpTrustRoots::allowsUnrootedTrust() && !McpTrustRoots::hasConfiguredRoots()) {
            $keys = self::verifiedKeySet($keySet, self::keyList($response['keys'] ?? null));
            if ($keys !== null) {
                return ['keys' => $keys, 'trustChain' => McpTrustRoots::CHAIN_DEVELOPMENT];
            }
        }

        throw new ApiRequestFailedException('The AQG licence API attestation keys could not be verified.', 0, null, 'attestation_keys_untrusted');
    }

    /** Re-reads the key set and replaces the pins when it is signed by a pinned key. Never throws. */
    public function refresh(): bool
    {
        $pinned = $this->connectionStore->attestationTrust()['pinnedKeys'];
        if ($pinned === []) {
            return false;
        }
        try {
            $response = $this->gatewayClient->attestationKeys();
        } catch (ApiRequestFailedException) {
            return false;
        }
        // G-02: a connection paired before the chain was recorded keeps it from now on (only reached when its pins are
        // trusted: attestationTrust() returns no pins otherwise, and then nothing rotates either).
        $this->connectionStore->recordTrustChain($this->connectionStore->attestationTrust()['trustChain']);
        $keys = self::verifiedKeySet((string)($response['keySet'] ?? ''), $pinned);
        $issuedAt = self::keySetIssuedAt((string)($response['keySet'] ?? ''), $pinned);

        // A key set older than the newest one accepted here is never applied: a replayed earlier set (still signed by a
        // pinned key) cannot roll the pins back during a rotation.
        return $keys !== null && $issuedAt !== null && $this->connectionStore->replacePinnedKeys($keys, $issuedAt);
    }

    /**
     * @param list<array{kid: string, x: string}> $trusted
     * @return list<array{kid: string, x: string}>|null
     */
    public static function verifiedKeySet(string $keySet, array $trusted): ?array
    {
        if ($trusted === []) {
            return null;
        }
        $claims = McpAttestation::verifyJws($keySet, $trusted);
        if ($claims === null || ($claims['typ'] ?? null) !== self::KEYSET_TYPE || ($claims['iss'] ?? null) !== McpAttestation::ISSUER) {
            return null;
        }
        $keys = self::keyList($claims['keys'] ?? null);

        return $keys === [] ? null : $keys;
    }

    /** The verified key set's issue time (seconds), null when it does not verify or names none. */
    public static function keySetIssuedAt(string $keySet, array $trusted): ?int
    {
        $claims = McpAttestation::verifyJws($keySet, $trusted);
        $iat = $claims['iat'] ?? null;

        return is_int($iat) && $iat > 0 && $iat <= time() + McpAttestation::CLOCK_SKEW_SECONDS ? $iat : null;
    }

    /**
     * The trust roots (built-in production key + AQG_MCP_ATTESTATION_KEYS).
     *
     * @return list<array{kid: string, x: string}>
     */
    public static function configuredRoots(): array
    {
        return McpTrustRoots::roots();
    }

    /**
     * @return list<array{kid: string, x: string}>
     */
    private static function keyList(mixed $value): array
    {
        $keys = [];
        foreach (is_array($value) ? $value : [] as $key) {
            if (is_array($key) && is_string($key['x'] ?? null) && preg_match('/^[A-Za-z0-9_-]{43}$/', $key['x']) === 1
                && hash_equals(McpAttestation::keyId($key['x']), (string)($key['kid'] ?? ''))) {
                $keys[] = ['kid' => (string)$key['kid'], 'x' => $key['x']];
            }
        }

        return array_slice($keys, 0, 8);
    }

    /**
     * V1: the licence API's receipt that the Gateway installation `$installationId` is bound to exactly this
     * installation's public key (a key swapped on the way to the licence API shows here). Signed by a pinned key.
     *
     * @param list<array{kid: string, x: string}> $pinned
     */
    public static function bindingVerifies(string $binding, array $pinned, string $installationId, string $pairingId, string $publicKey): bool
    {
        $claims = McpAttestation::verifyJws($binding, $pinned);

        return $claims !== null
            && ($claims['typ'] ?? null) === 'aqg-mcp-binding'
            && ($claims['iss'] ?? null) === McpAttestation::ISSUER
            && $installationId !== '' && ($claims['installationId'] ?? null) === $installationId && ($claims['aud'] ?? null) === $installationId
            && ($claims['pairingId'] ?? null) === $pairingId
            && $publicKey !== '' && is_string($claims['installationKey'] ?? null) && hash_equals($publicKey, $claims['installationKey']);
    }
}
