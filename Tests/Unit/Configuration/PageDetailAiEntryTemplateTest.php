<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\PageDetailController;

/**
 * Atlas 07·A (audit F-07, F-35, F-34, F-24, F-23): one AI entry per occurrence, the wand icon and no plan tag on the
 * inline suggestion, a 24 px selection target, and owner chips without zero entries.
 */
final class PageDetailAiEntryTemplateTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../';

    #[Test]
    public function anOccurrenceWithAnInlineAiFlowGetsNoSecondQueueEntry(): void
    {
        $partial = $this->read('Resources/Private/Partials/Remediation/Occurrence.html');

        self::assertStringContainsString('<f:if condition="{remediation.canPrepare} && !{remediation.inlineAi}">', $partial);
        // Still eligible for the bulk path ("Prepare AI suggestions" for a selection, plan "Send editor steps").
        self::assertStringContainsString('data-aqg-remediation-eligible="{f:if(condition: remediation.canPrepare, then: \'1\', else: \'0\')}"', $partial);
        self::assertSame(1, substr_count($partial, 'data-aqg-remediation-prepare='));

        $controller = $this->read('Classes/Controller/PageDetailController.php');
        self::assertStringContainsString("\$row['remediation']['inlineAi'] = self::hasInlineAiFlow(\$row);", $controller);
    }

    #[Test]
    public function anOccurrenceHasOneActionRowAndSeparateExplainAndFixResultRegions(): void
    {
        $partial = $this->read('Resources/Private/Partials/Remediation/Occurrence.html');

        // No repeated generic help text and no owner chip per occurrence: both are said once (page / rule card).
        self::assertStringNotContainsString('remediation.pd.prepareHint', $partial);
        self::assertStringNotContainsString('aiCost.hint', $partial);
        self::assertStringNotContainsString('data-aqg-owner-set', $partial);
        self::assertStringContainsString("aria-describedby=\"{f:if(condition: remediation.fixCost, then: 'aqg-rem-fix-cost-{issue.uid} ')}aqg-rem-fix-hint aqg-rem-cost-hint\"", $partial);
        self::assertStringContainsString("aria-describedby=\"{f:if(condition: remediation.explainCost, then: 'aqg-rem-explain-cost-{issue.uid} ')}aqg-rem-explain-hint aqg-rem-cost-hint\"", $partial);
        // The accessible name is the action only: the amount sentence is a description outside the button.
        self::assertDoesNotMatchRegularExpression('#<button[^>]*data-aqg-remediation-(prepare|explain)[^>]*>(?:(?!</button>).)*remediation\.pd\.costDescription#s', $partial);
        // Explain and Fix never share a result region.
        self::assertSame(1, substr_count($partial, 'data-aqg-remediation-explanation="{issue.uid}"'));
        self::assertSame(1, substr_count($partial, 'data-aqg-remediation-fixresult="{issue.uid}"'));
        // An existing item links to exactly that item in AI fixes.
        self::assertStringContainsString('href="{queueUrl}&item={remediation.item.uid}"', $partial);

        $summary = $this->read('Resources/Private/Partials/Remediation/PageSummary.html');
        foreach (['aqg-rem-explain-hint', 'aqg-rem-fix-hint', 'aqg-rem-cost-hint'] as $id) {
            self::assertSame(1, substr_count($summary, 'id="' . $id . '"'), $id);
        }
        $show = $this->read('Resources/Private/Templates/PageDetail/Show.html');
        self::assertSame(1, substr_count($show, '<f:render partial="Remediation/RuleOwner"'));
        self::assertStringContainsString('data-aqg-owner-set="{owner}"', $this->read('Resources/Private/Partials/Remediation/RuleOwner.html'));
    }

    /** @return iterable<string, array{array<string,mixed>, bool}> */
    public static function inlineFlows(): iterable
    {
        yield 'image with AI alt suggestion' => [['imageRemediationAvailable' => true, 'aiAltSuggestionAvailable' => true], true];
        yield 'decorative image' => [['imageRemediationAvailable' => true, 'aiAltSuggestionAvailable' => true, 'imageIsDecorative' => true], false];
        yield 'image without AI' => [['imageRemediationAvailable' => true, 'aiAltSuggestionAvailable' => false], false];
        yield 'link text suggestion' => [['aiLinkTextSuggestionAvailable' => true], true];
        yield 'iframe title suggestion' => [['aiIframeTitleSuggestionAvailable' => true], true];
        yield 'rich-text finding without an inline flow' => [['rule_id' => 'rte.empty_heading'], false];
    }

    /** @param array<string,mixed> $row */
    #[Test]
    #[DataProvider('inlineFlows')]
    public function inlineFlowDetection(array $row, bool $expected): void
    {
        self::assertSame($expected, PageDetailController::hasInlineAiFlow($row));
    }

    #[Test]
    public function inlineSuggestionsUseTheWandAndNoPlanTag(): void
    {
        foreach (['ImageRemediationActions', 'AiLinkTextSuggestion', 'AiIframeTitleSuggestion'] as $name) {
            $partial = $this->read('Resources/Private/Partials/Issue/' . $name . '.html');
            // The buttons render only when the site's AI setup accepts the request, i.e. for entitled users.
            self::assertStringNotContainsString('aqg-btn-pro', $partial, $name);
            self::assertStringNotContainsString('PRO', $partial, $name);
            self::assertStringNotContainsString('✦', $partial, $name);
            self::assertStringContainsString('<span class="aqg-media-wand-icon" aria-hidden="true"></span>', $partial, $name);
        }
        $image = $this->read('Resources/Private/Partials/Issue/ImageRemediationActions.html');
        self::assertLessThan(strpos($image, 'data-action="aqg-apply-alt"'), strpos($image, 'data-action="aqg-suggest-alt"'), 'Suggest comes first (Atlas 07·A).');
    }

    #[Test]
    public function occurrenceSelectionHasA24PixelTarget(): void
    {
        $scss = $this->read('Resources/Private/Scss/views/_page-detail.scss');
        self::assertMatchesRegularExpression('/\.a11y-page-detail \.aqg-issue__select \{[^}]*width: 24px;[^}]*height: 24px;/s', $scss);
        $css = $this->read('Resources/Public/Css/backend.css');
        self::assertMatchesRegularExpression('/\.a11y-page-detail \.aqg-issue__select\{[^}]*width:24px;height:24px/', $css);
    }

    #[Test]
    public function summaryShowsOwnerChipsWithoutZeroEntriesAndALiveQueueLine(): void
    {
        $summary = $this->read('Resources/Private/Partials/Remediation/PageSummary.html');
        self::assertStringContainsString('<f:if condition="{ownerCount} > 0">', $summary);
        self::assertStringContainsString('data-aqg-remediation-summary-queue="true"', $summary);
        self::assertStringNotContainsString('remediation.pd.ownerSplit', $summary);

        $indicator = $this->read('Resources/Private/Templates/Backend/PageModuleIndicator.html');
        self::assertStringContainsString('<f:for each="{remediation.owners}" as="owner">', $indicator);
        self::assertStringContainsString('data-aqg-indicator-pending="true"', $indicator);
        self::assertStringContainsString('<f:if condition="{remoteHint.tag}">', $indicator);
        $service = $this->read('Classes/Service/PageModuleIndicatorService.php');
        self::assertStringContainsString('if ($count > 0) {', $service);
        self::assertStringNotContainsString("'tag' => 'PRO'", $service);
    }

    private function read(string $path): string
    {
        return file_get_contents(self::ROOT . $path) ?: '';
    }
}
