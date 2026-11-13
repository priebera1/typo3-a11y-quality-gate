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
 */
final class McpCallRunner
{
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
    ) {
    }

    /**
     * @param array<string, mixed> $call one acknowledged call of a poll: id, claimId, tool, arguments
     * @return string what happened, for the connector's verbose output: not_started, executed, answered_late, not_delivered
     */
    public function run(array $call): string
    {
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
        $audit += [
            'beUserUid' => $principal['beUserUid'],
            'beUserName' => $principal['beUserName'],
            'clientName' => $principal['clientName'],
            'tokenSuffix' => $principal['tokenSuffix'],
        ];
        $deadline = microtime(true) + $begun['remainingMs'] / 1000;
        $tokenEnds = strtotime($principal['expiresAt']);

        try {
            if ($principal['tokenId'] === '' || $principal['beUserUid'] <= 0 || $tokenEnds === false || $tokenEnds <= time()) {
                throw new McpCallRefused('token_expired', 'The agent token has expired; connect again.');
            }
            if ($begun['remainingMs'] <= 0) {
                throw new McpCallRefused('call_expired', 'The agent stopped waiting for this call.');
            }
            // The Gateway names the call it began (3.0.0 Gateway with F-02); a call handed to this process under another
            // tool name than the one the Gateway authorized is never run.
            if (($begun['tool'] ?? '') !== '' && $begun['tool'] !== $tool) {
                throw new McpCallRefused('call_mismatch', 'The call does not match what the Gateway authorized.', 'refused');
            }
            if (!$this->consentLedger->isRecorded($principal['consentRefHash'], $principal['beUserUid'], $principal['scopes'], $principal['siteIdentifiers'])) {
                // Consent decided elsewhere (a copy of this installation) or lost with a restore: never run it here.
                throw new McpCallRefused('consent_unknown', 'This installation has no record of the consent behind this agent token; connect the agent again.', 'refused');
            }
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
            return 'answered_late';
        }
        try {
            $this->gatewayClient->submitResult($callId, $claimId, $payload);
        } catch (ApiRequestFailedException) {
            // 404: the agent request already gave up or access ended meanwhile. Never replayed.
            return 'not_delivered';
        }

        return 'executed';
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
