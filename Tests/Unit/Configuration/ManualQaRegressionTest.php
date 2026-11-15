<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Owner manual QA on TYPO3 14, 2026-11-14: contracts of the fixes that are markup and wiring.
 */
final class ManualQaRegressionTest extends TestCase
{
    private static function read(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    /** MI-01: a GET form replaces the action's query; without the route token TYPO3 rendered its backend in the frame. */
    #[Test]
    public function myIssuesFiltersCarryTheRouteToken(): void
    {
        $template = self::read('Resources/Private/Templates/Issues/Index.html');
        self::assertStringContainsString('<input type="hidden" name="token" value="{formToken}" />', $template);
        self::assertStringContainsString("'formToken' => \$this->routeToken(\$formAction)", self::read('Classes/Controller/IssuesController.php'));
    }

    /** MI-02..MI-04: one results region the script swaps; chips with × links; page size; Settings in the doc header. */
    #[Test]
    public function myIssuesHasChipsPageSizeAndAResultsRegion(): void
    {
        $template = self::read('Resources/Private/Templates/Issues/Index.html');
        self::assertStringContainsString('data-aqg-issues-results="true"', $template);
        self::assertStringContainsString('name="perPage"', $template);
        self::assertStringContainsString('{chip.removeLabel}', $template);
        self::assertStringContainsString('aria-live="polite" data-aqg-issues-status="true"', $template);
        // Previous, next, the current page and the other pages: every pagination link updates in place.
        self::assertSame(4, substr_count($template, 'data-aqg-issues-nav="true" class="btn btn-default btn-sm aqg-pagination__link'));
        $controller = self::read('Classes/Controller/IssuesController.php');
        self::assertStringContainsString('addSettingsDocHeaderButton', $controller);
        self::assertStringContainsString('IssuesListState::RETURN_PARAMETER', $controller);
    }

    /** MI-05/MI-06: Settings on AI fixes; Page Detail goes back to My issues, rebuilt from the sanitised state. */
    #[Test]
    public function settingsAndTheWayBackAreConsistent(): void
    {
        self::assertStringContainsString('addSettingsDocHeaderButton', self::read('Classes/Controller/RemediationQueueController.php'));
        $pageDetail = self::read('Classes/Controller/PageDetailController.php');
        self::assertStringContainsString("IssuesListState::fromReturnValue(\$issuesReturn)", $pageDetail);
        self::assertStringContainsString("\$this->buildRouteUrl('web_a11y.issues', ['id' => \$pageUid] + \$issuesState->toParameters())", $pageDetail);
    }

    /** AF-01/AF-02: a deep link to one item says why it cannot open; a licence state never reads as "no item". */
    #[Test]
    public function aiFixesDeepLinksHaveExplicitStates(): void
    {
        $template = self::read('Resources/Private/Templates/Remediation/Index.html');
        foreach (['unavailable', 'other_page', 'licence'] as $state) {
            self::assertStringContainsString('data-aqg-rq-deeplink="' . $state . '"', $template);
        }
        self::assertStringContainsString("in_array(\$deepLink['state'], ['open', 'other_page'], true)", self::read('Classes/Controller/RemediationQueueController.php'));
        $repair = self::read('Classes/Backend/ToolbarItems/Assistant/Ask/AskAqgRepairService.php');
        self::assertLessThan(
            strpos($repair, "if (\$outcome === 'already_queued') {"),
            strpos($repair, "if (!\$licence && ((\$outcome === 'already_queued'"),
            'Ask AQG checks the licence before it offers "Review suggestion" for a kept item.',
        );
    }

    /** MCP-UI-01: a rejected connection offers its own recovery instead of a Connect the guard refuses. */
    #[Test]
    public function aRejectedGatewayConnectionCanBeReplaced(): void
    {
        $tab = self::read('Resources/Private/Partials/Settings/TabMcp.html');
        // The status section's case (the badge's case for the same state comes earlier in the partial).
        $revoked = substr($tab, (int)strrpos($tab, '<f:case value="revoked">'));
        $revoked = substr($revoked, 0, (int)strpos($revoked, '</f:case>'));
        self::assertStringContainsString('action="{mcp.urls.reconnect}"', $revoked);
        self::assertStringNotContainsString('ConnectIntro', $revoked);
        self::assertStringContainsString("'settingsMcpReconnect' =>", self::read('Configuration/Backend/Modules.php'));
        $service = self::read('Classes/Mcp/McpPairingService.php');
        self::assertStringContainsString("\$exception->httpStatus === 401 && \$exception->apiErrorCode === 'connector_unauthorized'", $service);
    }

    /** AQA-01/AQA-02/AQA-04: compact dropdown, a real Expand button, wrapping quick questions in the dock. */
    #[Test]
    public function theToolbarPanelIsCompactAndItsExpandIsAButton(): void
    {
        $scss = self::read('Resources/Private/Scss/toolbar.scss');
        self::assertStringNotContainsString('min(34rem', $scss);
        self::assertStringContainsString('width: min(25.5rem, calc(100vw - 1rem));', $scss);
        self::assertStringNotContainsString('aqt-btn--quiet aqt-assistant__expand', self::read('Resources/Private/Templates/ToolbarItems/A11yScanToolbarAssistant.html'));
        self::assertMatchesRegularExpression('/\.aqt-assistant--expanded \.aqt-chip-btn,[^{]*\{[^}]*white-space: normal;/s', $scss);
        self::assertStringContainsString('25.5rem', self::read('Resources/Public/Css/toolbar.css'), 'The compressed bundle is rebuilt.');
    }

    /** Follow-up: one filter pattern — the AI fixes filter bar and chip row on My issues, "Show" only without JS. */
    #[Test]
    public function myIssuesAndAiFixesShareOneFilterPattern(): void
    {
        $issues = self::read('Resources/Private/Templates/Issues/Index.html');
        $queue = self::read('Resources/Private/Templates/Remediation/Index.html');
        self::assertStringContainsString('class="aqg-remediation__filters aqg-remediation__filters--issues"', $issues);
        self::assertStringContainsString('<div class="aqg-filterchips" data-aqg-issues-chips="true">', $issues);
        self::assertStringContainsString('<div class="aqg-filterchips" data-aqg-rq-chips="true">', $queue);
        self::assertStringContainsString('class="aqg-remediation__chip" data-aqg-issues-chip="{chip.name}"', $issues);
        self::assertStringContainsString('data-aqg-issues-apply="true"', $issues);
        self::assertStringContainsString('fallback.hidden = true;', self::read('Resources/Public/JavaScript/backend/issues.js'));
        self::assertStringContainsString('<source>Status</source>', (string)preg_replace('/\s+/', ' ', self::sourceOf('assignment.filter.state')));
        self::assertStringContainsString('<source>Clear filters</source>', self::sourceOf('assignment.chips.clearAll'));
    }

    /** Widening choices are no chips: "Everyone" and "All" states (owner: "State: All" is not a filter). */
    #[Test]
    public function onlyNarrowingFiltersBecomeChips(): void
    {
        $controller = (new \ReflectionClass(\Priebera\A11yQualityGate\Controller\IssuesController::class))->newInstanceWithoutConstructor();
        $chips = new \ReflectionMethod($controller, 'activeFilterChips');
        $url = static fn (\Priebera\A11yQualityGate\Assignment\IssuesListState $state): string => '/issues?' . http_build_query($state->toParameters());
        $names = static fn (array $query): array => array_column($chips->invoke($controller, \Priebera\A11yQualityGate\Assignment\IssuesListState::fromArray($query), [['uid' => '7', 'label' => 'Eva']], [['value' => 'main', 'label' => 'Main']], $url), 'name');

        self::assertSame([], $names(['assignee' => 'all', 'state' => 'any']));
        self::assertSame(['assignee', 'state', 'site', 'q'], $names(['assignee' => 'unassigned', 'state' => 'done', 'site' => 'main', 'q' => 'alt']));
        self::assertSame(['assignee'], $names(['assignee' => '7', 'state' => 'any']));
    }

    private static function sourceOf(string $key): string
    {
        $xlf = self::read('Resources/Private/Language/locallang.xlf');
        $start = (int)strpos($xlf, 'id="' . $key . '"');

        return substr($xlf, $start, (int)strpos($xlf, '</trans-unit>', $start) - $start);
    }
}
