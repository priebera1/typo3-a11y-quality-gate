<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

/**
 * V1/V2 of the AQG licence API's MCP contract: this installation's Ed25519 key. A new key pair is made for every pairing;
 * its public half goes to the licence API with the pairing start and is bound to the Gateway installation for good; the
 * secret half never leaves this installation (sealed with the host key in McpConnectionStore, like the connector secret,
 * so a database copy without the host key cannot sign). It signs every consent decision of the consent page; the licence
 * API makes a token usable only for a consent that verifies against the bound key.
 */
final class McpInstallationKey
{
    public const CONSENT_TYPE = 'aqg-mcp-consent';
    /** How long a signed decision may wait for the agent's token exchange (the licence API allows at most 15 minutes). */
    public const CONSENT_LIFETIME_SECONDS = 600;

    /**
     * @return array{secretKey: string, publicKey: string} the 64-byte secret key (raw) and the public key (base64url)
     */
    public static function generate(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'secretKey' => sodium_crypto_sign_secretkey($pair),
            'publicKey' => McpAttestation::base64UrlEncode(sodium_crypto_sign_publickey($pair)),
        ];
    }

    public static function publicKeyOf(string $secretKey): string
    {
        return McpAttestation::base64UrlEncode(sodium_crypto_sign_publickey_from_secretkey($secretKey));
    }

    /**
     * The signed consent the Gateway passes to the licence API: payload (base64url of the exact JSON bytes signed) and
     * detached Ed25519 signature. Binds this installation, the authorization request, the TYPO3 user, the agent client,
     * its redirect URI and PKCE challenge (only the agent holding the verifier can redeem it), the grant and a short
     * window.
     *
     * @param list<string> $scopes
     * @param list<string> $sites
     * @return array{payload: string, signature: string}
     */
    public static function signConsent(string $secretKey, string $installationId, string $requestRef, int $beUserUid, string $clientId,
        string $redirectUri, string $codeChallenge, array $scopes, array $sites, int $durationDays, ?int $now = null): array
    {
        $now ??= time();
        $scopes = array_values(array_unique($scopes));
        $sites = array_values(array_unique($sites));
        sort($scopes);
        sort($sites);
        $payload = json_encode([
            'typ' => self::CONSENT_TYPE,
            'v' => 1,
            'installationId' => $installationId,
            'requestRefHash' => McpConsentLedger::consentRefHash($requestRef),
            'beUserUid' => $beUserUid,
            'clientId' => $clientId,
            'redirectUri' => $redirectUri,
            'codeChallenge' => $codeChallenge,
            'scopes' => $scopes,
            'sites' => $sites,
            'durationDays' => $durationDays,
            'iat' => $now,
            'exp' => $now + self::CONSENT_LIFETIME_SECONDS,
            'nonce' => McpAttestation::base64UrlEncode(random_bytes(16)),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'payload' => McpAttestation::base64UrlEncode($payload),
            'signature' => McpAttestation::base64UrlEncode(sodium_crypto_sign_detached($payload, $secretKey)),
        ];
    }
}
