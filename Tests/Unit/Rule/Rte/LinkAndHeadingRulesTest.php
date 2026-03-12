<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\EmptyLinkRule;
use Priebera\A11yQualityGate\Rule\Rte\HeadingHierarchyRule;
use Priebera\A11yQualityGate\Rule\Rte\NonDescriptiveLinkRule;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class LinkAndHeadingRulesTest extends TestCase
{
    #[Test]
    public function emptyLinkRuleIdIsStable(): void
    {
        self::assertSame('rte.empty_link', (new EmptyLinkRule())->getRuleId());
    }

    #[Test]
    public function emptyLinkDefaultSeverityIsCritical(): void
    {
        self::assertSame(Severity::Critical, (new EmptyLinkRule())->getDefaultSeverity());
    }

    #[Test]
    public function emptyLinkLinkWithTextPasses(): void
    {
        self::assertCount(0, (new EmptyLinkRule())->check($this->ctx('<a href="/about">About us</a>')));
    }

    #[Test]
    public function emptyLinkLinkWithAriaLabelPasses(): void
    {
        self::assertCount(0, (new EmptyLinkRule())->check($this->ctx(
            '<a href="/fb" aria-label="Visit our Facebook page"></a>'
        )));
    }

    #[Test]
    public function emptyLinkLinkWithTitlePasses(): void
    {
        self::assertCount(0, (new EmptyLinkRule())->check($this->ctx(
            '<a href="/fb" title="Facebook"></a>'
        )));
    }

    #[Test]
    public function emptyLinkLinkWithAriaLabelledbyPasses(): void
    {
        self::assertCount(0, (new EmptyLinkRule())->check($this->ctx(
            '<a href="/about" aria-labelledby="heading1"></a>'
        )));
    }

    #[Test]
    public function emptyLinkImageLinkWithMeaningfulAltPasses(): void
    {
        self::assertCount(0, (new EmptyLinkRule())->check($this->ctx(
            '<a href="/about"><img src="icon.png" alt="About us page"></a>'
        )));
    }

    #[Test]
    public function emptyLinkEmptyLinkProducesCritical(): void
    {
        $violations = (new EmptyLinkRule())->check($this->ctx('<a href="/about"></a>'));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Critical, $violations[0]->severity);
    }

    #[Test]
    public function emptyLinkWhitespaceOnlyLinkProducesCritical(): void
    {
        self::assertCount(1, (new EmptyLinkRule())->check($this->ctx('<a href="/about">   </a>')));
    }

    #[Test]
    public function emptyLinkImageLinkWithEmptyAltProducesCritical(): void
    {
        self::assertCount(1, (new EmptyLinkRule())->check($this->ctx(
            '<a href="/about"><img src="icon.png" alt=""></a>'
        )));
    }

    #[Test]
    public function emptyLinkAnchorWithoutHrefIsIgnored(): void
    {
        self::assertCount(0, (new EmptyLinkRule())->check($this->ctx('<a name="section1"></a>')));
    }

    #[Test]
    public function emptyLinkEmptyButtonIsIgnoredByThisRule(): void
    {
        self::assertCount(0, (new EmptyLinkRule())->check($this->ctx('<button></button>')));
    }

    #[Test]
    public function emptyLinkButtonWithTextPasses(): void
    {
        self::assertCount(0, (new EmptyLinkRule())->check($this->ctx('<button>Submit</button>')));
    }

    #[Test]
    public function nonDescriptiveRuleIdIsStable(): void
    {
        self::assertSame('rte.non_descriptive_link', $this->makeNonDescriptiveRule()->getRuleId());
    }

    #[Test]
    public function nonDescriptiveDefaultSeverityIsWarning(): void
    {
        self::assertSame(Severity::Warning, $this->makeNonDescriptiveRule()->getDefaultSeverity());
    }

    #[Test]
    public function nonDescriptiveDescriptiveLinkPasses(): void
    {
        self::assertCount(0, $this->makeNonDescriptiveRule()->check($this->ctx(
            '<a href="/report">Download the 2024 Annual Report</a>'
        )));
    }

    #[Test]
    public function nonDescriptiveGenericTextWithDescriptiveAriaLabelPasses(): void
    {
        self::assertCount(0, $this->makeNonDescriptiveRule()->check($this->ctx(
            '<a href="/report" aria-label="Download the 2024 Annual Report">read more</a>'
        )));
    }

    #[Test]
    public function nonDescriptiveClickHereProducesWarning(): void
    {
        $violations = $this->makeNonDescriptiveRule()->check($this->ctx(
            '<p><a href="/about">click here</a> to learn more.</p>'
        ));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function nonDescriptiveReadMoreProducesWarning(): void
    {
        self::assertCount(1, $this->makeNonDescriptiveRule()->check($this->ctx(
            '<a href="/article">read more</a>'
        )));
    }

    #[Test]
    public function nonDescriptiveCaseInsensitiveMatch(): void
    {
        self::assertCount(1, $this->makeNonDescriptiveRule()->check($this->ctx(
            '<a href="/article">Read More</a>'
        )));
    }

    #[Test]
    public function nonDescriptiveCustomPhraseProducesWarning(): void
    {
        self::assertCount(1, $this->makeNonDescriptiveRule('klikni sem')->check($this->ctx(
            '<a href="/kontakt">klikni sem</a>'
        )));
    }

    #[Test]
    public function nonDescriptiveViolationMessageContainsActualLinkText(): void
    {
        $violations = $this->makeNonDescriptiveRule()->check($this->ctx(
            '<a href="/article">read more</a>'
        ));

        self::assertStringContainsString('read more', $violations[0]->message);
    }

    #[Test]
    public function nonDescriptiveEmptyLinkIsNotFlaggedByThisRule(): void
    {
        self::assertCount(0, $this->makeNonDescriptiveRule()->check($this->ctx('<a href="/about"></a>')));
    }

    #[Test]
    public function headingRuleIdIsStable(): void
    {
        self::assertSame('rte.heading_hierarchy_jump', (new HeadingHierarchyRule())->getRuleId());
    }

    #[Test]
    public function headingDefaultSeverityIsWarning(): void
    {
        self::assertSame(Severity::Warning, (new HeadingHierarchyRule())->getDefaultSeverity());
    }

    #[Test]
    public function headingNoHeadingsProducesNoViolations(): void
    {
        self::assertCount(0, (new HeadingHierarchyRule())->check($this->ctx('<p>Just text, no headings.</p>')));
    }

    #[Test]
    public function headingSingleHeadingProducesNoViolations(): void
    {
        self::assertCount(0, (new HeadingHierarchyRule())->check($this->ctx(
            '<h2>Section title</h2><p>Content</p>'
        )));
    }

    #[Test]
    public function headingSequentialH2ToH3Passes(): void
    {
        self::assertCount(0, (new HeadingHierarchyRule())->check($this->ctx(
            '<h2>Section</h2><h3>Subsection</h3>'
        )));
    }

    #[Test]
    public function headingReturningToHigherLevelPasses(): void
    {
        self::assertCount(0, (new HeadingHierarchyRule())->check($this->ctx(
            '<h2>Section A</h2><h3>Sub A1</h3><h2>Section B</h2>'
        )));
    }

    #[Test]
    public function headingStartingWithH2WithoutH1IsNotFlagged(): void
    {
        self::assertCount(0, (new HeadingHierarchyRule())->check($this->ctx(
            '<h2>First section</h2><h3>Subsection</h3>'
        )));
    }

    #[Test]
    public function headingH2ToH4JumpProducesWarning(): void
    {
        $violations = (new HeadingHierarchyRule())->check($this->ctx(
            '<h2>Section</h2><h4>Sub-subsection</h4>'
        ));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function headingJumpMessageContainsLevels(): void
    {
        $violations = (new HeadingHierarchyRule())->check($this->ctx(
            '<h2>Section</h2><h4>Sub-subsection</h4>'
        ));

        self::assertStringContainsString('H2', $violations[0]->message);
        self::assertStringContainsString('H4', $violations[0]->message);
    }

    #[Test]
    public function headingMultipleJumpsProduceMultipleViolations(): void
    {
        $violations = (new HeadingHierarchyRule())->check($this->ctx('
            <h2>Section A</h2>
            <h4>Jump 1</h4>
            <h2>Section B</h2>
            <h5>Jump 2</h5>
        '));

        self::assertCount(2, $violations);
    }

    #[Test]
    public function headingMalformedHtmlDoesNotThrow(): void
    {
        $violations = (new HeadingHierarchyRule())->check($this->ctx('<h2>Section<h4>Unclosed content'));
        self::assertIsArray($violations);
    }

    private function makeNonDescriptiveRule(string $adminConfig = ''): NonDescriptiveLinkRule
    {
        $extensionConfiguration = $this->createMock(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturn($adminConfig);

        return new NonDescriptiveLinkRule(
            $extensionConfiguration,
            [
                'click here',
                'here',
                'read more',
                'more',
                'learn more',
                'continue',
                'continue reading',
                'details',
                'link',
                'this link',
                'this page',
                'download',
                'more info',
                'more information',
                'see more',
                'view more',
            ]
        );
    }

    private function ctx(string $html, int $langUid = 0): CheckContext
    {
        return new CheckContext(
            siteIdentifier: 'main',
            pageUid: 1,
            sourceLangUid: $langUid,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 42,
            sourceField: 'bodytext',
            content: $html,
        );
    }
}
