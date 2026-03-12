<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\DuplicateIdRule;

final class DuplicateIdRuleTest extends TestCase
{
    private DuplicateIdRule $rule;

    protected function setUp(): void
    {
        $this->rule = new DuplicateIdRule();
    }

    #[Test]
    public function ruleIdIsStable(): void
    {
        self::assertSame('rte.duplicate_id', $this->rule->getRuleId());
    }

    #[Test]
    public function defaultSeverityIsWarning(): void
    {
        self::assertSame(Severity::Warning, $this->rule->getDefaultSeverity());
    }

    #[Test]
    public function noIdsProducesNoViolations(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<p>No ids here.</p>')));
    }

    #[Test]
    public function uniqueIdsPass(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<h2 id="section-1">First</h2><h2 id="section-2">Second</h2>'
        )));
    }

    #[Test]
    public function singleIdPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<p id="intro">Intro text.</p>')));
    }

    #[Test]
    public function emptyIdAttributeIsIgnored(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<p id="">One</p><p id="">Two</p>')));
    }

    #[Test]
    public function twoDuplicateIdsProducesOneViolation(): void
    {
        $violations = $this->rule->check($this->ctx(
            '<h2 id="title">First heading</h2><p id="title">Paragraph</p>'
        ));

        self::assertCount(1, $violations);
        self::assertSame('rte.duplicate_id', $violations[0]->ruleId);
        self::assertSame(Severity::Warning, $violations[0]->severity);
        self::assertStringContainsString('title', $violations[0]->message);
    }

    #[Test]
    public function threeDuplicateIdsProducesTwoViolations(): void
    {
        self::assertCount(2, $this->rule->check($this->ctx(
            '<p id="box">A</p><p id="box">B</p><p id="box">C</p>'
        )));
    }

    #[Test]
    public function twoDifferentDuplicatePairsProducesTwoViolations(): void
    {
        self::assertCount(2, $this->rule->check($this->ctx('
            <h2 id="alpha">First</h2>
            <h2 id="alpha">Duplicate alpha</h2>
            <p id="beta">First beta</p>
            <p id="beta">Duplicate beta</p>
        ')));
    }

    #[Test]
    public function violationMessageIncludesDuplicateId(): void
    {
        $violations = $this->rule->check($this->ctx(
            '<p id="my-section">A</p><p id="my-section">B</p>'
        ));

        self::assertStringContainsString('my-section', $violations[0]->message);
    }

    #[Test]
    public function uniqueAndDuplicateMixed(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx('
            <h2 id="unique-heading">Unique</h2>
            <p id="duplicate">First</p>
            <p id="duplicate">Second</p>
            <span id="also-unique">Fine</span>
        ')));
    }

    #[Test]
    public function fingerprintDiffersForDifferentDuplicates(): void
    {
        $ctx = $this->ctx('<p id="a">X</p><p id="a">Y</p><p id="b">P</p><p id="b">Q</p>');
        $violations = $this->rule->check($ctx);

        self::assertCount(2, $violations);
        self::assertNotSame($violations[0]->fingerprint($ctx), $violations[1]->fingerprint($ctx));
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
