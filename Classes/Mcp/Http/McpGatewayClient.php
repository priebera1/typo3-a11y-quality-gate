<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp\Http;

use Priebera\A11yQualityGate\Mcp\McpScopes;

use Priebera\A11yQualityGate\Mcp\McpCallRunner;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Psr\Http\Client\ClientExceptionInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * HTTP client for the AQG MCP Gateway (part of the AQG API, same base URL as licence requests). Everything after the
 * pairing is authenticated with this installation's connector secret; the agent's OAuth token never reaches TYPO3.
 * Failures are ApiRequestFailedException with HTTP status and API error code, never shown as exception text.
 */
final class McpGatewayClient
{
    /** Above the Gateway's poll window (at most 8.5 s) plus network time. */
    private const POLL_TIMEOUT = 20.0;
    private const REQUEST_TIMEOUT = 10.0;
    private const INSTANCE_HEADER = 'X-AQG-Connector-Instance';
    /** Pages of 200 connected agents read for one list (2000 entries). */
    private const TOKEN_LIST_PAGES = 10;

    /** The connector process this client serves (N2), sent with every connector request of that process. */
    private string $connectorInstanceId = '';

    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly McpConnectionStore $connectionStore,
    ) {
    }

    /**
     * @param array<string, mixed> $payload licence key, installation id, sites, label and consent URL
     * @return array<string, mixed>
     */
    public function startPairing(array $payload): array
    {
        return $this->request('POST', '/mcp/pairing/start', $payload, null);
    }

    /**
     * @return array<string, mixed>
     */
    public function completePairing(string $pairingId, string $pollSecret): array
    {
        return $this->request('POST', '/mcp/pairing/' . rawurlencode($pairingId) . '/complete', ['pollSecret' => $pollSecret], null);
    }

    /**
     * Authoritative cancellation at the Gateway (poll secret): an open pairing is cancelled, and a completed pairing that
     * this installation has not activated is disconnected, so a completion still in flight can never be used.
     *
     * @return array<string, mixed> {status}
     */
    public function cancelPairing(string $pairingId, string $pollSecret): array
    {
        return $this->request('POST', '/mcp/pairing/' . rawurlencode($pairingId) . '/cancel', ['pollSecret' => $pollSecret], null);
    }

    /**
     * Activates the installation this connector secret belongs to; only then does the Gateway accept agents for it and
     * end an earlier connection of this installation.
     *
     * @return array<string, mixed>
     */
    public function activate(?string $connectorSecret = null): array
    {
        return $connectorSecret === null
            ? $this->connectorRequest('POST', '/mcp/connector/activate', [])
            : $this->request('POST', '/mcp/connector/activate', [], $connectorSecret);
    }

    /**
     * Disconnects the Gateway installation of a secret this installation did not keep (a pairing that lost a race).
     */
    public function disconnectWithSecret(string $connectorSecret, string $actorName): void
    {
        $this->request('POST', '/mcp/connector/disconnect', ['actorName' => $actorName], $connectorSecret);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return $this->connectorRequest('GET', '/mcp/connector/status');
    }

    /**
     * Names the connector process (clone safety N2): a random id per process, sent with each of its connector requests.
     * The Gateway gives one process at a time the poll lease of this installation; a second process (another
     * supervisor, or a copy of the installation) is refused with 409 connector_conflict.
     */
    public function useConnectorInstance(string $instanceId): void
    {
        if (preg_match('/^[A-Za-z0-9_-]{16,64}$/', $instanceId) !== 1) {
            throw new \InvalidArgumentException('Invalid connector instance id.', 1793400301);
        }
        $this->connectorInstanceId = $instanceId;
    }

    /** Hands the poll lease over on a clean stop, so a restarted connector need not wait for it to lapse. */
    public function releaseConnectorLease(): void
    {
        if ($this->connectorInstanceId !== '') {
            $this->connectorRequest('POST', '/mcp/connector/release', ['instanceId' => $this->connectorInstanceId]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function poll(string $version, string $host, ?int $max = null): array
    {
        // Capability negotiation: the tools this connector runs. The Gateway lists a tool only when the connector runs it
        // and the token's scopes allow it; the announcement grants nothing (McpToolExecutor checks every call again).
        // An older Gateway ignores the field.
        $payload = ['version' => $version, 'host' => $host, 'instanceId' => $this->connectorInstanceId, 'tools' => McpScopes::toolNames()];
        if ($max !== null) {
            // As many calls as the connector has free workers (Fable F-02); an older Gateway ignores it and sends up to 5.
            $payload['max'] = max(1, min(5, $max));
        }
        $response = $this->connectorRequest('POST', '/mcp/connector/poll', $payload, self::POLL_TIMEOUT);
        $calls = $response['calls'] ?? [];

        return is_array($calls) ? array_values(array_filter($calls, 'is_array')) : [];
    }

    /**
     * Confirms receipt of the calls of one poll. Only acknowledged calls may be executed here; the others may already
     * have been offered to another poll after their lease ended.
     *
     * @param list<array{id: string, claimId: string}> $claims
     * @return list<string> acknowledged call ids
     */
    public function acknowledge(array $claims): array
    {
        $acknowledged = $this->connectorRequest('POST', '/mcp/connector/calls/ack', ['calls' => $claims])['acknowledged'] ?? [];

        return is_array($acknowledged) ? array_values(array_map('strval', $acknowledged)) : [];
    }

    /**
     * The Gateway's authorization check immediately before execution: token, installation, licence, scope and deadline
     * are checked now, and the call starts at most once. Throws when the call must not run.
     *
     * @return array{principal: array<string, mixed>, remainingMs: int, tool: string}
     */
    public function beginCall(string $callId, string $claimId): array
    {
        $response = $this->connectorRequest('POST', '/mcp/connector/calls/' . rawurlencode($callId) . '/begin', ['claimId' => $claimId]);

        return [
            'principal' => is_array($response['principal'] ?? null) ? $response['principal'] : [],
            'remainingMs' => max(0, (int)($response['remainingMs'] ?? 0)),
            // The tool the Gateway authorized (empty from a Gateway before F-02).
            'tool' => is_string($response['tool'] ?? null) ? $response['tool'] : '',
        ];
    }

    /**
     * @param array<string, mixed> $result {ok: true, result} or {ok: false, error: {code, message}}
     */
    public function submitResult(string $callId, string $claimId, array $result): void
    {
        $this->connectorRequest('POST', '/mcp/connector/calls/' . rawurlencode($callId) . '/result', $result + ['claimId' => $claimId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function describeAuthorizationRequest(string $requestRef): array
    {
        $response = $this->connectorRequest('POST', '/mcp/connector/authorization-requests/describe', ['request' => $requestRef]);

        return is_array($response['request'] ?? null) ? $response['request'] : [];
    }

    /**
     * @param array<string, mixed> $decision
     * @return array<string, mixed> {redirect, status}
     */
    public function decideAuthorizationRequest(array $decision): array
    {
        return $this->connectorRequest('POST', '/mcp/connector/authorization-requests/decide', $decision);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTokens(?int $beUserUid = null): array
    {
        // Fable F-10: the Gateway pages the list (active tokens first, then ended ones) and names the next page in
        // `nextCursor`; an older Gateway answers one page without it. Bounded: at most TOKEN_LIST_PAGES pages.
        $tokens = [];
        $cursor = null;
        for ($page = 0; $page < self::TOKEN_LIST_PAGES; $page++) {
            $query = array_filter(['beUserUid' => $beUserUid, 'cursor' => $cursor], static fn (mixed $value): bool => $value !== null);
            $response = $this->connectorRequest('GET', '/mcp/connector/tokens' . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : ''));
            foreach (is_array($response['tokens'] ?? null) ? $response['tokens'] : [] as $token) {
                if (is_array($token) && is_string($token['id'] ?? null)) {
                    $tokens[$token['id']] ??= $token;
                }
            }
            $next = $response['nextCursor'] ?? null;
            if (!is_string($next) || preg_match('/^[A-Za-z0-9_-]{16,400}$/', $next) !== 1 || $next === $cursor) {
                break;
            }
            $cursor = $next;
        }

        return array_values($tokens);
    }

    public function revokeToken(string $tokenId, string $reason, string $actorName, int $beUserUid = 0): void
    {
        $this->connectorRequest('POST', '/mcp/connector/tokens/' . rawurlencode($tokenId) . '/revoke', [
            'reason' => $reason,
            'actorName' => $actorName,
            'beUserUid' => $beUserUid,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(string $actorName, ?string $connectorSecret = null): array
    {
        return $connectorSecret === null
            ? $this->connectorRequest('POST', '/mcp/connector/disconnect', ['actorName' => $actorName])
            : $this->request('POST', '/mcp/connector/disconnect', ['actorName' => $actorName], $connectorSecret);
    }

    public function isConnected(): bool
    {
        return $this->connectionStore->getConnectorSecret() !== '';
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function connectorRequest(string $method, string $path, ?array $payload = null, float $timeout = self::REQUEST_TIMEOUT): array
    {
        $secret = $this->connectionStore->getConnectorSecret();
        if ($secret === '') {
            throw new ApiRequestFailedException('This installation is not connected to the AQG MCP Gateway.', 0, null, 'mcp_not_connected');
        }

        return $this->request($method, $path, $payload, $secret, $timeout, $this->connectorInstanceId);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $payload, ?string $bearer, float $timeout = self::REQUEST_TIMEOUT, string $instanceId = ''): array
    {
        $options = [
            'headers' => ['Accept' => 'application/json'],
            'timeout' => $timeout,
            'http_errors' => false,
            'allow_redirects' => false,
        ];
        if ($bearer !== null) {
            $options['headers']['Authorization'] = 'Bearer ' . $bearer;
        }
        if ($instanceId !== '') {
            $options['headers'][self::INSTANCE_HEADER] = $instanceId;
        }
        try {
            if ($payload !== null) {
                $options['headers']['Content-Type'] = 'application/json';
                // Fable F-05: UTF-8 as is, so a non-Latin result is not six bytes per character on the wire.
                $options['body'] = json_encode($payload, McpCallRunner::JSON_FLAGS);
            }
            $response = $this->requestFactory->request(rtrim(ProSettings::resolveApiBaseUrl(), '/') . $path, $method, $options);
        } catch (ClientExceptionInterface | \JsonException $exception) {
            throw new ApiRequestFailedException('AQG MCP Gateway request failed.', 0, $exception, 'transport_error');
        }

        $status = $response->getStatusCode();
        try {
            $decoded = json_decode((string)$response->getBody(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiRequestFailedException('AQG MCP Gateway returned invalid JSON.', $status, $exception, 'invalid_json');
        }
        $decoded = is_array($decoded) ? $decoded : [];
        if ($status >= 400) {
            $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
            $code = trim((string)($error['code'] ?? ''));
            $retryAfter = $response->getHeaderLine('Retry-After');
            throw new ApiRequestFailedException(
                'AQG MCP Gateway answered with an error.',
                $status,
                null,
                $code !== '' ? $code : ($status === 404 ? 'route_not_found' : 'http_error'),
                is_array($error['details'] ?? null) ? $error['details'] : [],
                ctype_digit($retryAfter) ? (int)$retryAfter : null,
            );
        }

        return $decoded;
    }
}
