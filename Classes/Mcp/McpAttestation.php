<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * V3–V5 of the AQG licence API's MCP contract (backend docs/mcp-least-privilege.md): a call runs here only with a call
 * attestation the AQG licence API signed — never on what the MCP Gateway hands over.
 *
 * The licence API signs an attestation (compact JWS, EdDSA) only after it checked the agent's own bearer token against
 * the consent THIS installation signed (V2) and redeemed exactly once. The Gateway relays it; it cannot produce one, so a
 * compromised Gateway can neither invent a consent nor run a call for a token it minted itself, nor change the call's
 * arguments, site or tool after the licence API attested them.
 *
 * Checked here, in this order, before the module's own user, workspace, site and rights checks (V4):
 *   signature with a key this installation pinned from the licence API (rotation: McpAttestationKeys) · issuer and type ·
 *   audience = this installation's id at the Gateway · time window (≤ 60 s, 30 s skew) · call id and tool = the call
 *   being run · arguments = exactly the attested arguments (the attested ones are executed) · token = the begun call's ·
 *   jti never seen before (tx_a11y_mcp_attestation, kept past expiry) · the consent ledger: this installation recorded
 *   and signed that consent for that TYPO3 user, agent client, scopes and sites, it is bound to this one token (first use
 *   binds) and not revoked here. The principal the connector runs as is built from the attestation, never from the
 *   Gateway's begin answer.
 * Every refusal is a McpCallRefused with an `attestation_*` / `consent_*` reason, written to the MCP audit (V5).
 */
final class McpAttestation
{
    public const ISSUER = 'aqg-licence-api';
    public const CALL_TYPE = 'aqg-mcp-call';
    public const CONTINUATION_TYPE = 'aqg-mcp-continuation';
    public const CONTINUATION_PURPOSE = 'deferred_generation';
    public const MAX_CONTINUATION_LIFETIME_SECONDS = 30;
    public const MAX_LIFETIME_SECONDS = 60;
    public const CLOCK_SKEW_SECONDS = 30;
    /** Replay records are kept well past the longest attestation lifetime. */
    private const JTI_RETENTION_SECONDS = 3600;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly McpConsentLedger $consentLedger,
    ) {
    }

    public static function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return is_string($decoded) ? $decoded : null;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /** Key id of a raw Ed25519 public key (base64url): the first 16 hex characters of its SHA-256, as the licence API names it. */
    public static function keyId(string $x): string
    {
        $raw = self::base64UrlDecode($x);

        return $raw === null ? '' : substr(hash('sha256', $raw), 0, 16);
    }

    /**
     * A compact JWS (alg EdDSA) verified against pinned keys by kid; the payload, or null for anything that does not verify.
     *
     * @param list<array{kid: string, x: string}> $pinned
     * @return array<string, mixed>|null
     */
    public static function verifyJws(string $jws, array $pinned): ?array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3 || strlen($jws) > 32768) {
            return null;
        }
        [$headerPart, $payloadPart, $signaturePart] = $parts;
        $header = self::decodeJson(self::base64UrlDecode($headerPart));
        $signature = self::base64UrlDecode($signaturePart);
        if ($header === null || ($header['alg'] ?? null) !== 'EdDSA' || !is_string($header['kid'] ?? null)
            || $signature === null || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }
        foreach ($pinned as $key) {
            if (!hash_equals((string)$key['kid'], $header['kid'])) {
                continue;
            }
            $publicKey = self::base64UrlDecode((string)$key['x']);
            if ($publicKey === null || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return null;
            }
            if (!sodium_crypto_sign_verify_detached($signature, $headerPart . '.' . $payloadPart, $publicKey)) {
                return null;
            }

            return self::decodeJson(self::base64UrlDecode($payloadPart));
        }

        return null;
    }

    /**
     * V4: verifies the attestation of a begun call and returns what the call runs with — the principal and the arguments
     * the licence API attested. Throws McpCallRefused for every failure; nothing has run then.
     *
     * @param array<string, mixed> $call the acknowledged call (id, tool, arguments)
     * @param array<string, mixed> $begunPrincipal the Gateway's begin principal (informational; must agree on the token)
     * @param array{installationId: string, pinnedKeys: list<array{kid: string, x: string}>} $trust
     * @return array{principal: array{tokenId: string, tokenSuffix: string, clientName: string, beUserUid: int, beUserName: string, scopes: list<string>, siteIdentifiers: list<string>, expiresAt: string, consentRefHash: string}, arguments: array<string, mixed>, claims: array{jti: string, callId: string, tool: string, tokenId: string, beUserUid: int, consentRefHash: string, clientId: string, scopes: list<string>, sites: list<string>}}
     */
    public function verifyCall(?string $attestation, array $call, array $begunPrincipal, array $trust, ?int $now = null): array
    {
        $now ??= time();
        if ($attestation === null || $attestation === '') {
            throw new McpCallRefused('attestation_missing', 'This call carries no attestation of the AQG licence API; nothing was run.', 'refused');
        }
        if ($trust['installationId'] === '' || $trust['pinnedKeys'] === []) {
            throw new McpCallRefused('attestation_untrusted', 'This installation has no pinned AQG attestation key; connect it again (Agent access).', 'refused');
        }
        $claims = self::verifyJws($attestation, $trust['pinnedKeys']);
        if ($claims === null || ($claims['typ'] ?? null) !== self::CALL_TYPE || ($claims['iss'] ?? null) !== self::ISSUER) {
            throw new McpCallRefused('attestation_invalid', 'The call attestation does not verify; nothing was run.', 'refused');
        }
        if (!is_string($claims['aud'] ?? null) || !hash_equals($trust['installationId'], $claims['aud'])) {
            throw new McpCallRefused('attestation_invalid', 'The call attestation is for another installation; nothing was run.', 'refused');
        }
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp) || $iat > $now + self::CLOCK_SKEW_SECONDS || $exp <= $now || $exp - $iat > self::MAX_LIFETIME_SECONDS) {
            throw new McpCallRefused('attestation_expired', 'The call attestation has expired; nothing was run.', 'refused');
        }
        if (($claims['callId'] ?? null) !== (string)($call['id'] ?? '') || ($claims['tool'] ?? null) !== (string)($call['tool'] ?? '')) {
            throw new McpCallRefused('attestation_mismatch', 'The call attestation names another call; nothing was run.', 'refused');
        }
        $argsJson = $claims['args'] ?? null;
        if (!is_string($argsJson) || !is_string($claims['argsSha256'] ?? null) || !hash_equals($claims['argsSha256'], hash('sha256', $argsJson))) {
            throw new McpCallRefused('attestation_invalid', 'The call attestation does not verify; nothing was run.', 'refused');
        }
        $attestedArguments = self::decodeJson($argsJson, true);
        $relayed = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
        if ($attestedArguments === null || self::canonical($attestedArguments) !== self::canonical($relayed)) {
            throw new McpCallRefused('attestation_args_mismatch', 'The call arguments differ from what the AQG licence API attested; nothing was run.', 'refused');
        }
        $tokenId = (string)($claims['tokenId'] ?? '');
        if ($tokenId === '' || $tokenId !== (string)($begunPrincipal['tokenId'] ?? '')) {
            throw new McpCallRefused('attestation_mismatch', 'The call attestation names another agent token; nothing was run.', 'refused');
        }
        $jti = $claims['jti'] ?? null;
        if (!is_string($jti) || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $jti) !== 1 || !$this->claimJti($jti, $exp)) {
            throw new McpCallRefused('attestation_replayed', 'This call attestation was used before; nothing was run.', 'refused');
        }
        $strings = static fn (mixed $values): array => is_array($values)
            ? array_values(array_filter(array_map('strval', $values), static fn (string $value): bool => $value !== ''))
            : [];
        $scopes = $strings($claims['scopes'] ?? []);
        $sites = $strings($claims['sites'] ?? []);
        $beUserUid = is_int($claims['beUserUid'] ?? null) ? $claims['beUserUid'] : 0;
        $consentRefHash = strtolower((string)($claims['consentRefHash'] ?? ''));
        $clientId = (string)($claims['clientId'] ?? '');
        $verdict = $this->consentLedger->checkAttested($consentRefHash, $beUserUid, $clientId, $tokenId, $scopes, $sites);
        if ($verdict !== '') {
            throw new McpCallRefused($verdict, 'This installation has no matching consent for this agent token; connect the agent again.', 'refused');
        }
        $tokenExpiresAt = is_int($claims['tokenExpiresAt'] ?? null) ? $claims['tokenExpiresAt'] : $exp;

        return [
            'principal' => [
                'tokenId' => $tokenId,
                'tokenSuffix' => mb_substr((string)($begunPrincipal['tokenSuffix'] ?? ''), 0, 8),
                'clientName' => mb_substr((string)($begunPrincipal['clientName'] ?? ''), 0, 80),
                'beUserUid' => $beUserUid,
                'beUserName' => mb_substr((string)($begunPrincipal['beUserName'] ?? ''), 0, 120),
                // Only scopes this extension knows can authorize anything; the attested set itself was the ledger's.
                'scopes' => McpScopes::normalize($scopes),
                'siteIdentifiers' => $sites,
                'expiresAt' => gmdate('Y-m-d\TH:i:s\Z', $tokenExpiresAt),
                'consentRefHash' => $consentRefHash,
            ],
            'arguments' => $attestedArguments,
            // What a continuation of this call must name again (finding 2, verifyContinuation()).
            'claims' => [
                'jti' => $jti,
                'callId' => (string)$claims['callId'],
                'tool' => (string)$claims['tool'],
                'tokenId' => $tokenId,
                'beUserUid' => $beUserUid,
                'consentRefHash' => $consentRefHash,
                'clientId' => $clientId,
                'scopes' => $scopes,
                'sites' => $sites,
            ],
        ];
    }

    /**
     * Release-gate finding 2: verifies the licence API's continuation of a verified call, right before the call's
     * deferred AI request. Returns the continuation's claims (the grant as core re-read it now). Throws McpCallRefused
     * with continuation_invalid / continuation_expired / continuation_mismatch / continuation_replayed; nothing may run then.
     *
     * @param array{jti: string, callId: string, tool: string, tokenId: string, beUserUid: int, consentRefHash: string, clientId: string} $call verifyCall()['claims']
     * @param array{installationId: string, pinnedKeys: list<array{kid: string, x: string}>} $trust
     * @return array{jti: string, tokenId: string, beUserUid: int, consentRefHash: string, clientId: string, scopes: list<string>, sites: list<string>, tokenExpiresAt: int, exp: int}
     */
    public function verifyContinuation(string $jws, array $call, string $subject, array $trust, ?int $now = null): array
    {
        $now ??= time();
        if ($trust['installationId'] === '' || $trust['pinnedKeys'] === []) {
            throw new McpCallRefused('continuation_invalid', 'This installation has no pinned AQG attestation key.', 'refused');
        }
        $claims = $jws !== '' ? self::verifyJws($jws, $trust['pinnedKeys']) : null;
        if ($claims === null || ($claims['typ'] ?? null) !== self::CONTINUATION_TYPE || ($claims['iss'] ?? null) !== self::ISSUER
            || !is_string($claims['aud'] ?? null) || !hash_equals($trust['installationId'], $claims['aud'])) {
            throw new McpCallRefused('continuation_invalid', 'The continuation does not verify; nothing was generated.', 'refused');
        }
        $iat = $claims['iat'] ?? null;
        $exp = $claims['exp'] ?? null;
        if (!is_int($iat) || !is_int($exp) || $exp - $iat > self::MAX_CONTINUATION_LIFETIME_SECONDS) {
            throw new McpCallRefused('continuation_invalid', 'The continuation does not verify; nothing was generated.', 'refused');
        }
        if ($iat > $now + self::CLOCK_SKEW_SECONDS || $exp <= $now) {
            throw new McpCallRefused('continuation_expired', 'The continuation has expired; nothing was generated.', 'refused');
        }
        $tokenExpiresAt = is_int($claims['tokenExpiresAt'] ?? null) ? $claims['tokenExpiresAt'] : 0;
        if ($tokenExpiresAt <= $now) {
            throw new McpCallRefused('token_expired', 'The agent token has expired; nothing was generated.', 'refused');
        }
        $same = static fn (string $key, mixed $expected): bool => is_string($claims[$key] ?? null) && is_string($expected) && $expected !== ''
            && hash_equals($expected, $claims[$key]);
        $beUserUid = is_int($claims['beUserUid'] ?? null) ? $claims['beUserUid'] : 0;
        if (($claims['purpose'] ?? null) !== self::CONTINUATION_PURPOSE || !$same('subject', $subject)
            || !$same('callId', $call['callId']) || !$same('callJti', $call['jti']) || !$same('tool', $call['tool'])
            || !$same('tokenId', $call['tokenId']) || !$same('consentRefHash', $call['consentRefHash']) || !$same('clientId', $call['clientId'])
            || $beUserUid <= 0 || $beUserUid !== (int)$call['beUserUid']) {
            throw new McpCallRefused('continuation_mismatch', 'The continuation names another call, job, token or user; nothing was generated.', 'refused');
        }
        $jti = $claims['jti'] ?? null;
        if (!is_string($jti) || preg_match('/^[A-Za-z0-9_-]{16,64}$/', $jti) !== 1) {
            throw new McpCallRefused('continuation_invalid', 'The continuation does not verify; nothing was generated.', 'refused');
        }
        // The same replay table as call attestations: a continuation is used once, ever.
        if (!$this->claimJti('continuation:' . $jti, $exp)) {
            throw new McpCallRefused('continuation_replayed', 'This continuation was used before; nothing was generated.', 'refused');
        }
        $strings = static fn (mixed $values): array => is_array($values)
            ? array_values(array_filter(array_map('strval', $values), static fn (string $value): bool => $value !== ''))
            : [];

        return [
            'jti' => $jti,
            'tokenId' => (string)$claims['tokenId'],
            'beUserUid' => $beUserUid,
            'consentRefHash' => (string)$claims['consentRefHash'],
            'clientId' => (string)$claims['clientId'],
            'scopes' => $strings($claims['scopes'] ?? []),
            'sites' => $strings($claims['sites'] ?? []),
            'tokenExpiresAt' => $tokenExpiresAt,
            'exp' => $exp,
        ];
    }

    /** Records an attestation id once; false when it was seen before (replay). */
    private function claimJti(string $jti, int $exp): bool
    {
        try {
            // Stored as SHA-256 hex: the id is case-sensitive base64url, the column's collation may not be.
            $this->connectionPool->getConnectionForTable(Tables::MCP_ATTESTATION)->insert(Tables::MCP_ATTESTATION, [
                'jti_hash' => hash('sha256', $jti),
                'expires' => $exp,
                'crdate' => time(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    public function prune(): int
    {
        return (int)$this->connectionPool->getConnectionForTable(Tables::MCP_ATTESTATION)->executeStatement(
            'DELETE FROM ' . Tables::MCP_ATTESTATION . ' WHERE expires < ?',
            [time() - self::JTI_RETENTION_SECONDS],
            [Connection::PARAM_INT],
        );
    }

    /**
     * Order-independent form of decoded JSON for comparing relayed and attested arguments (objects by key; lists keep
     * their order). Both sides are decoded by PHP the same way, so `{}` and `[]` compare alike — as the executor reads them.
     */
    public static function canonical(mixed $value): string
    {
        $normalize = static function (mixed $item) use (&$normalize): mixed {
            if (!is_array($item)) {
                return $item;
            }
            if (!array_is_list($item)) {
                ksort($item, SORT_STRING);
            }

            return array_map($normalize, $item);
        };

        return (string)json_encode($normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJson(?string $json, bool $allowList = false): ?array
    {
        if ($json === null || $json === '' || strlen($json) > 65536) {
            return null;
        }
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || (!$allowList && array_is_list($decoded) && $decoded !== [])) {
            return null;
        }

        return $decoded;
    }
}
