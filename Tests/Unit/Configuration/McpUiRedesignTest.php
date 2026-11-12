<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * G6 (2026-11-11) redesign of the two agent-access screens outside Settings – "My connected agents" and the OAuth
 * consent page – and the module sub-navigation entry. Guards the contracts the redesign must keep: no token value in the
 * view, the client label never presented as verified, scanning never ticked in advance, Deny as the first submit button,
 * the same form fields, the toolbar link's permission gates for the navigation entry, theme tokens only.
 */
final class McpUiRedesignTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    private static function read(string $path): string
    {
        return (string)file_get_contents(self::ROOT . $path);
    }

    #[Test]
    public function myConnectedAgentsIsReachableFromTheModuleSubNavigationOnlyThroughTheToolbarGates(): void
    {
        $subnav = self::read('Resources/Private/Partials/Remediation/SubNav.html');
        self::assertStringContainsString('<f:variable name="agentsUrl"><aqg:connectedAgentsUrl /></f:variable>', $subnav);
        self::assertMatchesRegularExpression('#<f:if condition="\{agentsUrl\}">\s*<f:if condition="\{current\} == \'agents\'">#', $subnav, 'no link without the gates');
        self::assertStringContainsString('href="{agentsUrl}" aria-current="page"', $subnav);
        $viewHelper = self::read('Classes/ViewHelpers/ConnectedAgentsUrlViewHelper.php');
        // dg/bypass-finals (CI) strips final/readonly from PHP files read through its stream wrapper.
        self::assertStringContainsString('ConnectedAgentsLinkResolver $linkResolver', $viewHelper, 'one rule with the toolbar link');
        self::assertStringContainsString("{current: 'agents'", self::read('Resources/Private/Templates/Mcp/MyAgents.html'));
    }

    #[Test]
    public function connectionsAreSeparatedCardsWithAnHonestIdentityAndATwoStepRevoke(): void
    {
        $page = self::read('Resources/Private/Templates/Mcp/MyAgents.html');
        $card = self::read('Resources/Private/Partials/Mcp/AgentConnection.html');
        self::assertStringContainsString('<f:render partial="Mcp/AgentConnection"', $page);
        self::assertStringContainsString('<details class="aqg-card aqg-mcp-ended">', $page, 'ended connections apart from active ones');
        self::assertStringContainsString('<div class="a11y-settings aqg-module aqg-mcp-view aqg-mcp-agents">', $page, 'inside the Settings design system');
        self::assertStringContainsString('<div class="aqg-mcp aqg-stack aqg-mcp-view__body">', $page, '.aqg-mcp styles of Settings apply (descendant)');
        self::assertStringContainsString('aqg-mcp-chip--unverified', $card);
        self::assertStringContainsString('mcp.agent.notVerified', $card);
        self::assertStringNotContainsString('token.value', $card, 'token values are never rendered');
        self::assertMatchesRegularExpression('#<details class="aqg-mcp-conn__revoke">\s*<summary[^>]*>.*?</summary>\s*<form method="post" action="\{revokeUrl\}"[^>]*data-aqg-mcp-revoke="true">#s', $card, 'confirm before revoking');
        self::assertStringContainsString('<input type="hidden" name="tokenId" value="{token.id}" />', $card);
        foreach (['{item.title}', '{item.scope}', '{token.expiresAt}', '{token.lastUsedAt}', '{token.revokedAt}', '{token.tokenLabel}'] as $shown) {
            self::assertStringContainsString($shown, $card);
        }
        self::assertStringContainsString("'scopeItems' => array_map(", self::read('Classes/Mcp/McpSettingsViewBuilder.php'));
    }

    #[Test]
    public function theConsentKeepsItsContractAndNeverTicksScanningInAdvance(): void
    {
        $t = self::read('Resources/Private/Templates/Mcp/Consent.html');
        $deny = strpos($t, 'name="decision" value="deny"');
        $allow = strpos($t, 'name="decision" value="allow"');
        self::assertIsInt($deny);
        self::assertIsInt($allow);
        self::assertLessThan($allow, $deny, 'Deny is the first submit button: Enter submits Deny');
        foreach (['name="request" value="{requestRef}"', 'name="scopes[]" value="{scope.scope}"', 'name="sites[]" value="{site.identifier}"', 'name="duration"', '<option value="90">'] as $field) {
            self::assertStringContainsString($field, $t);
        }
        self::assertStringContainsString('<option value="7" selected="selected">', $t, 'P1-5: 7 days preselected');
        self::assertStringContainsString('<f:if condition="{site.preselected}">', $t, 'P1-5: sites approved explicitly');
        self::assertStringContainsString("<f:if condition=\"{scope.preselected} && {scope.scope} != 'scans:run-page'\">", $t);
        self::assertStringContainsString('<f:if condition="{scope.explicitScan}">', $t);
        self::assertStringContainsString('mcp.consent.explicitScanScope', $t);
        self::assertStringContainsString('mcp.consent.clientNote', $t, 'Q2 wording of the parallel hardening');
        self::assertStringContainsString('disabled="disabled"', $t, 'a capability that cannot be granted stays disabled');
        self::assertStringContainsString('<p class="aqg-mcp-consent__client">{clientName}</p>', $t, 'plain text, not a confirmed name');
        self::assertStringContainsString('class="a11y-settings aqg-module', $t);
    }

    #[Test]
    public function theStylesUseThemeTokensWrapLongLabelsAndShowFocus(): void
    {
        $scss = self::read('Resources/Private/Scss/views/_mcp.scss');
        self::assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', $scss, 'theme tokens only (light and dark)');
        self::assertStringContainsString('overflow-wrap: anywhere', $scss);
        self::assertStringContainsString(':focus-visible', $scss);
        self::assertStringContainsString('@container (max-width: 560px)', $scss);
        self::assertStringContainsString('@use "views/mcp";', self::read('Resources/Private/Scss/backend.scss'));
        $css = self::read('Resources/Public/Css/backend.css');
        self::assertStringContainsString('.a11y-settings.aqg-module.aqg-mcp-view .aqg-mcp-conn{', $css, 'built CSS is current');
        self::assertStringContainsString('.a11y-settings.aqg-module.aqg-mcp-view .aqg-mcp-consent__client{', $css);
    }

    #[Test]
    public function everyNewLabelIsTranslated(): void
    {
        $en = self::read('Resources/Private/Language/locallang.xlf');
        $de = self::read('Resources/Private/Language/de.locallang.xlf');
        $templates = self::read('Resources/Private/Templates/Mcp/MyAgents.html') . self::read('Resources/Private/Templates/Mcp/Consent.html')
            . self::read('Resources/Private/Partials/Mcp/AgentConnection.html') . self::read('Resources/Private/Partials/Remediation/SubNav.html');
        // Every reference names a key (a reference without one renders only its default text and is never translated).
        self::assertDoesNotMatchRegularExpression('#locallang\.xlf[^:]#', $templates);
        preg_match_all('#locallang\.xlf:([a-zA-Z0-9.]+)(?:"|\\\\\')#', $templates, $m);
        foreach (array_unique($m[1]) as $key) {
            self::assertStringContainsString('<trans-unit id="' . $key . '">', $en, $key . ' (en)');
            self::assertMatchesRegularExpression('#<trans-unit id="' . preg_quote($key, '#') . '">\s*<source>[^<]*</source>\s*<target>[^<]+</target>#u', $de, $key . ' (de)');
        }
    }
}
