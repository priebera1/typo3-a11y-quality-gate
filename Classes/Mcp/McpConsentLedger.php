<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * The consents this installation decided itself (clone safety N2, Gateway migration 097).
 *
 * The consent page records the authorization request it is about to allow — SHA-256 of the request reference, the
 * value the Gateway stores as the token's `consent_ref_hash` — together with the TYPO3 user, before the decision is
 * sent. The connector runs a call only when the call's token names a consent recorded here for that same user. A copy
 * of this installation (database and connector secret) can mint a token at the Gateway, but its consent lands in the
 * copy's database, so the original refuses every call of that token (`consent_unknown`) and the Gateway revokes it.
 *
 * The row also records the grant this page decided (scopes and sites): the connector runs a call only when the
 * token's own scopes and sites are that grant, so a decision made elsewhere for the same request (a copy holding the
 * connector secret and the request reference) cannot widen it. Rows are kept longer than the longest token (90 days)
 * and pruned; a decision the Gateway did not confirm is withdrawn. The table lives in the shared TYPO3 database, so every web host and the connector see it.
 *
 * Attested execution (V2/V4, AQG 3.0.0): a consent this page also signed with the installation key is recorded with the
 * agent client it was given to (`signed`, `client_id`). The connector then accepts a call only through
 * checkAttested(): the licence API's attestation must name exactly this consent, user, client and grant, the consent is
 * bound to the first token that uses it (a second token for the same consent is refused — the licence API redeems a
 * signed consent once, this is the second line) and a token revoked here (revokeToken) never runs again, whether or not
 * the Gateway carries the revocation out.
 */
final class McpConsentLedger
{
    /** Longer than the longest token (90 days) plus the 10-minute authorization request. */
    public const RETENTION_SECONDS = 100 * 86400;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /** The Gateway's consent reference of an authorization request: SHA-256 (hex) of the request reference. */
    public static function consentRefHash(string $requestRef): string
    {
        return hash('sha256', $requestRef);
    }

    public static function isConsentRefHash(string $value): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $value) === 1;
    }

    /**
     * The grant as one value: scopes and sites, order-independent.
     *
     * @param list<string> $scopes
     * @param list<string> $siteIdentifiers
     */
    public static function grantHash(array $scopes, array $siteIdentifiers): string
    {
        $scopes = array_values(array_unique($scopes));
        $siteIdentifiers = array_values(array_unique($siteIdentifiers));
        sort($scopes);
        sort($siteIdentifiers);

        return hash('sha256', 'aqg-mcp-grant:v1|' . implode(' ', $scopes) . '|' . implode(' ', $siteIdentifiers));
    }

    /**
     * Claims the authorization request `$requestRef` for `$beUserUid` with exactly this grant. Only the first claim
     * succeeds (unique key): a second submission of the same request (a double click, a second tab with another
     * selection) gets false and must neither decide nor withdraw, since the row belongs to the first submission.
     *
     * @param list<string> $scopes
     * @param list<string> $siteIdentifiers
     */
    public function record(string $requestRef, int $beUserUid, array $scopes, array $siteIdentifiers, string $clientId = ''): bool
    {
        if ($requestRef === '' || $beUserUid <= 0) {
            throw new \InvalidArgumentException('A consent needs a request reference and a TYPO3 user.', 1793400101);
        }
        try {
            $this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT)->insert(Tables::MCP_CONSENT, [
                'consent_ref_hash' => self::consentRefHash($requestRef),
                'be_user_uid' => $beUserUid,
                'grant_hash' => self::grantHash($scopes, $siteIdentifiers),
                'client_id' => mb_substr($clientId, 0, 255),
                'signed' => $clientId !== '' ? 1 : 0,
                'crdate' => time(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Whether this installation recorded this consent for exactly this TYPO3 user and exactly this grant.
     *
     * @param list<string> $scopes
     * @param list<string> $siteIdentifiers
     */
    public function isRecorded(string $consentRefHash, int $beUserUid, array $scopes, array $siteIdentifiers): bool
    {
        if (!self::isConsentRefHash($consentRefHash) || $beUserUid <= 0) {
            return false;
        }
        $stored = $this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT)->select(
            ['grant_hash'],
            Tables::MCP_CONSENT,
            ['consent_ref_hash' => $consentRefHash, 'be_user_uid' => $beUserUid],
        )->fetchOne();

        return is_string($stored) && $stored !== '' && hash_equals($stored, self::grantHash($scopes, $siteIdentifiers));
    }

    /**
     * V4: '' when this installation recorded and signed exactly this consent — user, agent client, scopes and sites — and
     * the consent belongs to this token (first use binds it) that was not revoked here; otherwise the refusal reason.
     *
     * @param list<string> $scopes
     * @param list<string> $siteIdentifiers
     */
    public function checkAttested(string $consentRefHash, int $beUserUid, string $clientId, string $tokenId, array $scopes, array $siteIdentifiers): string
    {
        if (!self::isConsentRefHash($consentRefHash) || $beUserUid <= 0 || $clientId === '' || preg_match('/^[0-9a-f-]{36}$/i', $tokenId) !== 1) {
            return 'consent_unknown';
        }
        $connection = $this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT);
        $row = $connection->select(
            ['grant_hash', 'client_id', 'token_id', 'signed', 'revoked'],
            Tables::MCP_CONSENT,
            ['consent_ref_hash' => $consentRefHash, 'be_user_uid' => $beUserUid],
        )->fetchAssociative();
        if (!is_array($row) || (int)$row['signed'] !== 1 || !hash_equals((string)$row['grant_hash'], self::grantHash($scopes, $siteIdentifiers))
            || !hash_equals((string)$row['client_id'], $clientId)) {
            return 'consent_unknown';
        }
        if ((int)$row['revoked'] === 1 || $this->isTokenRevoked($tokenId)) {
            return 'token_revoked';
        }
        $tokenId = strtolower($tokenId);
        if ((string)$row['token_id'] === '') {
            // First use binds the consent to this token, compare-and-set: of two racing tokens only one can win.
            $connection->update(
                Tables::MCP_CONSENT,
                ['token_id' => $tokenId],
                ['consent_ref_hash' => $consentRefHash, 'be_user_uid' => $beUserUid, 'token_id' => ''],
            );
            $bound = $connection->select(['token_id'], Tables::MCP_CONSENT, ['consent_ref_hash' => $consentRefHash, 'be_user_uid' => $beUserUid])->fetchOne();
        } else {
            $bound = $row['token_id'];
        }

        return is_string($bound) && hash_equals($bound, $tokenId) ? '' : 'consent_token_mismatch';
    }

    /**
     * Revokes one consent of this user here (V4), bound to a token or not yet used: no token of it ever runs again on this
     * installation, whatever the Gateway does. Keyed by user, so a consent reference can never reach another user's row.
     */
    public function revokeConsent(string $consentRefHash, int $beUserUid): void
    {
        if (!self::isConsentRefHash($consentRefHash) || $beUserUid <= 0) {
            return;
        }
        $this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT)->update(
            Tables::MCP_CONSENT,
            ['revoked' => 1],
            ['consent_ref_hash' => $consentRefHash, 'be_user_uid' => $beUserUid],
        );
    }

    /**
     * Local part of revoking an agent connection, written before the Gateway is asked (V4): the token by id, its bound
     * consent, and the consent the Gateway's token list names for that user.
     *
     * @param array<string, mixed>|null $listed the connection as the Gateway listed it (consentRefHash, beUserUid)
     */
    public function revokeConnection(string $tokenId, ?array $listed, int $beUserUid): void
    {
        $this->revokeToken($tokenId);
        $bound = $this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT)->select(
            ['consent_ref_hash', 'be_user_uid'],
            Tables::MCP_CONSENT,
            ['token_id' => strtolower($tokenId)],
        )->fetchAllAssociative();
        foreach ($bound as $row) {
            $this->revokeConsent((string)$row['consent_ref_hash'], (int)$row['be_user_uid']);
        }
        if (is_array($listed) && is_string($listed['consentRefHash'] ?? null) && $beUserUid > 0) {
            $this->revokeConsent(strtolower($listed['consentRefHash']), $beUserUid);
        }
    }

    /** Records a revocation here first (V4): this token never runs again on this installation. */
    public function revokeToken(string $tokenId): void
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $tokenId) !== 1) {
            return;
        }
        try {
            $this->connectionPool->getConnectionForTable(Tables::MCP_TOKEN_REVOCATION)->insert(Tables::MCP_TOKEN_REVOCATION, [
                'token_id' => strtolower($tokenId),
                'crdate' => time(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already revoked here.
        }
    }

    /** Whether one of this user's consents is bound to this token (a token that ran here at least once). */
    public function isTokenOfUser(string $tokenId, int $beUserUid): bool
    {
        return preg_match('/^[0-9a-f-]{36}$/i', $tokenId) === 1 && $beUserUid > 0
            && $this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT)->count(
                'uid',
                Tables::MCP_CONSENT,
                ['token_id' => strtolower($tokenId), 'be_user_uid' => $beUserUid],
            ) > 0;
    }

    public function isTokenRevoked(string $tokenId): bool
    {
        return $this->connectionPool->getConnectionForTable(Tables::MCP_TOKEN_REVOCATION)->count(
            'uid',
            Tables::MCP_TOKEN_REVOCATION,
            ['token_id' => strtolower($tokenId)],
        ) > 0;
    }

    /** Withdraws a consent whose decision the Gateway did not confirm (refused, failed or not answered). */
    public function withdraw(string $requestRef, int $beUserUid): void
    {
        $this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT)->delete(
            Tables::MCP_CONSENT,
            ['consent_ref_hash' => self::consentRefHash($requestRef), 'be_user_uid' => $beUserUid],
        );
    }

    public function prune(): int
    {
        $this->connectionPool->getConnectionForTable(Tables::MCP_TOKEN_REVOCATION)->executeStatement(
            'DELETE FROM ' . Tables::MCP_TOKEN_REVOCATION . ' WHERE crdate < ?',
            [time() - self::RETENTION_SECONDS],
            [Connection::PARAM_INT],
        );

        return (int)$this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT)->executeStatement(
            'DELETE FROM ' . Tables::MCP_CONSENT . ' WHERE crdate < ?',
            [time() - self::RETENTION_SECONDS],
            [Connection::PARAM_INT],
        );
    }
}
