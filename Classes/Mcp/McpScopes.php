<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Utility\BackendLabelUtility;

/**
 * Agent access (MCP) capability catalogue (v1 plus content:read of Accessibility Intelligence), the same list the AQG MCP Gateway grants (aqg-api services/mcp/scopes.ts).
 *
 * The effective right of an agent call is: consented scopes (the token) ∩ the site's capabilities here (now) ∩ the
 * TYPO3 user's own permissions (at call time). Shown but not grantable in v1: scans:run-site (Agency, needs the
 * administrator approval flow), suggestions:prepare (remediation queue) and changes:apply (locked pending a security
 * audit). They have no tool, so an agent can never use them.
 */
final class McpScopes
{
    public const FINDINGS_READ = 'findings:read';
    public const HISTORY_READ = 'history:read';
    public const SCANS_RUN_PAGE = 'scans:run-page';
    /**
     * Accessibility Intelligence: local TYPO3 content findings (records, fields, snippets), which can show content that
     * is not published yet (hidden or scheduled records and pages, live workspace only). Never ticked in advance at
     * consent ({@see requiresExplicitConsent()}) and never part of a request without scopes at the Gateway.
     */
    public const CONTENT_READ = 'content:read';

    /** @var list<string> */
    public const GRANTABLE = [self::FINDINGS_READ, self::HISTORY_READ, self::SCANS_RUN_PAGE, self::CONTENT_READ];

    /**
     * @var list<string> scopes the consent page shows unticked: the user must tick them deliberately. Since the public
     * hardening (AQG 3.0.0, Fable P1-5) only reading frontend findings and scan history is preselected: starting scans
     * has an external effect and a quota, and content:read can show unpublished content.
     */
    public const EXPLICIT_CONSENT = [self::SCANS_RUN_PAGE, self::CONTENT_READ];

    /** @var list<string> shown in the catalogue as "not in this version" / locked, never grantable */
    public const NOT_AVAILABLE = ['scans:run-site', 'suggestions:prepare', 'changes:apply'];

    public const RISK_READ = 'read';
    public const RISK_ACTION = 'act';
    public const RISK_LOCKED = 'locked';

    /** @var array<string, list<string>> tool → scopes, any of which allows it; [] = any valid token */
    public const TOOL_SCOPES = [
        'aqg_whoami' => [],
        'aqg_list_sites' => [self::FINDINGS_READ, self::HISTORY_READ, self::SCANS_RUN_PAGE],
        'aqg_list_scans' => [self::HISTORY_READ],
        'aqg_get_scan_results' => [self::FINDINGS_READ],
        'aqg_start_scan' => [self::SCANS_RUN_PAGE],
        'aqg_get_scan_status' => [self::HISTORY_READ, self::SCANS_RUN_PAGE],
        // Accessibility Intelligence, read-only (aqg-api services/mcp/scopes.ts, same table).
        // Page titles and paths: frontend findings already name affected pages (findings:read); unpublished pages only
        // with content:read (McpIntelligenceTools::findPages()). history:read / scans:run-page alone never search pages.
        'aqg_find_pages' => [self::FINDINGS_READ, self::CONTENT_READ],
        'aqg_list_content_findings' => [self::CONTENT_READ],
        'aqg_get_finding_context' => [self::CONTENT_READ],
        'aqg_get_remediation_guidance' => [self::CONTENT_READ, self::FINDINGS_READ],
    ];

    /** @var array<string, array{0: string, 1: string}> English title and description per scope (labels: mcp.scope.<labelKey>.title|text) */
    public const LABELS = [
        self::FINDINGS_READ => ['Read open accessibility issues', 'Issue types, occurrences and affected pages of frontend scans, only on pages the user can see in TYPO3.'],
        self::HISTORY_READ => ['Read scan history', 'Earlier frontend scans of a site, their status and progress.'],
        self::CONTENT_READ => ['Read content accessibility issues', 'AQG\'s local checks of TYPO3 content elements and fields, with short snippets and the record they come from, only for records the user can read. This can include content that is not published yet (hidden or scheduled), never workspace drafts. Separate from frontend scan results.'],
        self::SCANS_RUN_PAGE => ['Scan a single page', 'Starts a frontend scan of one page within a daily quota. The user needs Scan now and edit access to the page.'],
        'scans:run-site' => ['Scan a whole site', 'Agency, later: needs an administrator\'s approval of each request.'],
        'suggestions:prepare' => ['Prepare fix suggestions', 'Later: AI suggestions that a person reviews in the remediation queue.'],
        'changes:apply' => ['Apply changes to content', 'Locked: requires an independent security audit and explicit human approval of each change.'],
    ];

    private function __construct()
    {
    }

    public static function title(string $scope): string
    {
        return BackendLabelUtility::translate('mcp.scope.' . self::labelKey($scope) . '.title', self::LABELS[$scope][0] ?? $scope);
    }

    public static function description(string $scope): string
    {
        return BackendLabelUtility::translate('mcp.scope.' . self::labelKey($scope) . '.text', self::LABELS[$scope][1] ?? '');
    }

    public static function riskOf(string $scope): string
    {
        return match ($scope) {
            self::FINDINGS_READ, self::HISTORY_READ, self::CONTENT_READ => self::RISK_READ,
            self::SCANS_RUN_PAGE, 'scans:run-site', 'suggestions:prepare' => self::RISK_ACTION,
            default => self::RISK_LOCKED,
        };
    }

    /**
     * @param list<string> $values
     * @return list<string> known grantable scopes, in catalogue order, without duplicates
     */
    public static function normalize(array $values): array
    {
        return array_values(array_filter(
            self::GRANTABLE,
            static fn (string $scope): bool => in_array($scope, $values, true)
        ));
    }

    /** @return list<string> every tool this extension runs, announced to the Gateway on each poll */
    public static function toolNames(): array
    {
        return array_keys(self::TOOL_SCOPES);
    }

    /** Whether the consent page shows this scope unticked, so that only a deliberate tick grants it. */
    public static function requiresExplicitConsent(string $scope): bool
    {
        return in_array($scope, self::EXPLICIT_CONSENT, true);
    }

    /**
     * @param list<string> $scopes
     */
    public static function toolAllowed(string $tool, array $scopes): bool
    {
        if (!array_key_exists($tool, self::TOOL_SCOPES)) {
            return false;
        }
        $required = self::TOOL_SCOPES[$tool];

        return $required === [] || array_intersect($required, $scopes) !== [];
    }

    /**
     * Whether the Gateway keeps this site identifier in a token (aqg-api services/mcp/oauth.ts drops any other). A site
     * it would drop is never offered: the token's sites would then differ from the grant the consent ledger recorded.
     */
    public static function isGatewaySiteIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $identifier) === 1;
    }

    /** "findings:read" → "findingsRead", the suffix of its label keys. */
    public static function labelKey(string $scope): string
    {
        return lcfirst(str_replace(' ', '', ucwords(str_replace([':', '-'], ' ', $scope))));
    }
}
