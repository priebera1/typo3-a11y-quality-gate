<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\SvgMissingTitleRule;

final class SvgMissingTitleRuleTest extends TestCase
{
    private SvgMissingTitleRule $rule;

    protected function setUp(): void
    {
        $this->rule = new SvgMissingTitleRule();
    }

    #[Test]
    public function ruleIdIsStable(): void
    {
        self::assertSame('rte.svg_missing_title', $this->rule->getRuleId());
    }

    #[Test]
    public function defaultSeverityIsWarning(): void
    {
        self::assertSame(Severity::Warning, $this->rule->getDefaultSeverity());
    }

    #[Test]
    public function svgWithRolePresentationPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<svg role="presentation"><path d="M0 0"></path></svg>'
        )));
    }

    #[Test]
    public function svgWithRoleNonePasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<svg role="none"><circle cx="5" cy="5" r="5"></circle></svg>'
        )));
    }

    #[Test]
    public function svgWithTitleChildPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<svg><title>Download PDF</title><path d="M0 0"></path></svg>'
        )));
    }

    #[Test]
    public function svgWithAriaLabelPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<svg aria-label="Company logo"><path d="M0 0"></path></svg>'
        )));
    }

    #[Test]
    public function svgWithAriaLabelledByPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<p id="svg-lbl">Chart</p><svg aria-labelledby="svg-lbl"><rect></rect></svg>'
        )));
    }

    #[Test]
    public function noSvgProducesNoViolations(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<p>No SVG here.</p>')));
    }

    #[Test]
    public function svgWithNoAccessibleNameFails(): void
    {
        $violations = $this->rule->check($this->ctx(
            '<svg><path d="M0 0 L10 10"></path></svg>'
        ));

        self::assertCount(1, $violations);
        self::assertSame('rte.svg_missing_title', $violations[0]->ruleId);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function svgWithEmptyTitleFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx(
            '<svg><title></title><path d="M0 0"></path></svg>'
        )));
    }

    #[Test]
    public function svgWithEmptyAriaLabelFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx(
            '<svg aria-label=""><path d="M0 0"></path></svg>'
        )));
    }

    #[Test]
    public function svgWithAriaHiddenTrueFailsInCurrentImplementation(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx(
            '<svg aria-hidden="true"><use href="#icon-close"></use></svg>'
        )));
    }

    #[Test]
    public function multipleSvgsOnlyUnnamedOnesAreFlagged(): void
    {
        $violations = $this->rule->check($this->ctx('
            <svg aria-hidden="true"><use href="#icon-x"></use></svg>
            <svg><title>Download</title><path></path></svg>
            <svg><path d="M0 0"></path></svg>
            <svg aria-label="Logo"><circle></circle></svg>
            <svg><rect></rect></svg>
        '));

        self::assertCount(3, $violations);
    }

    #[Test]
    public function snippetContainsSvgTag(): void
    {
        $violations = $this->rule->check($this->ctx('<svg><path d="M0 0"></path></svg>'));
        self::assertStringContainsString('svg', $violations[0]->contextSnippet);
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
