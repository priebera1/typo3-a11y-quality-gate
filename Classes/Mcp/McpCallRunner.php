<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Command\McpConnectorCommand;
use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use TYPO3\CMS\Core\Log\LogManager;

/**
 * Runs one acknowledged agent call, exactly as the connector always has (Sol audit F1/F7, clone safety N2): the
 * Gateway's begin check decides immediately before execution whether it may run and hands out the current principal;
 * TYPO3 then requires the consent it recorded itself for that user (McpConsentLedger) and re-checks the user, site,
 * page and module rights (McpToolExecutor). A call the Gateway refuses is not executed, a call that began is never
 * offered again, and an answer after the call's deadline is not sent (the agent stopped waiting).
 *
 * Used by the connector process itself and by its worker processes (McpConnectorWorkerPool, Fable F-02), which run
 * calls in parallel: each worker holds the same connector instance id and runs this same code, so nothing a worker
 * does is beyond what the single-process connector did.
 *
 * Attested execution (V4/V5, AQG 3.0.0): a call runs only with the AQG licence API's call attestation (McpAttestation),
 * verified against the keys this installation pinned at pairing. The principal the tool runs as and the arguments it
 * runs with come from the attestation — never from the Gateway's begin answer or relayed arguments — so a compromised
 * Gateway can neither run a call for a consent it did not get from this installation nor alter a consented call.
 * Missing, invalid, expired, replayed, foreign or mismatching attestations run nothing and are audited (V5). There is
 * no unattested mode: a connection paired by an earlier build is refused until it is paired again.
 */
final class McpCallRunner
{
    /** The longest call window this process accepts from the Gateway (the connector's supervisor assumes the same). */
    private const MAX_CALL_WINDOW_MS = 10000;

    /**
     * Fable F-05: what the Gateway relays (MCP_MAX_RESULT_BYTES, 256 KiB, measured on its own JSON) less a margin for
     * the envelope. A larger answer is replaced by `result_too_large` here, so the agent is told to ask for a smaller
     * page instead of waiting for a timeout after the Gateway refused the body.
     */
    public const MAX_RESULT_BYTES = 255 * 1024;

    /** UTF-8 as is (a non-Latin result is not six bytes per character), and never a failed encoding. */
    public const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

    public function __construct(
        private readonly McpGatewayClient $gatewayClient,
        private readonly McpToolExecutor $toolExecutor,
        private readonly McpAuditRepository $auditRepository,
        private readonly McpConsentLedger $consentLedger,
        private readonly LogManager $logManager,
        private readonly ?McpDeferredWork $deferredWork = null,
        private readonly ?McpAttestation $attestation = null,
        private readonly ?McpConnectionStore $connectionStore = null,
    ) {
    }

    /**
     * @param array<string, mixed> $call one acknowledged call of a poll: id, claimId, tool, arguments
     * @return string what happened, for the connector's verbose output: not_started, executed, answered_late, not_delivered
     */
    public function run(array $call): string
    {
        // Work left by an earlier call never runs as part of this one.
        $this->deferredWork?->clear();
        $callId = (string)($call['id'] ?? '');
        $claimId = (string)($call['claimId'] ?? '');
        $tool = (string)($call['tool'] ?? '');
        $arguments = is_array($call['arguments'] ?? null) ? $call['arguments'] : [];
        $audit = [
            'siteIdentifier' => (string)($arguments['siteIdentifier'] ?? ''),
            'target' => $this->auditTarget($arguments),
        ];

        // The Gateway's authorization check immediately before execution; nothing runs without it.
        try {
            $begun = $this->gatewayClient->beginCall($callId, $claimId);
        } catch (ApiRequestFailedException $exception) {
            $this->auditRepository->record($tool, 'refused', 'connector', $audit + ['reason' => mb_substr($exception->apiErrorCode ?: 'begin_refused', 0, 64)]);
            return 'not_started';
        }
        $principal = $this->normalizePrincipal($begun['principal']);
        // Recorded for the audit as the Gateway named it; replaced below by what the licence API attested.
        $audit += [
            'beUserUid' => $principal['beUserUid'],
            'beUserName' => $principal['beUserName'],
            'clientName' => $principal['clientName'],
            'tokenSuffix' => $principal['tokenSuffix'],
        ];
        // G-07: never later than the connector assumes (McpConnectorCommand::callDeadline() caps at 10 s), so deferred work
        // bounded by this deadline + CALL_GRACE ends before the pool replaces the worker, whatever the Gateway sends.
        $deadline = microtime(true) + min($begun['remainingMs'], self::MAX_CALL_WINDOW_MS) / 1000;
        $tokenEnds = strtotime($principal['expiresAt']);

        try {
            // The Gateway names the call it began (3.0.0 Gateway with F-02); a call handed to this process under another
            // tool name than the one the Gateway authorized is never run.
            if (($begun['tool'] ?? '') !== '' && $begun['tool'] !== $tool) {
                throw new McpCallRefused('call_mismatch', 'The call does not match what the Gateway authorized.', 'refused');
            }
            // V4/V5: nothing runs without a valid attestation; afterwards the attested principal and arguments are used.
            if ($this->attestation === null || $this->connectionStore === null) {
                throw new McpCallRefused('attestation_unavailable', 'This connector cannot verify call attestations; nothing was run.', 'refused');
            }
            $verified = $this->attestation->verifyCall($begun['attestation'] ?? null, $call, $begun['principal'], $this->connectionStore->attestationTrust());
            $principal = $verified['principal'];
            $arguments = $verified['arguments'];
            $audit['beUserUid'] = $principal['beUserUid'];
            $tokenEnds = strtotime($principal['expiresAt']);
            if ($principal['tokenId'] === '' || $principal['beUserUid'] <= 0 || $tokenEnds === false || $tokenEnds <= time()) {
                throw new McpCallRefused('token_expired', 'The agent token has expired; connect again.');
            }
            if ($begun['remainingMs'] <= 0) {
                throw new McpCallRefused('call_expired', 'The agent stopped waiting for this call.');
            }
            // The consent ledger (clone safety N2) was checked by the attestation: this installation recorded and signed
            // exactly this consent for this user, client and grant, bound to this token, not revoked here.
            // Finding 2: work this call leaves runs only on a core continuation of exactly this verified call
            // (McpDeferredAuthorization), so the job keeps the attestation and its claims, never the Gateway's values.
            $this->deferredWork?->begin([
                'callId' => $callId,
                'attestation' => (string)($begun['attestation'] ?? ''),
                'tool' => $tool,
                'siteIdentifier' => (string)($arguments['siteIdentifier'] ?? ''),
                'deadline' => $deadline,
                'claims' => $verified['claims'] ?? [],
                'principal' => ['beUserName' => $principal['beUserName'], 'clientName' => $principal['clientName'], 'tokenSuffix' => $principal['tokenSuffix']],
            ]);
            $result = $this->toolExecutor->execute($tool, $arguments, $principal);
            $payload = ['ok' => true, 'result' => $result];
            $started = $tool === 'aqg_start_scan' && ($result['status'] ?? '') === 'submitted';
            if (!$started && self::encodedSize($payload) > self::MAX_RESULT_BYTES) {
                // Nothing of the oversized result leaves this installation. A started scan is reported as started however
                // large its description: its result is a single scan, and the agent must learn the job id.
                throw new McpCallRefused('result_too_large', 'The result is too large to return; request a smaller page (pageSize) or narrow the filter.', 'refused');
            }
            $this->auditRepository->record($tool, $started ? 'scan_started' : 'allowed', 'connector', $audit);
        } catch (McpCallRefused $refused) {
            $payload = ['ok' => false, 'error' => array_filter([
                'code' => $refused->reason,
                'message' => $refused->getMessage(),
                'retryAfter' => $refused->retryAfter,
            ], static fn (mixed $value): bool => $value !== null)];
            $this->auditRepository->record($tool, $refused->auditResult, 'connector', $audit + ['reason' => $refused->reason]);
        } catch (\Throwable $exception) {
            // Exception text never reaches the agent; it is logged here.
            $this->logManager->getLogger(McpConnectorCommand::class)->error('AQG MCP tool call failed', [
                'tool' => $tool,
                'exception' => $exception::class,
                'code' => $exception->getCode(),
                'message' => McpConnectorCommand::loggableMessage($exception->getMessage()),
            ]);
            $payload = ['ok' => false, 'error' => ['code' => 'installation_error', 'message' => 'The TYPO3 installation could not complete the call.']];
            $this->auditRepository->record($tool, 'refused', 'connector', $audit + ['reason' => 'installation_error']);
        }

        if (microtime(true) > $deadline) {
            // The agent stopped waiting; the Gateway would discard the answer. Never replayed.
            $this->deferredWork?->clear();
            return 'answered_late';
        }
        try {
            $this->gatewayClient->submitResult($callId, $claimId, $payload);
        } catch (ApiRequestFailedException) {
            // 404: the agent request already gave up or access ended meanwhile. Never replayed.
            // The Gateway refused the answer (agent gone, token revoked or access ended): nothing more runs for it.
            $this->deferredWork?->clear();
            return 'not_delivered';
        }
        $this->runDeferred($tool, ($payload['ok'] ?? false) === true, $deadline);

        return 'executed';
    }

    /**
     * The work the executed call left (AI suggestions of items it created), after its answer: an answer the agent no
     * longer receives does not undo items that exist, and their owner sees them in AI Fixes either way. A refused or
     * failed call leaves nothing to run.
     */
    private function runDeferred(string $tool, bool $executed, float $deadline): void
    {
        if ($this->deferredWork === null) {
            return;
        }
        // Too close to the deadline: the worker could overrun its grace (McpDeferredWork::MAX_JOBS). The items stay
        // Preparing; the agent's next call continues them.
        if (!$executed || microtime(true) > $deadline - McpDeferredWork::START_BEFORE_DEADLINE_SECONDS) {
            $this->deferredWork->clear();
            return;
        }
        $this->deferredWork->run(function (\Throwable $exception) use ($tool): void {
            $this->logManager->getLogger(McpConnectorCommand::class)->error('AQG MCP deferred work failed', [
                'tool' => $tool,
                'exception' => $exception::class,
                'code' => $exception->getCode(),
                'message' => McpConnectorCommand::loggableMessage($exception->getMessage()),
            ]);
        });
    }

    /**
     * Bytes of the answer as the Gateway client sends it.
     *
     * @param array<string, mixed> $payload
     */
    public static function encodedSize(array $payload): int
    {
        try {
            return strlen(json_encode($payload, self::JSON_FLAGS));
        } catch (\JsonException) {
            return PHP_INT_MAX;
        }
    }

    /**
     * @param array<string, mixed> $principal
     * @return array{tokenId: string, tokenSuffix: string, clientName: string, beUserUid: int, beUserName: string, scopes: list<string>, siteIdentifiers: list<string>, expiresAt: string, consentRefHash: string}
     */
    private function normalizePrincipal(array $principal): array
    {
        $strings = static fn (mixed $values): array => is_array($values)
            ? array_values(array_filter(array_map('strval', $values), static fn (string $value): bool => $value !== ''))
            : [];

        return [
            'tokenId' => (string)($principal['tokenId'] ?? ''),
            'tokenSuffix' => mb_substr((string)($principal['tokenSuffix'] ?? ''), 0, 8),
            'clientName' => mb_substr((string)($principal['clientName'] ?? ''), 0, 80),
            'beUserUid' => (int)($principal['beUserUid'] ?? 0),
            'beUserName' => mb_substr((string)($principal['beUserName'] ?? ''), 0, 120),
            'scopes' => McpScopes::normalize($strings($principal['scopes'] ?? [])),
            'siteIdentifiers' => $strings($principal['siteIdentifiers'] ?? []),
            'expiresAt' => (string)($principal['expiresAt'] ?? ''),
            'consentRefHash' => strtolower((string)($principal['consentRefHash'] ?? '')),
        ];
    }

    /**
     * Target ids only (page, job, content finding), never content (a find_pages query is not recorded).
     *
     * @param array<string, mixed> $arguments
     */
    private function auditTarget(array $arguments): string
    {
        $parts = [];
        if ((int)($arguments['pageUid'] ?? 0) > 0) {
            $parts[] = 'page ' . (int)$arguments['pageUid'];
        }
        if (is_string($arguments['jobId'] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $arguments['jobId']) === 1) {
            $parts[] = 'job ' . substr($arguments['jobId'], 0, 8);
        }
        if (is_int($arguments['findingId'] ?? null) && $arguments['findingId'] > 0) {
            $parts[] = 'finding ' . $arguments['findingId'];
        }

        return implode(' · ', $parts);
    }
}
