<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;

/**
 * G-02 (AQG 3.0.0 security gate): the trust root for the AQG licence API's MCP attestation keys.
 *
 * Before, a pairing without AQG_MCP_ATTESTATION_KEYS pinned whatever key set answered `/licence/mcp/attestation-keys`
 * (trust on first use). Whoever answered that request at pairing time (TLS interception, an http development override,
 * a misconfigured service URL) could then forge call attestations within an existing consent.
 *
 * Now:
 *  - the AQG production attestation public key ships with the extension (PRODUCTION_ROOTS; public keys only, never a
 *    secret). A key set is pinned at pairing only when it is signed by a root: a built-in one or one configured in
 *    AQG_MCP_ATTESTATION_KEYS (comma-separated raw base64url Ed25519 keys, for an own staging service);
 *  - a key set signed by no root is never accepted for the AQG service itself, in any context. Only a development setup
 *    that points AQG at its own service (A11Y_QUALITY_GATE_PRO_API_BASE_URL) outside TYPO3's Production context, or the
 *    test suite (Testing context), may still pin an unrooted key set — recorded as trust chain `development`, which is
 *    refused again as soon as the context is Production or the service is AQG's;
 *  - every pairing records its chain (`root` / `development`). It survives rotation: a rotated key set is accepted only
 *    when signed by a key already pinned (McpAttestationKeys::refresh()). A connection paired before this change has no
 *    recorded chain; its pins count as `root` when they contain a root key (the production key the pilot installations
 *    pinned), otherwise they are not trusted (no call runs; connect again).
 *
 * Rotation: the licence API announces its next key in the key set it signs with the current one (both are pinned), then
 * switches. Every extension release ships the then current and announced keys here; after the switch, an extension
 * release older than the announcement can no longer start a NEW pairing (attestation_keys_untrusted) until it is updated —
 * existing pairings keep rotating through their pins.
 */
final class McpTrustRoots
{
    /**
     * AQG licence API production attestation keys (Ed25519, raw base64url `x`). kid = first 16 hex of SHA-256(raw).
     * d95fdde9bf78f61f: provisioned 2026-11-14 (AQG backend 3.0.0, `aqg-api/.env.production` on the production host),
     * served at https://api.priebera.sk/licence/mcp/attestation-keys.
     */
    public const PRODUCTION_ROOTS = [
        'fuewRMO2btJ6c8BZyuwRmUVpM45uwjMVFECEGr_7VXM',
    ];

    public const ENV = 'AQG_MCP_ATTESTATION_KEYS';
    public const CHAIN_ROOT = 'root';
    public const CHAIN_DEVELOPMENT = 'development';

    /**
     * Built-in roots plus the configured ones.
     *
     * @return list<array{kid: string, x: string}>
     */
    public static function roots(): array
    {
        $keys = [];
        $configured = getenv(self::ENV);
        foreach ([...self::PRODUCTION_ROOTS, ...(is_string($configured) ? explode(',', $configured) : [])] as $x) {
            $x = trim((string)$x);
            if (preg_match('/^[A-Za-z0-9_-]{43}$/', $x) === 1) {
                $kid = McpAttestation::keyId($x);
                if ($kid !== '') {
                    $keys[$kid] = ['kid' => $kid, 'x' => $x];
                }
            }
        }

        return array_values($keys);
    }

    /** Whether AQG_MCP_ATTESTATION_KEYS names at least one valid key: then only roots decide, in every context. */
    public static function hasConfiguredRoots(): bool
    {
        $configured = getenv(self::ENV);

        return is_string($configured)
            && array_filter(explode(',', $configured), static fn (string $x): bool => preg_match('/^[A-Za-z0-9_-]{43}$/', trim($x)) === 1) !== [];
    }

    /**
     * Whether a key set signed by no root may be pinned (and such a pin used): only the test suite, or a development
     * setup with its own AQG service outside the Production context. Never for AQG's own service.
     */
    public static function allowsUnrootedTrust(?ApplicationContext $context = null, ?string $apiBaseUrl = null): bool
    {
        $context ??= Environment::getContext();
        if ($context->isTesting()) {
            // The test suite only: a host that merely sets TYPO3_CONTEXT=Testing without the testing framework gains nothing.
            return class_exists(\TYPO3\TestingFramework\Core\BaseTestCase::class);
        }
        if ($context->isProduction()) {
            return false;
        }

        return !self::isAqgService($apiBaseUrl ?? ProSettings::resolveApiBaseUrl());
    }

    /** Whether $url names AQG's own service (scheme, host and port compared; host case and default port ignored). */
    public static function isAqgService(string $url): bool
    {
        $origin = static function (string $value): string {
            $parts = parse_url(trim($value));
            if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
                return '';
            }
            $scheme = strtolower((string)$parts['scheme']);
            $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : ($scheme === 'http' ? 80 : 0)));

            return $scheme . '://' . rtrim(strtolower((string)$parts['host']), '.') . ':' . $port;
        };
        $candidate = $origin($url);

        return $candidate === '' || $candidate === $origin(ProConstants::API_BASE_URL);
    }

    /**
     * The chain a stored connection's pins have now: `root`, `development`, or '' (not trusted: no call may run).
     *
     * @param list<array{kid: string, x: string}> $pins
     * @param bool|null $unrootedAllowed null = allowsUnrootedTrust() for this request/process
     */
    public static function effectiveChain(string $storedChain, array $pins, ?bool $unrootedAllowed = null): string
    {
        if ($pins === []) {
            return '';
        }
        if ($storedChain === self::CHAIN_ROOT) {
            return self::CHAIN_ROOT;
        }
        if ($storedChain === '' && self::allPinsAreRoots($pins)) {
            // Paired before the chain was recorded with root keys only (the pilot installations pinned the production key).
            // Never "contains a root": a root's public key is public, so an unrooted set can list it next to its own key.
            return self::CHAIN_ROOT;
        }

        return ($unrootedAllowed ?? self::allowsUnrootedTrust()) ? self::CHAIN_DEVELOPMENT : '';
    }

    /** @param list<array{kid: string, x: string}> $pins every pin is a trust root (and there is at least one) */
    public static function allPinsAreRoots(array $pins): bool
    {
        $roots = array_column(self::roots(), 'x', 'kid');
        foreach ($pins as $pin) {
            if (!isset($roots[$pin['kid'] ?? '']) || !hash_equals($roots[$pin['kid']], (string)($pin['x'] ?? ''))) {
                return false;
            }
        }

        return $pins !== [];
    }
}
