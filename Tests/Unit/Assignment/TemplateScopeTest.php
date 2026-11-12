<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Assignment;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Assignment\TemplateScope;

/** N-01: which findings are shared template work, and the stable key of one template element. */
final class TemplateScopeTest extends TestCase
{
    #[Test]
    public function onlyRenderedFindingsStoredAgainstThePageAreTemplateWork(): void
    {
        self::assertTrue(TemplateScope::isTemplateFinding(['source_table' => 'pages', 'rule_id' => 'rendered.img_missing_alt']));
        self::assertFalse(TemplateScope::isTemplateFinding(['source_table' => 'tt_content', 'rule_id' => 'rendered.img_missing_alt']), 'Inside a content element: editor work.');
        self::assertFalse(TemplateScope::isTemplateFinding(['source_table' => 'pages', 'rule_id' => 'structured.page_title_missing']), 'Page properties are editor work.');
    }

    #[Test]
    public function theSameTemplateElementInEveryLanguageHasOneKeyDifferentElementsDoNot(): void
    {
        $logo = TemplateScope::key('rendered.empty_link', '<a href="/"><img src="/logo.png" class="logo"></a>');
        self::assertSame($logo, TemplateScope::key('rendered.empty_link', "<a  href=\"/de/\">\n<img src=\"/logo.png\" class=\"logo\"></a>"));
        self::assertNotSame($logo, TemplateScope::key('rendered.empty_link', '<a href="/"><img src="/search.svg" class="icon"></a>'));
        self::assertNotSame($logo, TemplateScope::key('rendered.img_missing_alt', '<a href="/"><img src="/logo.png" class="logo"></a>'));
        self::assertStringStartsWith('template:rte.empty_link:', $logo);
        self::assertTrue(TemplateScope::isKey($logo));
        self::assertSame('rte.empty_link', TemplateScope::ruleOfKey($logo));
        self::assertSame('rte.empty_link', TemplateScope::ruleOfKey('rte.empty_link'));
    }
}
