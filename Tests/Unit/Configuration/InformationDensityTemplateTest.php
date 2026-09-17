<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the information hierarchy of the AQG screens: every field has a programmatic label, primary
 * information stays in view, secondary metadata sits behind a disclosure, and counts say what they count.
 */
final class InformationDensityTemplateTest extends TestCase
{
    private const PRIVATE = __DIR__ . '/../../../Resources/Private/';

    #[Test]
    public function everySiteOverrideFieldHasItsOwnProgrammaticLabel(): void
    {
        $row = $this->read('Partials/Settings/QualityGateSiteRow.html');

        preg_match_all('/<(select|input)\b[^>]*>/', $row, $controls);
        self::assertCount(3, $controls[0]);

        $ids = [];
        foreach ($controls[0] as $control) {
            self::assertSame(1, preg_match('/\sid="([^"]+)"/', $control, $id), 'Control without id: ' . $control);
            self::assertStringContainsString('{siteRuleset.site_identifier}', $id[1], 'Ids must be unique per site override.');
            self::assertStringContainsString('<label class="aqg-field__label" for="' . $id[1] . '">', $row);
            $ids[] = $id[1];
        }
        self::assertSame($ids, array_unique($ids));
        self::assertStringNotContainsString('<label class="aqg-field__label">', $row, 'Every label names its control.');
    }

    #[Test]
    public function siteOverridesShowASummaryAndEditTheFieldsOnDemand(): void
    {
        $row = $this->read('Partials/Settings/QualityGateSiteRow.html');
        $head = $this->between($row, '<header class="aqg-site__head">', '</header>');
        $details = $this->between($row, '<details class="aqg-site__details js-aqg-site-details">', '</details>');

        self::assertStringContainsString('js-aqg-mode-pill', $head);
        self::assertStringContainsString('settings.qualityGate.site.summaryCritical', $head);
        self::assertStringContainsString('js-aqg-site-summary-warning', $head);
        self::assertStringNotContainsString('<select', $head);

        // Primary line: site name, identifier and publishing mode. Secondary line: the thresholds only.
        $primaryLine = $this->between($row, '<span class="aqg-site__title">', '<span class="aqg-site__summary">');
        $secondaryLine = $this->between($row, '<span class="aqg-site__summary">', '</header>');
        self::assertStringContainsString('aqg-site__id', $primaryLine);
        self::assertStringContainsString('js-aqg-mode-pill', $primaryLine);
        self::assertStringNotContainsString('js-aqg-mode-pill', $secondaryLine);
        self::assertStringContainsString('js-aqg-site-summary-critical', $secondaryLine);
        self::assertStringContainsString('js-aqg-site-summary-warning', $secondaryLine);
        self::assertStringContainsString('js-aqg-site-mode', $details);
        self::assertStringContainsString('js-aqg-site-critical', $details);
        self::assertStringContainsString('js-aqg-site-warning', $details);
        self::assertStringNotContainsString(' open', $this->openingTag($row, '<details class="aqg-site__details'));
    }

    #[Test]
    public function qualityGateGuidanceSaysWhichScanADecisionUses(): void
    {
        $tab = $this->read('Partials/Settings/TabQualityGate.html');

        self::assertStringContainsString('settings.qualityGate.guidance.stepScan', $tab);
        self::assertStringContainsString('fresh content scan', $this->english('settings.qualityGate.guidance.stepScan'));
        self::assertStringContainsString('Frontend scan results are not part of the decision', $this->english('settings.qualityGate.guidance.stepScan'));
    }

    #[Test]
    public function viewAffectedPagesKeepsTheRuleContext(): void
    {
        $panel = $this->read('Partials/Overview/RemotePanel.html');

        self::assertStringContainsString("href=\"{f:if(condition: fix.affectedPagesUrl, then: fix.affectedPagesUrl, else: '#a11y-remote-top-pages')}\"", $panel);
        self::assertStringContainsString('data-aqg-rule-filter="{fix.ruleId}"', $panel);
        self::assertStringContainsString('{remoteRuleFilter.clearUrl}', $panel);
        self::assertSame(
            2,
            substr_count($panel, '<input type="hidden" name="remoteRule" value="{remoteRule}" />'),
            'Searching the page lists must keep the rule filter.'
        );
    }

    #[Test]
    public function countsDistinguishIssueTypesFromOccurrences(): void
    {
        $detail = $this->read('Templates/RemotePageDetail/Show.html');
        $panel = $this->read('Partials/Overview/RemotePanel.html');
        $hero = $this->between($detail, '<div class="aqg-hero__strip">', '</header>');

        self::assertMatchesRegularExpression('/remotePageDetail\.issueTypes"[^>]*\/>\s*<\/span>\s*<span[^>]*>\{remotePage\.issues_count\}/', $hero);
        self::assertMatchesRegularExpression('/remotePageDetail\.occurrencesTotal"[^>]*\/><\/span>\s*<span[^>]*>\{pageFindingsCount\}/', $hero);
        self::assertStringNotContainsString('remote.history.findings', $hero);
        self::assertStringNotContainsString('remotePageDetail.issueGroups', $detail);

        self::assertStringContainsString('overview.remote.table.issueTypes', $panel);
        self::assertStringContainsString('overview.remote.priorityFixes.occurrences', $panel);
        self::assertStringContainsString('overview.remote.metric.occurrences', $panel);
        self::assertStringNotContainsString('overview.remote.priorityFixes.issues"', $panel);

        foreach (['Templates', 'Partials'] as $directory) {
            foreach (glob(self::PRIVATE . $directory . '/*/*.html') ?: [] as $file) {
                if (!str_contains($file, '/Export/')) {
                    self::assertStringNotContainsString('remote.history.findings"', (string)file_get_contents($file), $file);
                }
            }
        }

        self::assertSame(['Issue types', 'Problemtypen'], $this->label('overview.remote.table.issueTypes'));
        self::assertSame(['Occurrences', 'Vorkommen'], $this->label('module.remotePageDetail.occurrencesTotal'));
        self::assertSame(['%d issue types', '%d Problemtypen'], $this->label('pageModuleIndicator.metric.issueTypes.plural'));
        self::assertSame(['%d occurrences', '%d Vorkommen'], $this->label('pageModuleIndicator.metric.occurrences.plural'));
    }

    #[Test]
    public function frontendPageTablesKeepTheHttpStatusColumn(): void
    {
        $panel = $this->read('Partials/Overview/RemotePanel.html');
        $tables = [
            'Affected pages' => [
                $this->between($panel, 'id="a11y-remote-top-pages">', '</table>'),
                ['overview.table.page', 'overview.remote.table.issueTypes', 'overview.proSummary.table.httpStatus', 'overview.table.actions'],
            ],
            'Failed pages' => [
                $this->between($panel, 'id="a11y-remote-failed-pages">', '</table>'),
                ['overview.table.page', 'overview.proSummary.table.httpStatus', 'overview.proSummary.table.failureReason', 'overview.table.actions'],
            ],
        ];

        foreach ($tables as $name => [$table, $columns]) {
            preg_match_all(
                '/<th\b[^>]*>\s*<f:translate\s+key="LLL:EXT:a11y_quality_gate\/Resources\/Private\/Language\/locallang\.xlf:([\w.]+)"/',
                $this->between($table, '<thead>', '</thead>'),
                $headers
            );
            self::assertSame($columns, $headers[1], $name);

            $cells = array_slice(preg_split('/<td\b/', $this->between($table, '<tbody>', '</tr>')) ?: [], 1);
            self::assertCount(count($columns), $cells, $name . ': one cell per column header.');
            $httpCell = $cells[(int)array_search('overview.proSummary.table.httpStatus', $columns, true)];
            self::assertStringContainsString('<span class="aqg-http">', $httpCell, $name);
            self::assertMatchesRegularExpression(
                '/<f:if condition="\{page\.http_status\}">\s*<f:then>\{page\.http_status\}<\/f:then>\s*<f:else>—<\/f:else>/',
                $httpCell,
                $name . ': a missing status (0) renders a dash, not a code.'
            );
        }
    }

    #[Test]
    public function pageModuleRowsNameTheirSourceAndScanTime(): void
    {
        $indicator = $this->read('Templates/Backend/PageModuleIndicator.html');

        self::assertStringContainsString('data-aqg-indicator-source="{row.source}"', $indicator);
        self::assertStringContainsString('<span class="aqg-page-module-indicator__status-meta">{row.meta}</span>', $indicator);
        self::assertSame(['Content scan', 'Inhaltsscan'], $this->label('pageModuleIndicator.row.local'));
        self::assertSame(['Frontend scan', 'Frontend-Scan'], $this->label('pageModuleIndicator.row.remote'));
    }

    #[Test]
    public function localFindingsLeadWithTheIssueAndKeepRuleWideActionsInAMenu(): void
    {
        $detail = $this->read('Templates/PageDetail/Show.html');
        $head = $this->between($detail, '<header class="aqg-issue__head">', '</header>');

        self::assertLessThan(
            strpos($head, 'aqg-issue__pills'),
            strpos($head, '<h2 class="aqg-issue__title">'),
            'The issue title comes before its status pills.'
        );
        self::assertStringNotContainsString('a11y-bulk-open-rule', $head);
        self::assertStringNotContainsString('a11y-bulk-select-rule', $head);

        $menu = $this->between($detail, '<details class="aqg-menu" data-aqg-menu="true">', '</details>');
        foreach (['a11y-bulk-select-rule', 'a11y-bulk-open-rule-page', 'a11y-bulk-open-rule-site'] as $action) {
            self::assertStringContainsString('data-action="' . $action . '"', $menu);
        }
        self::assertStringContainsString('<f:if condition="{canIgnoreRuleOnSite}">', $menu, 'Site-wide ignore keeps its permission check.');

        $actions = $this->between($detail, '<div class="aqg-issue__actions">', '<f:if condition="{issue.statusEnum.value} == 0">
                                <div class="aqg-ignore-form');
        self::assertLessThan(strpos($actions, 'data-aqg-menu'), strpos($actions, 'action.editRecord'), 'Edit record stays the first action.');
        self::assertStringContainsString('class="btn btn-primary btn-sm"', $actions);
    }

    #[Test]
    public function remoteRuleSummariesShowOnlyTheEssentials(): void
    {
        $detail = $this->read('Templates/RemotePageDetail/Show.html');
        $summary = $this->between($detail, '<summary class="aqg-rule__head aqg-rule__summary">', '</summary>');

        foreach (['whoShouldFix', 'fixType', 'confidence', 'affectedUserItems', 'wcagPrimaryLabel', 'pageDetail.ruleLabel'] as $secondary) {
            self::assertStringNotContainsString($secondary, $summary);
        }
        self::assertStringContainsString('{issue.count}', $summary);
        self::assertStringContainsString('issue.primaryFixSummary', $summary);

        $details = $this->between($detail, '<summary><f:translate key="LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:module.remotePageDetail.moreDetails"', '</details>');
        foreach (['issue.whoShouldFix', 'issue.fixType', 'issue.confidence', 'issue.affectedUserItems', '{issue.rule_id}'] as $secondary) {
            self::assertStringContainsString($secondary, $details);
        }

        // One note for all rules instead of one per rule, and per-element remediation starts closed.
        self::assertSame(1, substr_count($detail, 'module.remotePageDetail.guidance.note'));
        self::assertStringContainsString('<details class="aqg-node-remediation">', $detail);
        self::assertStringContainsString('id="{issue.anchorId}"', $detail);
    }

    #[Test]
    public function remotePageDetailKeepsTechnicalRunDataBehindADisclosure(): void
    {
        $detail = $this->read('Templates/RemotePageDetail/Show.html');
        $metadata = $this->between($detail, '<f:section name="RemoteMetadata">', '</f:section>');
        $withoutMetadata = str_replace($metadata, '', $detail);

        self::assertStringContainsString('<details class="aqg-section aqg-tech-details" data-aqg-technical-details="true">', $metadata);
        foreach (['{remoteScan.job_id}', '{remoteScan.user_agent}', '{remotePage.screenshot_path}', 'module.remotePageDetail.scanType.frontendHttp'] as $technical) {
            self::assertStringContainsString($technical, $metadata);
            self::assertStringNotContainsString($technical, $withoutMetadata, $technical . ' belongs to the technical details only.');
        }
        self::assertStringNotContainsString('aqg-shot__caption', $detail);

        $hero = $this->between($detail, '<div class="aqg-hero__strip">', '</header>');
        self::assertStringContainsString('<f:if condition="!{remoteDetail.httpStatusIsSuccess}">', $hero, 'A routine success status is not shown in the summary.');
    }

    #[Test]
    public function remotePageDetailSaysWhichScanItShows(): void
    {
        $detail = $this->read('Templates/RemotePageDetail/Show.html');
        $comparison = $this->read('Partials/Remote/ScanComparison.html');
        $regression = $this->read('Partials/Remote/RegressionSignal.html');

        self::assertStringContainsString('module.remotePageDetail.scanShown', $detail);
        self::assertStringContainsString('<f:if condition="{newerScan}">', $detail);
        self::assertStringContainsString('{newerScan.url}', $detail);
        self::assertStringContainsString('viewedJobId: remoteScan.job_id', $detail);

        foreach ([$comparison, $regression] as $partial) {
            self::assertStringContainsString('remote.comparison.earlierScan', $partial);
            self::assertStringContainsString('remote.comparison.laterScan', $partial);
            self::assertStringContainsString('remote.comparison.shownHere', $partial);
            self::assertStringNotContainsString('remote.comparison.currentScan', $partial);
        }
    }

    #[Test]
    public function priorityFixCardsKeepOwnerFixTypeAndConfidenceInTheirDetails(): void
    {
        $panel = $this->read('Partials/Overview/RemotePanel.html');
        $item = $this->between($panel, '<article class="aqg-pfix-item">', '</article>');
        $details = $this->between($item, '<details class="aqg-rule-metadata aqg-rule-metadata--compact aqg-pfix-item__details">', '</details>');
        $visible = str_replace($details, '', $item);

        foreach (['{fix.displayTitle}', '{fix.impact}', '{fix.issuesTotal}', '{fix.affectedPagesTotal}', '{fix.shortFix}', 'viewAffectedPages'] as $primary) {
            self::assertStringContainsString($primary, $visible);
        }
        foreach (['fix.whoShouldFix', 'fix.fixType', 'fix.confidence', 'fix.wcagCriterion', 'fix.quickWin', 'fix.reason', 'fix.guidanceDetail'] as $secondary) {
            self::assertStringNotContainsString($secondary, $visible);
            self::assertStringContainsString($secondary, $details);
        }
    }

    #[Test]
    public function statementAssistantKeepsSourceAndRequiredFieldsInViewAndGroupsTheOptionalOnes(): void
    {
        $statement = $this->read('Partials/Settings/TabStatement.html');
        $optional = $this->between($statement, '<details class="aqg-statement-optional js-aqg-statement-optional">', '</details>');
        $visible = str_replace($optional, '', $statement);

        self::assertStringNotContainsString(' open', $this->openingTag($statement, '<details class="aqg-statement-optional'));
        foreach (['standard', 'measures', 'known-limitations', 'remediation', 'compatibility', 'technology', 'assessment', 'approval'] as $section) {
            self::assertStringContainsString('aqg-statement-' . $section . '-editor', $optional);
        }
        foreach (['js-aqg-statement-site', 'aqg-statement-scope', 'aqg-statement-language', 'aqg-statement-basic-editor', 'aqg-statement-status-editor', 'aqg-statement-contact-editor', 'aqg-statement-enforcement-editor', 'js-aqg-statement-generate'] as $core) {
            self::assertStringContainsString($core, $visible);
        }

        // "Required before publishing" is announced per field but explained once.
        self::assertSame(4, substr_count($visible, '<span class="aqg-required-mark" aria-hidden="true">*</span><span class="visually-hidden">'));
        self::assertStringContainsString('settings.statement.requiredNote', $visible);
        self::assertStringNotContainsString('settings.statement.result.warningStrong', $statement);
        self::assertStringContainsString('settings.statement.result.reviewNotice', $statement);
        self::assertStringContainsString('review it manually', $this->english('settings.statement.result.reviewNotice'));
        self::assertStringContainsString('role="status"', $this->openingTag($statement, '<span class="aqg-inline-status js-aqg-statement-status"'));
    }

    #[Test]
    public function licenceFormHasOnePrimarySaveAndValidateActionAndRevalidatesSavedKeys(): void
    {
        $licence = $this->read('Partials/Settings/TabLicence.html');

        self::assertSame(1, substr_count($licence, 'type="submit"'));
        self::assertStringContainsString('data-aqg-licence-submit="true"', $licence);
        self::assertStringContainsString('settings.licence.saveAndValidate', $licence);
        self::assertStringNotContainsString('aqg-card__actions', $licence, 'No second save button in the card header.');

        $revalidate = $this->between($licence, '<f:if condition="{hasLicenceKey}">', '</f:if>');
        self::assertStringContainsString('data-action="a11y-validate-licence"', $revalidate);
        self::assertStringContainsString('settings.licence.revalidate', $revalidate);
        self::assertSame(1, substr_count($licence, 'data-action="a11y-validate-licence"'));
        self::assertSame(['Save and validate', 'Speichern und prüfen'], $this->label('settings.licence.saveAndValidate'));
        self::assertSame(['Revalidate', 'Erneut prüfen'], $this->label('settings.licence.revalidate'));

        $status = $this->read('Partials/Settings/LicenceStatus.html');
        self::assertStringContainsString('<f:for each="{licenceGuidance.actions}" as="action">', $status);
        self::assertStringContainsString('data-aqg-licence-action="{action.action}"', $status);
        self::assertStringContainsString('data-aqg-licence-state="{licenceGuidance.state}"', $status);
    }

    #[Test]
    public function lockedFeaturesExplainTheirValueAndNameEveryPlanThatIncludesThem(): void
    {
        $detail = $this->read('Templates/RemotePageDetail/Show.html');

        self::assertStringContainsString('data-aqg-locked-feature="screenshot"', $detail);
        self::assertStringContainsString('data-aqg-locked-feature="record-mapping"', $detail);

        foreach (['freePreview.screenshotsLocked', 'freePreview.recordMappingLocked', 'freePreview.upsell'] as $key) {
            $english = $this->english($key);
            foreach (['trial', 'PRO', 'Agency'] as $plan) {
                self::assertStringContainsString($plan, $english, $key);
            }
            self::assertStringNotContainsString('available in PRO', $english, $key);
        }
        self::assertSame('Start a free trial', $this->english('freePreview.startTrial'));
    }

    #[Test]
    public function aiTabIsNamedForAllOfItsSuggestions(): void
    {
        self::assertSame(['AI suggestions', 'KI-Vorschläge'], $this->label('settings.tab.ai'));
        self::assertStringContainsString('link text', $this->english('settings.ai.title'));
        self::assertStringContainsString('iframe', $this->english('settings.ai.title'));
    }

    private function read(string $path): string
    {
        $content = file_get_contents(self::PRIVATE . $path);
        self::assertIsString($content, $path);

        return $content;
    }

    private function between(string $haystack, string $start, string $end): string
    {
        $from = strpos($haystack, $start);
        self::assertIsInt($from, 'Not found: ' . $start);
        $to = strpos($haystack, $end, $from + strlen($start));
        self::assertIsInt($to, 'Not found after start: ' . $end);

        return substr($haystack, $from, $to - $from + strlen($end));
    }

    private function openingTag(string $haystack, string $start): string
    {
        $from = strpos($haystack, $start);
        self::assertIsInt($from, 'Not found: ' . $start);

        return substr($haystack, $from, (int)strpos($haystack, '>', $from) - $from + 1);
    }

    private function english(string $id): string
    {
        return $this->label($id)[0];
    }

    /**
     * @return array{string, string} English source and German target
     */
    private function label(string $id): array
    {
        $labels = [];
        foreach (['locallang.xlf' => 'source', 'de.locallang.xlf' => 'target'] as $file => $element) {
            $document = new \DOMDocument();
            self::assertTrue($document->load(self::PRIVATE . 'Language/' . $file));
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
            $node = $xpath->query('//x:trans-unit[@id="' . $id . '"]/x:' . $element)?->item(0);
            self::assertNotNull($node, $file . ' has no ' . $element . ' for ' . $id);
            $labels[] = (string)$node->textContent;
        }

        return [$labels[0], $labels[1]];
    }
}
