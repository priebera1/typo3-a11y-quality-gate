<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Settings › Agent access (MCP), owner UX pass 3.0.0: a management hierarchy built from Atlas form components. The
 * redesign keeps every form, field name and route the controller validates; it only changes the presentation.
 */
final class McpSettingsTemplateTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private static function template(): string
    {
        return (string)file_get_contents(self::ROOT . '/Resources/Private/Partials/Settings/TabMcp.html');
    }

    #[Test]
    public function sectionsFollowTheManagementHierarchy(): void
    {
        $template = self::template();
        $order = ['aqg-mcp-connection-title', 'aqg-mcp-sites-title', 'aqg-mcp-agents-title', 'aqg-mcp-boundaries-title', 'aqg-mcp-audit-title', 'aqg-mcp-disconnect-title'];
        $positions = array_map(static fn (string $id): int => (int)strpos($template, 'id="' . $id . '"'), $order);
        self::assertNotContains(0, $positions);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'Gateway → sites → agents → boundaries → audit → disconnect.');
        self::assertStringContainsString('<f:render section="StateBadge" arguments="{state: mcp.state}" />', $template);
    }

    /**
     * C-03 (2026-11-05 company acceptance): the hosting requirement — a supervised long-running process — surfaced only
     * after pairing, as a "Connector offline" state. It is stated before "Connect to AQG Gateway".
     */
    #[Test]
    public function theHostingRequirementIsStatedBeforePairing(): void
    {
        $template = self::template();
        $intro = substr($template, (int)strpos($template, '<f:section name="ConnectIntro">'));
        $intro = substr($intro, 0, (int)strpos($intro, '</f:section>'));
        $requirement = strpos($intro, 'settings.mcp.intro.requirement');

        self::assertNotFalse($requirement, 'ConnectIntro names the hosting requirement.');
        self::assertLessThan(strpos($intro, 'settings.mcp.intro.connect"'), $requirement, 'Before the Connect button.');
    }

    #[Test]
    public function capabilitiesAreAlignedCheckboxRowsNotBulletLists(): void
    {
        $template = self::template();
        self::assertStringNotContainsString('<ul class="aqg-mcp-scopes">', $template);
        self::assertStringNotContainsString('badge badge-', (string)file_get_contents(self::ROOT . '/Resources/Private/Partials/Settings/McpRisk.html'));
        self::assertMatchesRegularExpression('/<fieldset class="aqg-mcp-caps">\s*<legend class="visually-hidden">/', $template);
        self::assertStringContainsString('<label class="aqg-mcp-cap" for="{capability.fieldId}">', $template);
        self::assertStringContainsString('<span class="aqg-mcp-cap__title">{capability.title}</span>', $template);
    }

    #[Test]
    public function formsKeepTheirFieldsAndRoutes(): void
    {
        $template = self::template();
        foreach ([
            'action="{mcp.urls.saveSites}"', 'action="{mcp.urls.connect}"', 'action="{mcp.urls.cancelPairing}"', 'action="{mcp.urls.disconnect}"',
            'name="enabled[{site.identifier}]" value="0"', 'role="switch"', 'name="capabilities[{site.identifier}][]" value="{capability.scope}"',
            'name="confirmWord"', 'data-aqg-mcp-disconnect="true"', 'data-aqg-mcp-pairing="true"', 'data-aqg-mcp-sites="true"',
            'href="{mcp.urls.auditAll}"', 'href="{mcp.urls.auditRefused}"',
        ] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
        $tokens = (string)file_get_contents(self::ROOT . '/Resources/Private/Partials/Settings/McpTokenTable.html');
        self::assertStringContainsString('data-aqg-mcp-revoke="true"', $tokens);
        self::assertStringContainsString('name="tokenId" value="{token.id}"', $tokens);
        self::assertStringNotContainsString('token.value', $tokens, 'Token values are never rendered.');
    }

    #[Test]
    public function stylesUseAtlasTokensAndCompile(): void
    {
        $scss = (string)file_get_contents(self::ROOT . '/Resources/Private/Scss/views/_settings.scss');
        $start = strpos($scss, '.a11y-settings.aqg-module .aqg-mcp {');
        self::assertNotFalse($start);
        $block = substr($scss, $start, 18000);
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,6}\b/', $block, 'Agent access uses tokens, not literal colours.');
        self::assertDoesNotMatchRegularExpression('/border-(left|inline-start)-width:\s*[2-9]px/', $block);

        $css = (string)file_get_contents(self::ROOT . '/Resources/Public/Css/backend.css');
        self::assertStringContainsString('.a11y-settings.aqg-module .aqg-mcp .aqg-mcp-cap{', $css);
        self::assertStringContainsString('.a11y-settings.aqg-module .aqg-mcp .aqg-mcp-cap:focus-within{box-shadow:var(--aqi-focus-ring)}', $css);
    }

    /**
     * First real activation (typo3test14, 2026-11-09): a fresh connection whose connector has not reported is a polite
     * status that polls itself into the next state, never the red "Connector offline" alert; it still shows the
     * connector command, the agents list and the disconnect.
     */
    #[Test]
    public function aStartingConnectionIsAStatusThatPollsNotAnAlert(): void
    {
        $template = self::template();
        self::assertMatchesRegularExpression('/<f:case value="starting">\s*<f:comment>.*?<\/f:comment>\s*<div class="aqg-statusline tone-info" role="status" aria-live="polite" data-aqg-mcp-starting="true" data-state-url="\{mcp\.urls\.connectionState\}"/s', $template);
        self::assertStringContainsString('<f:case value="starting"><span class="aqg-status-badge aqg-status-badge--warning">', $template);
        self::assertStringContainsString("{mcp.state} == 'suspended' || {mcp.state} == 'starting'\">\n        <section class=\"aqg-card\" aria-labelledby=\"aqg-mcp-agents-title\">", $template);
        self::assertStringContainsString("{mcp.state} == 'unreachable' || {mcp.state} == 'starting'", $template);
        $js = (string)file_get_contents(self::ROOT . '/Resources/Public/JavaScript/backend/settings-mcp.js');
        self::assertStringContainsString("querySelector('[data-aqg-mcp-starting]')", $js);
    }

    /** The audit trail is paged by the server: previous/next and page links from auditPagination, a range line. */
    #[Test]
    public function theAuditTrailHasServerSidePagination(): void
    {
        $template = self::template();
        self::assertStringContainsString('arguments="{0: mcp.auditPagination.firstItem, 1: mcp.auditPagination.lastItem, 2: mcp.auditPagination.totalItems}"', $template);
        self::assertStringContainsString('<f:if condition="{mcp.auditPagination.totalPages} > 1">', $template);
        self::assertStringContainsString('href="{mcp.auditPagination.previousUrl}" rel="prev"', $template);
        self::assertStringContainsString('href="{mcp.auditPagination.nextUrl}" rel="next"', $template);
        self::assertStringContainsString('<f:for each="{mcp.auditPagination.items}" as="item">', $template);
        $modules = (string)file_get_contents(self::ROOT . '/Configuration/Backend/Modules.php');
        self::assertStringContainsString("'mcpAuditPage' => true,", $modules, 'the page parameter survives the module route');
    }
}
