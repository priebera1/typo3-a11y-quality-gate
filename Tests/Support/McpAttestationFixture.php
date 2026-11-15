<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Support;

use Priebera\A11yQualityGate\Mcp\McpAttestation;

/**
 * A stand-in for the AQG licence API's attestation key in tests: signs key sets, binding receipts and call attestations
 * exactly in the licence API's format (aqg-api services/mcp/attestation-keys.ts, consent-authority.ts), so the
 * extension's V1/V4 checks run against real Ed25519 signatures. Never a production key.
 */
final class McpAttestationFixture
{
    public const INSTALLATION_ID = '0f0e0d0c-0b0a-4908-8706-050403020100';

    public readonly string $secretKey;
    public readonly string $x;
    public readonly string $kid;

    public function __construct()
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->x = McpAttestation::base64UrlEncode(sodium_crypto_sign_publickey($pair));
        $this->kid = McpAttestation::keyId($this->x);
    }

    /** @return list<array{kid: string, x: string}> */
    public function pinned(): array
    {
        return [['kid' => $this->kid, 'x' => $this->x]];
    }

    /** @param array<string, mixed> $payload */
    public function sign(array $payload): string
    {
        $header = McpAttestation::base64UrlEncode((string)json_encode(['alg' => 'EdDSA', 'typ' => 'JWT', 'kid' => $this->kid]));
        $body = McpAttestation::base64UrlEncode((string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $header . '.' . $body . '.' . McpAttestation::base64UrlEncode(sodium_crypto_sign_detached($header . '.' . $body, $this->secretKey));
    }

    /** @param list<array{kid: string, x: string}>|null $keys */
    public function keySet(?array $keys = null): string
    {
        return $this->sign(['typ' => 'aqg-mcp-keyset', 'iss' => McpAttestation::ISSUER, 'iat' => time(), 'keys' => $keys ?? $this->pinned()]);
    }

    public function binding(string $installationId, string $pairingId, string $installationKey): string
    {
        return $this->sign([
            'typ' => 'aqg-mcp-binding', 'iss' => McpAttestation::ISSUER, 'aud' => $installationId, 'installationId' => $installationId,
            'pairingId' => $pairingId, 'slug' => 'slug', 'installationKey' => $installationKey, 'iat' => time(),
        ]);
    }

    /**
     * A call attestation as the licence API signs it. `$arguments` are canonicalised (keys sorted) like the licence API.
     *
     * @param array<string, mixed> $arguments
     * @param list<string> $scopes
     * @param list<string> $sites
     * @param array<string, mixed> $override
     */
    public function attest(string $callId, string $tool, array $arguments, string $tokenId, string $consentRefHash, int $beUserUid,
        string $clientId, array $scopes, array $sites, array $override = [], string $installationId = self::INSTALLATION_ID): string
    {
        $args = self::canonicalJson($arguments);
        $now = time();

        return $this->sign($override + [
            'typ' => 'aqg-mcp-call', 'iss' => McpAttestation::ISSUER, 'aud' => $installationId, 'sub' => $tokenId,
            'jti' => McpAttestation::base64UrlEncode(random_bytes(18)), 'iat' => $now, 'exp' => $now + 60,
            'callId' => $callId, 'tool' => $tool, 'args' => $args, 'argsSha256' => hash('sha256', $args),
            'tokenId' => $tokenId, 'consentRefHash' => $consentRefHash, 'beUserUid' => $beUserUid, 'clientId' => $clientId,
            'scopes' => $scopes, 'sites' => $sites, 'tokenExpiresAt' => $now + 3600,
        ]);
    }

    /** The licence API's canonical JSON (canonicalJson in attestation-keys.ts). */
    public static function canonicalJson(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(self::canonicalJson(...), $value)) . ']';
            }
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = json_encode((string)$key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ':' . self::canonicalJson($item);
            }

            return '{' . implode(',', $parts) . '}';
        }

        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
