<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\EmptyHeadingRule;
use Priebera\A11yQualityGate\Rule\Rte\TableMissingHeaderRule;

class TableAndHeadingRulesTest extends TestCase
{
    // =========================================================================
    // TableMissingHeaderRule
    // =========================================================================

    #[Test]
    public function table_ruleIdIsStable(): void
    {
        self::assertSame('rte.table_missing_header', (new TableMissingHeaderRule())->getRuleId());
    }

    #[Test]
    public function table_defaultSeverityIsWarning(): void
    {
        self::assertSame(Severity::Warning, (new TableMissingHeaderRule())->getDefaultSeverity());
    }

    // PASS

    #[Test]
    public function table_tableWithThInTheadPasses(): void
    {
        $html = '
            <table>
                <thead><tr><th>Name</th><th>Age</th></tr></thead>
                <tbody><tr><td>Alice</td><td>30</td></tr></tbody>
            </table>
        ';
        self::assertCount(0, (new TableMissingHeaderRule())->check($this->ctx($html)));
    }

    #[Test]
    public function table_tableWithThInTbodyPasses(): void
    {
        // th in tbody (row headers) is valid
        $html = '
            <table>
                <tr><th>Country</th><td>Slovakia</td></tr>
                <tr><th>Capital</th><td>Bratislava</td></tr>
            </table>
        ';
        self::assertCount(0, (new TableMissingHeaderRule())->check($this->ctx($html)));
    }

    #[Test]
    public function table_presentationTableIsSkipped(): void
    {
        $html = '
            <table role="presentation">
                <tr><td>Left column</td><td>Right column</td></tr>
                <tr><td>More</td><td>Content</td></tr>
            </table>
        ';
        self::assertCount(0, (new TableMissingHeaderRule())->check($this->ctx($html)));
    }

    #[Test]
    public function table_singleRowTableIsSkipped(): void
    {
        // Single row cannot meaningfully have headers
        $html = '<table><tr><td>A</td><td>B</td></tr></table>';
        self::assertCount(0, (new TableMissingHeaderRule())->check($this->ctx($html)));
    }

    #[Test]
    public function table_noTablesProducesNoViolations(): void
    {
        self::assertCount(0, (new TableMissingHeaderRule())->check($this->ctx('<p>Text</p>')));
    }

    // FAIL

    #[Test]
    public function table_multiRowTableWithoutThProducesWarning(): void
    {
        $html = '
            <table>
                <tr><td>Name</td><td>Age</td></tr>
                <tr><td>Alice</td><td>30</td></tr>
                <tr><td>Bob</td><td>25</td></tr>
            </table>
        ';
        $violations = (new TableMissingHeaderRule())->check($this->ctx($html));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function table_multipleBadTablesProduceMultipleViolations(): void
    {
        $html = '
            <table>
                <tr><td>A</td></tr><tr><td>B</td></tr>
            </table>
            <p>Separator</p>
            <table>
                <tr><td>C</td></tr><tr><td>D</td></tr>
            </table>
        ';
        self::assertCount(2, (new TableMissingHeaderRule())->check($this->ctx($html)));
    }

    #[Test]
    public function table_fingerprintsForTwoTablesAreDifferent(): void
    {
        $html = '
            <table><tr><td>A</td></tr><tr><td>B</td></tr></table>
            <table><tr><td>C</td></tr><tr><td>D</td></tr></table>
        ';
        $ctx        = $this->ctx($html);
        $violations = (new TableMissingHeaderRule())->check($ctx);

        self::assertCount(2, $violations);
        self::assertNotSame(
            $violations[0]->fingerprint($ctx),
            $violations[1]->fingerprint($ctx)
        );
    }

    // =========================================================================
    // EmptyHeadingRule
    // =========================================================================

    #[Test]
    public function heading_ruleIdIsStable(): void
    {
        self::assertSame('rte.empty_heading', (new EmptyHeadingRule())->getRuleId());
    }

    #[Test]
    public function heading_defaultSeverityIsWarning(): void
    {
        self::assertSame(Severity::Warning, (new EmptyHeadingRule())->getDefaultSeverity());
    }

    // PASS

    #[Test]
    public function heading_headingWithTextPasses(): void
    {
        $html = '<h2>Our services</h2>';
        self::assertCount(0, (new EmptyHeadingRule())->check($this->ctx($html)));
    }

    #[Test]
    public function heading_headingWithAriaLabelPasses(): void
    {
        $html = '<h2 aria-label="Services section"></h2>';
        self::assertCount(0, (new EmptyHeadingRule())->check($this->ctx($html)));
    }

    #[Test]
    public function heading_headingWithImageWithAltPasses(): void
    {
        $html = '<h2><img src="logo.png" alt="Company logo"></h2>';
        self::assertCount(0, (new EmptyHeadingRule())->check($this->ctx($html)));
    }

    #[Test]
    public function heading_noHeadingsProducesNoViolations(): void
    {
        self::assertCount(0, (new EmptyHeadingRule())->check($this->ctx('<p>Text only</p>')));
    }

    // FAIL

    #[Test]
    public function heading_completelyEmptyHeadingProducesWarning(): void
    {
        $violations = (new EmptyHeadingRule())->check($this->ctx('<h2></h2>'));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function heading_whiteSpaceOnlyHeadingProducesWarning(): void
    {
        $violations = (new EmptyHeadingRule())->check($this->ctx('<h3>   </h3>'));
        self::assertCount(1, $violations);
    }

    #[Test]
    public function heading_nbspOnlyHeadingProducesWarning(): void
    {
        // &nbsp; is NOT visible meaningful content
        $violations = (new EmptyHeadingRule())->check($this->ctx('<h2>&nbsp;</h2>'));
        self::assertCount(1, $violations);
    }

    #[Test]
    public function heading_headingWithImageWithEmptyAltProducesWarning(): void
    {
        // Image with empty alt provides no accessible name to the heading
        $html = '<h2><img src="deco.png" alt=""></h2>';
        self::assertCount(1, (new EmptyHeadingRule())->check($this->ctx($html)));
    }

    #[Test]
    public function heading_violationMessageContainsTagName(): void
    {
        $violations = (new EmptyHeadingRule())->check($this->ctx('<h3></h3>'));
        self::assertStringContainsString('h3', $violations[0]->message);
    }

    #[Test]
    public function heading_multipleEmptyHeadingsProduceMultipleViolations(): void
    {
        $html = '<h2></h2><p>Content</p><h3></h3><h4>This one is fine</h4>';
        self::assertCount(2, (new EmptyHeadingRule())->check($this->ctx($html)));
    }

    #[Test]
    public function heading_allLevelsH1ToH6AreChecked(): void
    {
        $html = '<h1></h1><h2></h2><h3></h3><h4></h4><h5></h5><h6></h6>';
        self::assertCount(6, (new EmptyHeadingRule())->check($this->ctx($html)));
    }

    #[Test]
    public function heading_malformedHtmlDoesNotThrow(): void
    {
        $html = '<h2>Unclosed <b>bold<h3></h3>';
        self::assertIsArray((new EmptyHeadingRule())->check($this->ctx($html)));
    }

    // =========================================================================
    // Shared helper
    // =========================================================================

    private function ctx(string $html): CheckContext
    {
        return new CheckContext(
            siteIdentifier: 'main',
            pageUid:        1,
            sourceLangUid:  0,
            sourceTable:    'tt_content',
            sourceUid:      42,
            sourceField:    'bodytext',
            content:        $html,
        );
    }
}
