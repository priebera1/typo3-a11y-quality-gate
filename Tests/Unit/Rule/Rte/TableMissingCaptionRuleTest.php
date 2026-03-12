<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\TableMissingCaptionRule;

final class TableMissingCaptionRuleTest extends TestCase
{
    private TableMissingCaptionRule $rule;

    protected function setUp(): void
    {
        $this->rule = new TableMissingCaptionRule();
    }

    #[Test]
    public function ruleIdIsStable(): void
    {
        self::assertSame('rte.table_missing_caption', $this->rule->getRuleId());
    }

    #[Test]
    public function defaultSeverityIsInfo(): void
    {
        self::assertSame(Severity::Info, $this->rule->getDefaultSeverity());
    }

    #[Test]
    public function tableWithCaptionPasses(): void
    {
        $html = '<table><caption>Q3 Results</caption>'
            . '<tr><th scope="col">Name</th></tr><tr><td>Jan</td></tr></table>';
        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function tableWithAriaLabelPasses(): void
    {
        $html = '<table aria-label="Q3 Sales Results">'
            . '<tr><th scope="col">Name</th></tr><tr><td>Jan</td></tr></table>';
        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function tableWithAriaLabelledByPasses(): void
    {
        $html = '<p id="tbl-lbl">Sales</p>'
            . '<table aria-labelledby="tbl-lbl">'
            . '<tr><th>Name</th></tr><tr><td>Jan</td></tr></table>';
        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function layoutTableWithRolePresentationIsSkipped(): void
    {
        $html = '<table role="presentation"><tr><td>Layout cell</td></tr><tr><td>Another</td></tr></table>';
        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function layoutTableWithRoleNoneIsSkipped(): void
    {
        $html = '<table role="none"><tr><td>A</td></tr><tr><td>B</td></tr></table>';
        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function singleRowTableIsSkipped(): void
    {
        $html = '<table><tr><th scope="col">Name</th><th scope="col">Age</th></tr></table>';
        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function emptyCaptionStillTriggers(): void
    {
        $html = '<table><caption></caption>'
            . '<tr><th scope="col">Name</th></tr><tr><td>Jan</td></tr></table>';
        self::assertCount(1, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function noTablesProducesNoViolations(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<p>Just text.</p>')));
    }

    #[Test]
    public function tableWithoutCaptionOrAriaLabelFails(): void
    {
        $html = '<table>'
            . '<tr><th scope="col">Name</th><th scope="col">Age</th></tr>'
            . '<tr><td>Jan</td><td>30</td></tr>'
            . '</table>';

        $violations = $this->rule->check($this->ctx($html));

        self::assertCount(1, $violations);
        self::assertSame('rte.table_missing_caption', $violations[0]->ruleId);
        self::assertSame(Severity::Info, $violations[0]->severity);
    }

    #[Test]
    public function twoTablesWithoutCaptionProducesTwoViolations(): void
    {
        $table = '<table><tr><th>A</th></tr><tr><td>B</td></tr></table>';
        self::assertCount(2, $this->rule->check($this->ctx($table . $table)));
    }

    private function ctx(string $content): CheckContext
    {
        return new CheckContext(
            siteIdentifier: 'main',
            pageUid: 1,
            sourceLangUid: 0,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 42,
            sourceField: 'bodytext',
            content: $content,
        );
    }
}
