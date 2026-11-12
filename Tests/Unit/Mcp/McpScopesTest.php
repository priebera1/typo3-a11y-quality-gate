<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Mcp;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\McpSettingsController;
use Priebera\A11yQualityGate\Mcp\McpScopes;

/**
 * Agent access (MCP) v1: the capability catalogue the connector enforces must match the Gateway's
 * (aqg-api services/mcp/scopes.ts), and only PRO/Agency licences may connect.
 */
final class McpScopesTest extends TestCase
{
    #[Test]
    public function theV1ScopesAndContentReadAreGrantable(): void
    {
        self::assertSame(['findings:read', 'history:read', 'scans:run-page', 'content:read'], McpScopes::GRANTABLE);
        // Only reading is ticked in advance (public hardening, Fable P1-5): scans (an external effect with a quota) and
        // content:read (possibly unpublished content) need a deliberate tick.
        self::assertSame(['scans:run-page', 'content:read'], McpScopes::EXPLICIT_CONSENT);
        self::assertTrue(McpScopes::requiresExplicitConsent('content:read'));
        self::assertTrue(McpScopes::requiresExplicitConsent('scans:run-page'));
        self::assertFalse(McpScopes::requiresExplicitConsent('findings:read'));
        self::assertFalse(McpScopes::requiresExplicitConsent('history:read'));
        self::assertSame(
            ['findings:read', 'scans:run-page'],
            McpScopes::normalize(['scans:run-page', 'changes:apply', 'suggestions:prepare', 'findings:read', 'findings:read', '*']),
            'Unknown and locked scopes are dropped, order follows the catalogue, duplicates collapse.'
        );
    }

    #[Test]
    public function onlySiteIdentifiersTheGatewayKeepsAreOffered(): void
    {
        self::assertTrue(McpScopes::isGatewaySiteIdentifier('main'));
        self::assertTrue(McpScopes::isGatewaySiteIdentifier('client-a_2.de'));
        self::assertTrue(McpScopes::isGatewaySiteIdentifier(str_repeat('a', 64)));
        self::assertFalse(McpScopes::isGatewaySiteIdentifier(str_repeat('a', 65)));
        self::assertFalse(McpScopes::isGatewaySiteIdentifier(' main'));
        self::assertFalse(McpScopes::isGatewaySiteIdentifier('müller'));
        self::assertFalse(McpScopes::isGatewaySiteIdentifier(''));
    }

    #[Test]
    public function toolsFollowTheirScopes(): void
    {
        self::assertTrue(McpScopes::toolAllowed('aqg_whoami', []));
        self::assertTrue(McpScopes::toolAllowed('aqg_get_scan_results', ['findings:read']));
        self::assertFalse(McpScopes::toolAllowed('aqg_get_scan_results', ['history:read']));
        self::assertFalse(McpScopes::toolAllowed('aqg_start_scan', ['findings:read', 'history:read']));
        self::assertTrue(McpScopes::toolAllowed('aqg_get_scan_status', ['scans:run-page']));
        self::assertFalse(McpScopes::toolAllowed('aqg_apply_change', ['findings:read', 'history:read', 'scans:run-page']), 'No write tool exists.');
        self::assertSame(
            ['aqg_whoami', 'aqg_list_sites', 'aqg_list_scans', 'aqg_get_scan_results', 'aqg_start_scan', 'aqg_get_scan_status',
                'aqg_find_pages', 'aqg_list_content_findings', 'aqg_get_finding_context', 'aqg_get_remediation_guidance'],
            array_keys(McpScopes::TOOL_SCOPES)
        );
        // The connector announces exactly the tools it runs (capability negotiation with the Gateway).
        self::assertSame(array_keys(McpScopes::TOOL_SCOPES), McpScopes::toolNames());
    }

    #[Test]
    public function contentToolsNeedContentReadAndFrontendScopesDoNotGrantThem(): void
    {
        $v1 = ['findings:read', 'history:read', 'scans:run-page'];
        self::assertFalse(McpScopes::toolAllowed('aqg_list_content_findings', $v1), 'An old token never reaches content findings.');
        self::assertFalse(McpScopes::toolAllowed('aqg_get_finding_context', $v1));
        self::assertTrue(McpScopes::toolAllowed('aqg_list_content_findings', ['content:read']));
        self::assertTrue(McpScopes::toolAllowed('aqg_get_finding_context', ['content:read']));
        self::assertFalse(McpScopes::toolAllowed('aqg_find_pages', ['history:read', 'scans:run-page']), 'no page search without findings:read or content:read');
        self::assertTrue(McpScopes::toolAllowed('aqg_find_pages', ['findings:read']));
        self::assertTrue(McpScopes::toolAllowed('aqg_find_pages', ['content:read']));
        self::assertTrue(McpScopes::toolAllowed('aqg_get_remediation_guidance', ['findings:read']));
        self::assertTrue(McpScopes::toolAllowed('aqg_get_remediation_guidance', ['content:read']));
        self::assertFalse(McpScopes::toolAllowed('aqg_get_remediation_guidance', ['history:read']));
        self::assertFalse(McpScopes::toolAllowed('aqg_list_content_findings', []));
        self::assertSame('read', McpScopes::riskOf('content:read'));
        self::assertSame('contentRead', McpScopes::labelKey('content:read'));
    }

    #[Test]
    public function riskLabelsAndLabelKeys(): void
    {
        self::assertSame('read', McpScopes::riskOf('findings:read'));
        self::assertSame('act', McpScopes::riskOf('scans:run-page'));
        self::assertSame('locked', McpScopes::riskOf('changes:apply'));
        self::assertSame('scansRunPage', McpScopes::labelKey('scans:run-page'));
    }

    #[Test]
    public function agentAccessIsForProAndAgencyOnly(): void
    {
        $status = static fn (bool $valid, string $plan, bool $isTrial = false): object => (object)['valid' => $valid, 'plan' => $plan, 'isTrial' => $isTrial];

        self::assertTrue(McpSettingsController::isEligible($status(true, 'pro')));
        self::assertTrue(McpSettingsController::isEligible($status(true, 'Agency')));
        self::assertFalse(McpSettingsController::isEligible($status(true, 'trial', true)));
        self::assertFalse(McpSettingsController::isEligible($status(true, 'agency', true)), 'An Agency trial is a trial.');
        self::assertFalse(McpSettingsController::isEligible($status(true, 'free')));
        self::assertFalse(McpSettingsController::isEligible($status(false, 'pro')));
    }
}
