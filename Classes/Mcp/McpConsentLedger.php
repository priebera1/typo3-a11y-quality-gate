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
    public function record(string $requestRef, int $beUserUid, array $scopes, array $siteIdentifiers): bool
    {
        if ($requestRef === '' || $beUserUid <= 0) {
            throw new \InvalidArgumentException('A consent needs a request reference and a TYPO3 user.', 1793400101);
        }
        try {
            $this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT)->insert(Tables::MCP_CONSENT, [
                'consent_ref_hash' => self::consentRefHash($requestRef),
                'be_user_uid' => $beUserUid,
                'grant_hash' => self::grantHash($scopes, $siteIdentifiers),
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
        return (int)$this->connectionPool->getConnectionForTable(Tables::MCP_CONSENT)->executeStatement(
            'DELETE FROM ' . Tables::MCP_CONSENT . ' WHERE crdate < ?',
            [time() - self::RETENTION_SECONDS],
            [Connection::PARAM_INT],
        );
    }
}
