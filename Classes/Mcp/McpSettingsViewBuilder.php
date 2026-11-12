<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Utility\PaginationUtility;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * View data of Settings › Agent access (MCP) (atlas 13 · A, B, D, F, G). Gateway state is read live with the connector
 * credential; when the Gateway does not answer the tab says so and still shows local settings and the audit trail.
 * Token values are never part of it — only identifiers (…a91c).
 */
final class McpSettingsViewBuilder
{
    public const CONNECTOR_COMMAND = 'vendor/bin/typo3 a11y:mcp:connector';
    public const RESET_LOCAL_COMMAND = 'vendor/bin/typo3 a11y:mcp:reset-local --confirm';
    public const TSCONFIG_LINE = 'options.a11y_quality_gate.mcp.connect = 1';

    public function __construct(
        private readonly McpConnectionStore $connectionStore,
        private readonly McpGatewayClient $gatewayClient,
        private readonly McpSiteSettingsRepository $siteSettingsRepository,
        private readonly McpAuditRepository $auditRepository,
        private readonly SiteFinder $siteFinder,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    /**
     * How long after pairing a connection without any connector heartbeat yet reads as "starting" rather than "offline".
     * Right after the code is confirmed the connector process has usually not polled yet (a supervisor restarts it with
     * a back-off, a container needs its start time); before 3.0.1 the tab reloaded into the red "Connector offline"
     * alert at exactly that moment, although nothing had failed.
     */
    public const STARTING_GRACE_SECONDS = 900;

    /** Seconds between the tab's own state checks while it waits for the first heartbeat (settings-mcp.js). */
    public const STARTING_POLL_SECONDS = 4;

    /**
     * The state of the stored connection as the Gateway reports it, without tokens, sites or the audit trail: the
     * Settings tab and its "starting" poll (McpSettingsController::connectionStateAction) decide it with this one rule.
     *
     * @param array<string, mixed>|null $connection the stored connection; read here when null
     * @return array{state: string, gateway: array<string, mixed>|null}
     */
    public function resolveState(bool $eligible, ?array $connection = null, ?int $now = null): array
    {
        $connection ??= $this->connectionStore->get();
        $now ??= time();
        $gateway = null;
        $gatewayError = '';
        $unusable = $connection['unusableReason'] !== '';
        $connected = $connection['status'] === 'connected';
        $withinGrace = (int)$connection['pairedAt'] > 0 && $now - (int)$connection['pairedAt'] < self::STARTING_GRACE_SECONDS;
        // Not activated yet: the Gateway answers every connector call but `activate` with 401 until the connector (or
        // the tab's next poll) activates it. That 401 is the expected state of a fresh pairing, not a revocation.
        $awaitingActivation = $connected && (bool)($connection['activationPending'] ?? false) && $withinGrace;
        if ($eligible && $connected && !$unusable && !$awaitingActivation) {
            try {
                $status = $this->gatewayClient->status();
                $gateway = is_array($status['installation'] ?? null) ? $status['installation'] : [];
                $gateway['quotas'] = is_array($status['quotas'] ?? null) ? $status['quotas'] : [];
                $gateway['lastHeartbeat'] = self::date((string)($gateway['lastHeartbeatAt'] ?? ''));
            } catch (ApiRequestFailedException $exception) {
                $gatewayError = in_array($exception->httpStatus, [401, 403], true) ? 'revoked' : 'unreachable';
            }
        }
        $neverHeard = $gateway !== null && ($gateway['lastHeartbeatAt'] ?? null) === null;

        $state = match (true) {
            !$eligible => 'locked',
            // Stored, but not usable on this host (a copy, another host's key, a new identity): no Gateway call (N2).
            $unusable => 'unusable',
            $connection['status'] === 'pairing' => 'pairing',
            $awaitingActivation => 'starting',
            $connected && $gatewayError === 'revoked' => 'revoked',
            $connected && $gatewayError !== '' => 'unreachable',
            $connected && !($gateway['licenceActive'] ?? true) => 'suspended',
            // Paired, but the connector has not sent its first heartbeat yet: waiting, not failing (bounded by the grace).
            $connected && !($gateway['connectorOnline'] ?? false) && $neverHeard && $withinGrace => 'starting',
            $connected && !($gateway['connectorOnline'] ?? false) => 'offline',
            $connected => 'connected',
            $connection['status'] === 'disconnected' => 'disconnected',
            default => 'not_connected',
        };

        return ['state' => $state, 'gateway' => $gateway];
    }

    /**
     * @param array<string, string> $returnParameters
     * @return array<string, mixed>
     */
    public function build(bool $eligible, string $plan, array $returnParameters, string $auditFilter = '', int $auditPage = 1): array
    {
        $connection = $this->connectionStore->get();
        ['state' => $state, 'gateway' => $gateway] = $this->resolveState($eligible, $connection);
        $tokens = [];
        if ($gateway !== null) {
            try {
                $tokens = $this->describeTokens($this->gatewayClient->listTokens());
            } catch (ApiRequestFailedException) {
                // The status call just answered; a failing token list leaves the list empty and keeps the state.
            }
        }
        // Showing the trail applies its retention too (90 days), also on installations that run no connector now.
        $this->auditRepository->prune();
        $auditPageData = $this->auditRepository->findPage($auditPage, McpAuditRepository::PAGE_SIZE, $auditFilter);

        $activeTokens = array_filter($tokens, static fn (array $token): bool => in_array($token['status'], ['active', 'expiring_soon'], true));
        $sites = $this->describeSites();

        return [
            'state' => $state,
            'plan' => $plan,
            'connection' => $connection,
            'gateway' => $gateway,
            'pairingExpiresAt' => $connection['expiresAt'] > 0 ? gmdate('Y-m-d\TH:i:s\Z', $connection['expiresAt']) : '',
            'tokens' => $tokens,
            'activeTokenCount' => count($activeTokens),
            'sites' => $sites,
            // Header summary of the site access card ("2 of 5 sites open to agents").
            'siteCount' => count($sites),
            'enabledSiteCount' => count(array_filter($sites, static fn (array $site): bool => (bool)$site['enabled'])),
            'scopeCatalogue' => $this->scopeCatalogue(),
            'audit' => $this->describeAudit($auditPageData['rows']),
            'auditPagination' => $this->auditPagination($auditPageData, $returnParameters, $auditFilter),
            'auditFilter' => $auditFilter,
            'auditRetentionDays' => $this->auditRepository->retentionDays(),
            'connectorCommand' => self::CONNECTOR_COMMAND,
            'resetLocalCommand' => self::RESET_LOCAL_COMMAND,
            'tsconfigLine' => self::TSCONFIG_LINE,
            'returnParameters' => $returnParameters,
            'urls' => [
                'connect' => $this->url('web_a11y.settingsMcpConnect'),
                'cancelPairing' => $this->url('web_a11y.settingsMcpCancelPairing'),
                'pairingStatus' => (string)$this->uriBuilder->buildUriFromRoute('ajax_a11y_mcp_pairing_status'),
                'connectionState' => (string)$this->uriBuilder->buildUriFromRoute('ajax_a11y_mcp_connection_state'),
                'startingPollSeconds' => (string)self::STARTING_POLL_SECONDS,
                'saveSites' => $this->url('web_a11y.settingsMcpSaveSites'),
                'revoke' => $this->url('web_a11y.settingsMcpRevoke'),
                'disconnect' => $this->url('web_a11y.settingsMcpDisconnect'),
                'auditAll' => $this->url('web_a11y.settings', $returnParameters + ['tab' => 'mcp']) . '#aqg-mcp-audit',
                'auditRefused' => $this->url('web_a11y.settings', $returnParameters + ['tab' => 'mcp', 'mcpAudit' => 'refused']) . '#aqg-mcp-audit',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $tokens
     * @return list<array<string, mixed>>
     */
    public function describeTokens(array $tokens): array
    {
        return array_map(static function (array $token): array {
            $scopes = array_values(array_filter(array_map('strval', is_array($token['scopes'] ?? null) ? $token['scopes'] : [])));
            // "Expiring soon" follows the granted lifetime (McpTokenStatus), not a fixed 7-day window.
            $status = McpTokenStatus::resolve((string)($token['status'] ?? ''), (string)($token['createdAt'] ?? ''), (string)($token['expiresAt'] ?? ''));

            return [
                'id' => (string)($token['id'] ?? ''),
                'tokenLabel' => 'mcp_…' . (string)($token['tokenSuffix'] ?? ''),
                'clientName' => (string)($token['clientName'] ?? ''),
                'clientId' => (string)($token['clientId'] ?? ''),
                'beUserUid' => (int)($token['beUserUid'] ?? 0),
                'beUserName' => (string)($token['beUserName'] ?? ''),
                'scopes' => $scopes,
                // Presentation only: the capability's name in the backend language and its risk, next to the scope code.
                'scopeItems' => array_map(static fn (string $scope): array => [
                    'scope' => $scope,
                    'title' => McpScopes::title($scope),
                    'risk' => McpScopes::riskOf($scope),
                ], $scopes),
                'siteList' => array_values(array_map('strval', is_array($token['siteIdentifiers'] ?? null) ? $token['siteIdentifiers'] : [])),
                'sites' => implode(', ', array_map('strval', is_array($token['siteIdentifiers'] ?? null) ? $token['siteIdentifiers'] : [])),
                'status' => in_array($status, ['active', 'expiring_soon', 'expired', 'revoked'], true) ? $status : 'expired',
                'revocable' => in_array($status, ['active', 'expiring_soon'], true),
                'expiresAt' => self::date((string)($token['expiresAt'] ?? '')),
                'lastUsedAt' => self::date((string)($token['lastUsedAt'] ?? '')),
                'revokedAt' => self::date((string)($token['revokedAt'] ?? '')),
                'revokedReason' => (string)($token['revokedReason'] ?? ''),
                'revokedByName' => (string)($token['revokedByName'] ?? ''),
            ];
        }, $tokens);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function describeSites(): array
    {
        $sites = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $settings = $this->siteSettingsRepository->find($site->getIdentifier());
            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'base' => (string)$site->getBase(),
                'enabled' => $settings['enabled'],
                'capabilities' => array_map(static fn (string $scope): array => [
                    'scope' => $scope,
                    // The capability's name in the backend language; the scope code stays beside it.
                    'title' => McpScopes::title($scope),
                    'labelKey' => McpScopes::labelKey($scope),
                    'risk' => McpScopes::riskOf($scope),
                    'checked' => in_array($scope, $settings['capabilities'], true),
                    'fieldId' => 'aqg-mcp-cap-' . preg_replace('/[^a-z0-9]+/i', '-', $site->getIdentifier() . '-' . $scope),
                ], McpScopes::GRANTABLE),
                'fieldId' => 'aqg-mcp-site-' . preg_replace('/[^a-z0-9]+/i', '-', $site->getIdentifier()),
            ];
        }

        return $sites;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scopeCatalogue(): array
    {
        $catalogue = [];
        foreach ([...McpScopes::GRANTABLE, ...McpScopes::NOT_AVAILABLE] as $scope) {
            $catalogue[] = [
                'scope' => $scope,
                'title' => McpScopes::title($scope),
                'description' => McpScopes::description($scope),
                'risk' => McpScopes::riskOf($scope),
                'available' => in_array($scope, McpScopes::GRANTABLE, true),
            ];
        }

        return $catalogue;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function describeAudit(array $rows): array
    {
        return array_map(static fn (array $row): array => [
            'time' => gmdate('Y-m-d H:i:s', (int)$row['crdate']) . ' UTC',
            'clientName' => (string)$row['client_name'],
            'beUserName' => (string)$row['be_user_name'],
            'beUserUid' => (int)$row['be_user_uid'],
            'site' => (string)$row['site_identifier'],
            'action' => (string)$row['action'],
            'target' => (string)$row['target'],
            'result' => (string)$row['result'],
            'reason' => (string)$row['reason'],
            'source' => (string)$row['source'],
        ], $rows);
    }

    /**
     * Previous/next and page links of the trail. Every link keeps the filter and the module's return parameters, and
     * lands on the trail (#aqg-mcp-audit); page 1 carries no page parameter.
     *
     * @param array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, perPage: int} $page
     * @param array<string, string> $returnParameters
     * @return array<string, mixed>
     */
    private function auditPagination(array $page, array $returnParameters, string $auditFilter): array
    {
        $pagination = PaginationUtility::buildPagination($page['total'], $page['page'], $page['perPage']);
        $urlFor = function (int $number) use ($returnParameters, $auditFilter): string {
            $parameters = $returnParameters + ['tab' => 'mcp'];
            if ($auditFilter !== '') {
                $parameters['mcpAudit'] = $auditFilter;
            }
            if ($number > 1) {
                $parameters['mcpAuditPage'] = (string)$number;
            }

            return $this->url('web_a11y.settings', $parameters) . '#aqg-mcp-audit';
        };
        // URLs only for the page links buildPaginationItems() shows (all of up to five pages, else first, last and the
        // neighbours of the current one): a long trail (retention up to 3650 days, or none while misconfigured) must not
        // build one backend route URL per page on every view.
        $current = $pagination['currentPage'];
        $total = $pagination['totalPages'];
        $shown = $total <= 5 ? range(1, $total) : [1, $total, $current - 1, $current, $current + 1];
        $pageUrls = [];
        foreach (array_unique($shown) as $number) {
            if ($number >= 1 && $number <= $total) {
                $pageUrls[$number] = $urlFor($number);
            }
        }
        $first = $page['total'] === 0 ? 0 : $pagination['offset'] + 1;

        return $pagination + [
            'perPage' => $page['perPage'],
            'firstItem' => $first,
            'lastItem' => $page['total'] === 0 ? 0 : $pagination['offset'] + count($page['rows']),
            'previousUrl' => $pagination['hasPrevious'] ? $urlFor($pagination['currentPage'] - 1) : '',
            'nextUrl' => $pagination['hasNext'] ? $urlFor($pagination['currentPage'] + 1) : '',
            'items' => PaginationUtility::buildPaginationItems($pagination['currentPage'], $pagination['totalPages'], $pageUrls),
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function url(string $route, array $parameters = []): string
    {
        return (string)$this->uriBuilder->buildUriFromRoute($route, $parameters);
    }

    private static function date(string $iso): string
    {
        $timestamp = $iso !== '' ? strtotime($iso) : false;

        return $timestamp !== false ? gmdate('Y-m-d H:i', $timestamp) . ' UTC' : '';
    }
}
