<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\ButtonLabelMissingRule;

final class ButtonLabelMissingRuleTest extends TestCase
{
    private ButtonLabelMissingRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ButtonLabelMissingRule();
    }

    #[Test]
    public function ruleIdIsStable(): void
    {
        self::assertSame('rte.button_label_missing', $this->rule->getRuleId());
    }

    #[Test]
    public function defaultSeverityIsCritical(): void
    {
        self::assertSame(Severity::Critical, $this->rule->getDefaultSeverity());
    }

    #[Test]
    public function supportsNonEmptyStringContent(): void
    {
        self::assertTrue($this->rule->supports($this->ctx('<button>x</button>')));
    }

    #[Test]
    public function doesNotSupportEmptyContent(): void
    {
        self::assertFalse($this->rule->supports($this->ctx('')));
    }

    #[Test]
    public function buttonWithTextPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<button>Submit</button>')));
    }

    #[Test]
    public function buttonWithAriaLabelPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<button aria-label="Close dialog"><svg aria-hidden="true"></svg></button>'
        )));
    }

    #[Test]
    public function buttonWithAriaLabelledByPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<button aria-labelledby="lbl"><i class="icon"></i></button>'
        )));
    }

    #[Test]
    public function buttonWithTitlePasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<button title="Delete item"><i class="icon-trash"></i></button>'
        )));
    }

    #[Test]
    public function buttonWithImgAltPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<button><img src="icon.svg" alt="Open menu"></button>'
        )));
    }

    #[Test]
    public function buttonWithNestedSpanTextPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<button><span>Save</span></button>')));
    }

    #[Test]
    public function iconFollowedByTextPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<button><i class="icon-save"></i> Save changes</button>'
        )));
    }

    #[Test]
    public function noButtonsProducesNoViolations(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<p>Just text.</p>')));
    }

    #[Test]
    public function emptyButtonFails(): void
    {
        $violations = $this->rule->check($this->ctx('<button></button>'));

        self::assertCount(1, $violations);
        self::assertSame('rte.button_label_missing', $violations[0]->ruleId);
        self::assertSame(Severity::Critical, $violations[0]->severity);
    }

    #[Test]
    public function buttonWithWhitespaceOnlyFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx('<button>   </button>')));
    }

    #[Test]
    public function buttonWithNbspOnlyFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx('<button>&nbsp;</button>')));
    }

    #[Test]
    public function buttonWithImgNoAltFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx('<button><img src="icon.svg"></button>')));
    }

    #[Test]
    public function buttonWithImgEmptyAltFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx(
            '<button><img src="icon.svg" alt=""></button>'
        )));
    }

    #[Test]
    public function buttonWithOnlyIconTagFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx(
            '<button><i class="icon-close"></i></button>'
        )));
    }

    #[Test]
    public function buttonWithOnlySvgNoTitleFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx(
            '<button><svg aria-hidden="true"><use href="#icon-x"></use></svg></button>'
        )));
    }

    #[Test]
    public function buttonWithMultipleIconsFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx(
            '<button><i class="icon-a"></i><i class="icon-b"></i></button>'
        )));
    }

    #[Test]
    public function buttonWithRolePresentationStillFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx('<button role="presentation"></button>')));
    }

    #[Test]
    public function buttonWithRoleNoneStillFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx('<button role="none"></button>')));
    }

    #[Test]
    public function onlyUnlabelledButtonsAreReported(): void
    {
        $violations = $this->rule->check($this->ctx('
            <button>Save</button>
            <button><i class="icon-edit"></i></button>
            <button aria-label="Delete"><svg></svg></button>
            <button></button>
        '));

        self::assertCount(2, $violations);
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
