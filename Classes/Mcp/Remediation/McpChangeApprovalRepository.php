<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp\Remediation;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Approvals of exact agent changes (remediations:apply-approved, post-3.0.0): tx_a11y_mcp_change_approval.
 *
 * A row binds one person (the token's TYPO3 user), one agent token, one site, one AI Fixes item and proposal version,
 * one target (table, uid, field, page, language, workspace) and the exact write (SHA-256 of the stored value before, the
 * value after and the validated suggestion), together as `binding_hash`. Every state change is a compare-and-set on the
 * state the caller read, so of two racing requests exactly one wins:
 *
 *   pending ──(the person, in the TYPO3 backend)──▶ approved ──(connector, once)──▶ applying ──▶ applied | failed
 *      │                                              │                               └─▶ approved (nothing written: retry)
 *      └──▶ rejected | expired | stale | superseded   └──▶ expired | stale
 *
 * Hashes, ids and codes only: no content, prompt or token.
 */
final class McpChangeApprovalRepository
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const APPLYING = 'applying';
    public const APPLIED = 'applied';
    public const FAILED = 'failed';
    public const REJECTED = 'rejected';
    public const EXPIRED = 'expired';
    public const STALE = 'stale';
    public const SUPERSEDED = 'superseded';

    /** A request waits this long for the person's decision. */
    public const PENDING_TTL = 1800;
    /** An approval must be applied within this time after the person approved it. */
    public const APPROVED_TTL = 900;
    /** An apply that has not finished after this time was interrupted (connector stopped mid-call). */
    public const APPLY_INTERRUPTED_AFTER = 300;

    /** Approval requests of one agent token that may wait for the person at the same time. */
    public const MAX_OPEN_PER_TOKEN = 5;

    /** The fields `binding_hash` covers, in this order. */
    private const BINDING_FIELDS = [
        'be_user_uid', 'token_id', 'site_identifier', 'item_uid', 'finding_uid', 'proposal_version', 'item_row_version',
        'target_kind', 'target_table', 'target_uid', 'target_field', 'page_uid', 'language_uid', 'workspace_id',
        'base_hash', 'before_hash', 'after_hash', 'value_hash',
    ];

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    /**
     * The binding of a preview (RemediationWorkflowService::directApplyPreview(), state ok) for this person and token.
     *
     * @param array<string, mixed> $preview
     * @return array<string, int|string>
     */
    public static function bindingOf(array $preview, int $beUserUid, string $tokenId): array
    {
        return [
            'be_user_uid' => $beUserUid,
            'token_id' => strtolower($tokenId),
            'site_identifier' => (string)$preview['siteIdentifier'],
            'item_uid' => (int)$preview['itemUid'],
            'finding_uid' => (int)$preview['findingUid'],
            'proposal_version' => (int)$preview['proposalVersion'],
            'item_row_version' => (int)$preview['rowVersion'],
            'target_kind' => (string)$preview['kind'],
            'target_table' => (string)$preview['table'],
            'target_uid' => (int)$preview['uid'],
            'target_field' => (string)$preview['field'],
            'page_uid' => (int)$preview['pageUid'],
            'language_uid' => (int)$preview['languageUid'],
            'workspace_id' => (int)$preview['workspaceId'],
            'base_hash' => (string)$preview['baseHash'],
            'before_hash' => (string)$preview['beforeHash'],
            'after_hash' => (string)$preview['afterHash'],
            'value_hash' => (string)$preview['valueHash'],
        ];
    }

    /** @param array<string, mixed> $binding bindingOf() or a stored row */
    public static function bindingHash(array $binding): string
    {
        $values = [];
        foreach (self::BINDING_FIELDS as $field) {
            $values[$field] = (string)($binding[$field] ?? '');
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Records a new request and supersedes the person's earlier open requests of the same item from the same token: only
     * the newest request can ever be approved.
     *
     * @param array<string, int|string> $binding bindingOf()
     * @return array<string, mixed> the new row
     */
    public function create(array $binding, string $clientName, int $now): array
    {
        $ref = bin2hex(random_bytes(16));
        // One transaction: no reader sees the new request beside the ones it supersedes. Requests of the same item by
        // the person's other agents are superseded too, so only the newest request of an item can be approved.
        $this->connection()->transactional(function (Connection $connection) use ($binding, $clientName, $now, $ref): void {
            $connection->executeStatement(
                'UPDATE ' . Tables::MCP_CHANGE_APPROVAL . ' SET status = ?, status_reason = ?, tstamp = ? WHERE item_uid = ? AND be_user_uid = ? AND status IN (?, ?)',
                [self::SUPERSEDED, 'new_request', $now, (int)$binding['item_uid'], (int)$binding['be_user_uid'], self::PENDING, self::APPROVED],
            );
            // An apply that was interrupted (connector killed mid-call) and whose token can no longer ask for its status
            // (revoked, expired) would stay "applying" forever. A new request exists only once AI Fixes has the item back
            // as not written (failed: interrupted, or pending again), so such an approval is closed as not written.
            $connection->executeStatement(
                'UPDATE ' . Tables::MCP_CHANGE_APPROVAL . ' SET status = ?, status_reason = ?, result_status = ?, tstamp = ? WHERE item_uid = ? AND status = ? AND apply_started_at < ?',
                [self::FAILED, 'interrupted', 'unknown', $now, (int)$binding['item_uid'], self::APPLYING, $now - self::APPLY_INTERRUPTED_AFTER],
            );
            $connection->insert(Tables::MCP_CHANGE_APPROVAL, $binding + [
                'crdate' => $now,
                'tstamp' => $now,
                'approval_ref' => $ref,
                'status' => self::PENDING,
                'client_name' => mb_substr($clientName, 0, 255),
                'binding_hash' => self::bindingHash($binding),
                'expires_at' => $now + self::PENDING_TTL,
            ]);
        });

        return $this->findByRef($ref) ?? throw new \RuntimeException('The approval request was not stored.', 1771500001);
    }

    /** Unexpired pending requests of this person's agent token. */
    public function openRequests(int $beUserUid, string $tokenId, int $now): int
    {
        return (int)$this->connection()->executeQuery(
            'SELECT COUNT(*) FROM ' . Tables::MCP_CHANGE_APPROVAL . ' WHERE be_user_uid = ? AND token_id = ? AND status = ? AND expires_at >= ?',
            [$beUserUid, strtolower($tokenId), self::PENDING, $now],
        )->fetchOne();
    }

    public static function isRef(string $ref): bool
    {
        return preg_match('/^[0-9a-f]{32}$/', $ref) === 1;
    }

    /** @return array<string, mixed>|null */
    public function findByRef(string $ref): ?array
    {
        if (!self::isRef($ref)) {
            return null;
        }
        $row = $this->connection()->select(['*'], Tables::MCP_CHANGE_APPROVAL, ['approval_ref' => $ref])->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * The person's decision, compare-and-set: only a pending, unexpired request of exactly this person whose binding is
     * the one the page showed. An approval is valid for APPROVED_TTL from now.
     */
    public function decide(int $uid, int $beUserUid, string $bindingHash, bool $approve, int $now): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . Tables::MCP_CHANGE_APPROVAL . ' SET status = ?, decided_by = ?, decided_at = ?, expires_at = ?, tstamp = ?'
            . ' WHERE uid = ? AND status = ? AND be_user_uid = ? AND binding_hash = ? AND expires_at >= ?',
            [$approve ? self::APPROVED : self::REJECTED, $beUserUid, $now, $approve ? $now + self::APPROVED_TTL : $now, $now, $uid, self::PENDING, $beUserUid, $bindingHash, $now],
            [Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT, Connection::PARAM_STR, Connection::PARAM_INT],
        ) === 1;
    }

    /** The single apply of an approval: approved and unexpired → applying. Exactly one caller gets true. */
    public function claim(int $uid, int $now): bool
    {
        return $this->connection()->executeStatement(
            'UPDATE ' . Tables::MCP_CHANGE_APPROVAL . ' SET status = ?, apply_started_at = ?, apply_attempts = apply_attempts + 1, tstamp = ?'
            . ' WHERE uid = ? AND status = ? AND expires_at >= ?',
            [self::APPLYING, $now, $now, $uid, self::APPROVED, $now],
        ) === 1;
    }

    /** A state change from exactly $from (no-op when another request moved it meanwhile). */
    public function move(int $uid, string $from, string $to, string $reason = '', string $resultStatus = '', int $now = 0): bool
    {
        $now = $now > 0 ? $now : time();
        $values = ['status' => $to, 'status_reason' => mb_substr($reason, 0, 64), 'tstamp' => $now];
        if ($resultStatus !== '') {
            $values['result_status'] = mb_substr($resultStatus, 0, 32);
            $values['result_reason'] = mb_substr($reason, 0, 64);
        }
        if ($to === self::APPLIED) {
            $values['applied_at'] = $now;
        }

        return $this->connection()->update(Tables::MCP_CHANGE_APPROVAL, $values, ['uid' => $uid, 'status' => $from]) === 1;
    }

    /** Pending or approved rows past their time become expired (read paths call this before they answer). */
    public function expireIfDue(array $row, int $now): array
    {
        if (in_array((string)$row['status'], [self::PENDING, self::APPROVED], true) && (int)$row['expires_at'] < $now) {
            $this->move((int)$row['uid'], (string)$row['status'], self::EXPIRED, 'expired', '', $now);
            $row = $this->findByRef((string)$row['approval_ref']) ?? $row;
        }

        return $row;
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(Tables::MCP_CHANGE_APPROVAL);
    }
}
