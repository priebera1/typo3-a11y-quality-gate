<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Editor;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTargetResolver;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\ButtonLabelMissingRule;
use Priebera\A11yQualityGate\Rule\Rte\HeadingHierarchyRule;
use Priebera\A11yQualityGate\Rule\Rte\IframeMissingTitleRule;
use Priebera\A11yQualityGate\Rule\Rte\ImageInLinkMissingAltRule;
use Priebera\A11yQualityGate\Rule\Rte\ImgAltMissingRule;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class EditorFindingTargetResolverTest extends TestCase
{
    #[Test]
    public function identicalDuplicatesGetTheirOwnOccurrence(): void
    {
        $html = '<p>A <img src="/a.png"></p><p>B <img src="/a.png"></p><p>C <img src="/a.png" alt="x"></p>';
        $targets = $this->targets(new ImgAltMissingRule(), $html);

        self::assertCount(2, $targets);
        self::assertSame([0, 1], array_map(static fn ($t) => $t->occurrence, $targets));
        self::assertSame([3, 3], array_map(static fn ($t) => $t->total, $targets));
        self::assertSame(['src' => '/a.png'], $targets[1]->attributes);
        self::assertSame(EditorAiRuleCatalog::OPERATION_ATTRIBUTE, $targets[1]->operation);
        self::assertSame('alt', $targets[1]->attribute);
        self::assertTrue($targets[1]->isActionable());
    }

    #[Test]
    public function aViolationWhoseSnippetDoesNotMatchItsElementIsNotTargeted(): void
    {
        $html = '<p><img src="/a.png"></p>';
        $violation = new RuleViolation('rte.img_alt_missing', (new ImgAltMissingRule())->getDefaultSeverity(), 'm', 'h', '<img src="/b.png">', 'p > img');

        self::assertNull((new EditorFindingTargetResolver())->resolve($html, $violation));
    }

    #[Test]
    public function anAmbiguousPathIsNotTargeted(): void
    {
        $html = '<p><img src="/a.png"><img src="/a.png"></p>';
        // buildXPath() writes an index for repeated siblings; a path without it cannot name one element.
        $violation = new RuleViolation('rte.img_alt_missing', (new ImgAltMissingRule())->getDefaultSeverity(), 'm', 'h', '<img src="/a.png">', 'p > img');

        self::assertNull((new EditorFindingTargetResolver())->resolve($html, $violation));
    }

    #[Test]
    public function iframeFindingsFromTheMarkupScanAreTargetedWhenTheParseAgrees(): void
    {
        $html = '<p>Map</p><iframe src="https://maps.example/a" title="A"></iframe><iframe src="https://maps.example/b"></iframe>';
        $targets = $this->targets(new IframeMissingTitleRule(), $html);

        self::assertCount(1, $targets);
        self::assertSame(1, $targets[0]->occurrence);
        self::assertSame('https://maps.example/b', $targets[0]->attributes['src']);
        self::assertSame('title', $targets[0]->attribute);
    }

    #[Test]
    public function rawScanIframeFindingsMapByTheirSourcePosition(): void
    {
        $html = '<p><iframe src="https://v.example/a"></iframe></p><iframe src="https://v.example/a"></iframe><iframe src="https://v.example/a"></iframe>';
        $targets = $this->targets(new IframeMissingTitleRule(), $html);

        self::assertSame([0, 1, 2], array_map(static fn ($t) => $t->occurrence, $targets));
    }

    #[Test]
    public function anImageThatIsTheOnlyContentOfALinkIsTheTarget(): void
    {
        $targets = $this->targets(new ImageInLinkMissingAltRule(), '<p><a href="/prices"><img src="/p.png"></a></p>');

        self::assertCount(1, $targets);
        self::assertSame('img', $targets[0]->tag);
        self::assertSame('alt', $targets[0]->attribute);
    }

    #[Test]
    public function aButtonWithInnerMarkupGetsAnAttributeTarget(): void
    {
        $targets = $this->targets(new ButtonLabelMissingRule(), '<form><button type="submit"><svg aria-hidden="true"></svg></button></form>');

        self::assertCount(1, $targets);
        self::assertTrue($targets[0]->hasChildElements);
        self::assertSame('aria-label', $targets[0]->attribute);
        self::assertTrue($targets[0]->isActionable());
    }

    #[Test]
    public function templatePlaceholdersAndNestedTextMarkupStayManual(): void
    {
        $resolver = new EditorFindingTargetResolver();
        $placeholder = $this->targets(new ImgAltMissingRule(), '<p><img src="/x.png" title="{file.title}"></p>');
        self::assertSame('template_placeholder', $placeholder[0]->unavailableReason);

        $nested = $resolver->resolve(
            '<p><a href="/k"><strong>more</strong></a></p>',
            new RuleViolation('rte.non_descriptive_link', (new ImgAltMissingRule())->getDefaultSeverity(), 'm', 'h', '<a href="/k"><strong>more</strong></a>', 'p > a'),
        );
        self::assertSame('nested_markup', $nested?->unavailableReason);
        self::assertSame('more', $nested?->text);
    }

    #[Test]
    public function aHeadingJumpGetsTheRuleBasedLevel(): void
    {
        $targets = $this->targets(new HeadingHierarchyRule(), '<h2>A</h2><p>x</p><h4>B</h4>');

        self::assertCount(1, $targets);
        self::assertSame(4, $targets[0]->headingLevel);
        self::assertSame(3, $targets[0]->targetHeadingLevel);
        self::assertFalse(EditorAiRuleCatalog::hasAiFix($targets[0]->ruleId));
    }

    #[Test]
    public function theTargetContractNeverExposesTheDomElement(): void
    {
        $targets = $this->targets(new ImgAltMissingRule(), '<p><img src="/a.png"></p>');
        $json = json_encode($targets[0]->toArray(), JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('DOMElement', $json);
        self::assertSame(['ruleId', 'kind', 'operation', 'attribute', 'tag', 'occurrence', 'total', 'contextPath', 'element', 'currentValue', 'headingLevel', 'targetHeadingLevel', 'unavailableReason'], array_keys($targets[0]->toArray()));
    }

    /** @return list<\Priebera\A11yQualityGate\Ai\Editor\EditorFindingTarget> */
    private function targets(RuleInterface $rule, string $html): array
    {
        $context = new CheckContext('main', 1, 0, 'tt_content', 1, 'bodytext', $html);
        $resolver = new EditorFindingTargetResolver();
        $targets = [];
        foreach ($rule->check($context) as $violation) {
            $target = $resolver->resolve($html, $violation);
            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }
}
