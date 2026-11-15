<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\PageDetailController;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationPageSummaryService;

/**
 * AQG-300-UX-04 / FEAT-01: which AI actions Page Detail offers per occurrence. "Explain with AI" only for the rich-text
 * rules the editors can explain; "Fix with AI" only where a safe target and the queue exist; link and iframe actions
 * only with the site's AI link/iframe switch; cost hints only on actions that call a provider.
 */
final class PageDetailAiActionsTest extends TestCase
{
    private const COST = ['explain' => '<$0.008', 'fixText' => '<$0.007', 'fixImage' => '<$0.02', 'hint' => 'Estimated maximum.'];

    /** @return iterable<string,array{0:string,1:string,2:string,3:bool,4:bool,5:bool}> */
    public static function ruleProvider(): iterable
    {
        // rule, source type, fix path, text switch, explain, fix
        yield 'empty heading' => ['rte.empty_heading', 'rte', RemediationPageSummaryService::FIX_AI_TEXT, false, true, true];
        yield 'heading jump: rule-based fix, explanation only' => ['rte.heading_hierarchy_jump', 'rte', RemediationPageSummaryService::FIX_RULE_EDITOR, false, true, false];
        yield 'form label: no AI help' => ['rte.form_control_missing_label', 'rte', RemediationPageSummaryService::FIX_MANUAL, true, false, false];
        yield 'table header: no AI help' => ['rte.table_missing_header', 'rte', RemediationPageSummaryService::FIX_MANUAL, true, false, false];
        yield 'rendered output: no AI help' => ['rendered.empty_heading', 'rendered', RemediationPageSummaryService::FIX_DEVELOPER, true, false, false];
        yield 'link text without the site switch' => ['rte.empty_link', 'rte', RemediationPageSummaryService::FIX_AI_TEXT, false, false, false];
        yield 'link text with the site switch' => ['rte.empty_link', 'rte', RemediationPageSummaryService::FIX_AI_TEXT, true, true, true];
        yield 'iframe title without the site switch' => ['rte.iframe_missing_title', 'rte', RemediationPageSummaryService::FIX_AI_TEXT, false, false, false];
    }

    #[Test]
    #[DataProvider('ruleProvider')]
    public function offersOnlyTheActionsTheFindingSupports(string $rule, string $source, string $fixPath, bool $textSwitch, bool $explain, bool $fix): void
    {
        $row = PageDetailController::withAiActions($this->row($rule, $source), $this->context($fixPath), self::COST, $textSwitch);

        self::assertSame($explain, $row['remediation']['canExplain'], 'explain');
        self::assertSame($fix, $row['remediation']['canPrepare'], 'fix');
    }

    #[Test]
    public function nothingIsOfferedWithoutReadyAiEditAccessOrForAClosedOrQueuedFinding(): void
    {
        $ready = $this->context(RemediationPageSummaryService::FIX_AI_TEXT);
        $cases = [
            'AI not ready' => [$this->row('rte.empty_heading', 'rte'), ['aiReady' => false] + $ready],
            'no edit access' => [['hasEditAccess' => false] + $this->row('rte.empty_heading', 'rte'), $ready],
            'closed finding' => [['status' => 1] + $this->row('rte.empty_heading', 'rte'), $ready],
            // The record may be opened, but the field the AI action changes is read-only (TCA / TSconfig / inline).
            'read-only source field' => [['aiFieldEditable' => false] + $this->row('rte.empty_heading', 'rte'), $ready],
        ];
        foreach ($cases as $label => [$row, $context]) {
            $result = PageDetailController::withAiActions($row, $context, self::COST, true);
            self::assertFalse($result['remediation']['canExplain'], $label);
            self::assertFalse($result['remediation']['canPrepare'], $label);
        }
        $queued = $ready;
        $queued['findings'][5]['item'] = ['uid' => 1, 'status' => 'pending_review'];
        $result = PageDetailController::withAiActions($this->row('rte.empty_heading', 'rte'), $queued, self::COST, true);
        self::assertFalse($result['remediation']['canPrepare'], 'already in AI fixes');
        self::assertTrue($result['remediation']['canExplain'], 'an explanation stays available');
    }

    #[Test]
    public function aTextFindingAiFixesCanApplyHasOnlyFixWithAiNeverTheCopyOnlyCard(): void
    {
        // Observed: a Plain HTML "Click here" link got the copy-only link text card and no "Fix with AI".
        foreach (['rte.non_descriptive_link', 'rte.empty_link'] as $rule) {
            $row = PageDetailController::withAiActions(['aiLinkTextSuggestionAvailable' => true] + $this->row($rule, 'rte'), $this->context(RemediationPageSummaryService::FIX_AI_TEXT), self::COST, true);
            self::assertFalse($row['aiLinkTextSuggestionAvailable'], $rule);
            self::assertFalse($row['remediation']['inlineAi'], $rule);
            self::assertTrue($row['remediation']['canPrepare'], $rule);
        }
        // Also while an item exists: the card would be a second, paid suggestion for the same occurrence.
        $queued = $this->context(RemediationPageSummaryService::FIX_AI_TEXT);
        $queued['findings'][5]['item'] = ['uid' => 1, 'status' => 'pending_review'];
        $row = PageDetailController::withAiActions(['aiLinkTextSuggestionAvailable' => true] + $this->row('rte.empty_link', 'rte'), $queued, self::COST, true);
        self::assertFalse($row['aiLinkTextSuggestionAvailable']);
        // An image occurrence with an item gets no inline "Suggest alt text" either: no second charge, no write
        // outside the item's checks and history.
        $image = $this->context(RemediationPageSummaryService::FIX_AI_IMAGE);
        $image['findings'][5]['item'] = ['uid' => 2, 'status' => 'pending_review'];
        $row = PageDetailController::withAiActions(['aiAltSuggestionAvailable' => true, 'imageRemediationAvailable' => true] + $this->row('structured.file_reference_alt', 'structured'), $image, self::COST, true);
        self::assertFalse($row['aiAltSuggestionAvailable']);
        self::assertFalse($row['remediation']['inlineAi']);
        $free = PageDetailController::withAiActions(['aiAltSuggestionAvailable' => true, 'imageRemediationAvailable' => true] + $this->row('structured.file_reference_alt', 'structured'), $this->context(RemediationPageSummaryService::FIX_AI_IMAGE), self::COST, true);
        self::assertTrue($free['aiAltSuggestionAvailable'], 'without an item the inline image flow stays');
        // Rendered output has no record AQG may write: its suggestion card (review and copy) stays.
        $rendered = PageDetailController::withAiActions(['aiLinkTextSuggestionAvailable' => true] + $this->row('rendered.empty_link', 'rendered'), $this->context(RemediationPageSummaryService::FIX_DEVELOPER), self::COST, true);
        self::assertTrue($rendered['aiLinkTextSuggestionAvailable']);
        self::assertTrue($rendered['remediation']['inlineAi']);
        self::assertFalse($rendered['remediation']['canPrepare']);
    }

    #[Test]
    public function costHintsFollowTheActionAndAreEmptyWithoutAPrice(): void
    {
        $text = PageDetailController::withAiActions($this->row('rte.empty_heading', 'rte'), $this->context(RemediationPageSummaryService::FIX_AI_TEXT), self::COST, true);
        self::assertSame('<$0.008', $text['remediation']['explainCost']);
        self::assertSame('<$0.007', $text['remediation']['fixCost']);
        $image = PageDetailController::withAiActions($this->row('structured.file_reference_alt', 'structured'), $this->context(RemediationPageSummaryService::FIX_AI_IMAGE), self::COST, true);
        self::assertSame('<$0.02', $image['remediation']['fixCost']);
        // An alt text in rich text sends the image too: the image bound, and only with the image permission.
        $richImage = PageDetailController::withAiActions($this->row('rte.img_alt_missing', 'rte'), $this->context(RemediationPageSummaryService::FIX_AI_TEXT), self::COST, true);
        self::assertSame('<$0.02', $richImage['remediation']['fixCost']);
        self::assertTrue($richImage['remediation']['canPrepare']);
        $noImages = ['images' => false] + $this->context(RemediationPageSummaryService::FIX_AI_TEXT);
        self::assertFalse(PageDetailController::withAiActions($this->row('rte.img_alt_missing', 'rte'), $noImages, self::COST, true)['remediation']['canPrepare']);
        $none = PageDetailController::withAiActions($this->row('rte.empty_heading', 'rte'), $this->context(RemediationPageSummaryService::FIX_AI_TEXT), [], true);
        self::assertSame('', $none['remediation']['explainCost']);
        self::assertSame('', $none['remediation']['fixCost']);
    }

    /** @return array<string,mixed> */
    private function row(string $rule, string $source): array
    {
        return ['uid' => 5, 'status' => 0, 'rule_id' => $rule, 'source_type' => $source, 'hasEditAccess' => true];
    }

    /** @return array<string,mixed> */
    private function context(string $fixPath): array
    {
        return [
            'aiReady' => true,
            'images' => true,
            'canChangeOwner' => true,
            'findings' => [5 => ['fixPath' => $fixPath, 'item' => null, 'owner' => 'editor']],
        ];
    }
}
