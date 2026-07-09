<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionCandidateExtractor;

final class AiLinkTextSuggestionCandidateExtractorTest extends TestCase
{
    #[Test]
    public function singleNonDescriptiveLinkIsResolvedWithMinimalContext(): void
    {
        $result = (new AiLinkTextSuggestionCandidateExtractor())->resolve(
            '<p>Before the document <a href="/fileadmin/checklist.pdf">click here</a> after the document.</p>',
            ['context_path' => 'p > a']
        );

        self::assertIsArray($result);
        self::assertSame('click here', $result['text']);
        self::assertSame('/fileadmin/checklist.pdf', $result['href']);
        self::assertStringContainsString('Before the document', $result['surroundingText']);
        self::assertLessThanOrEqual(500, mb_strlen($result['surroundingText']));
    }


    #[Test]
    public function singleRteEmptyLinkIsResolvedWithHref(): void
    {
        $result = (new AiLinkTextSuggestionCandidateExtractor())->resolve(
            '<p>Visit our <a href="/contact"></a> page for details.</p>',
            ['rule_id' => 'rte.empty_link', 'context_path' => 'p > a']
        );

        self::assertIsArray($result);
        self::assertSame('', $result['text']);
        self::assertSame('/contact', $result['href']);
        self::assertStringContainsString('Visit our', $result['surroundingText']);
    }

    #[Test]
    public function renderedEmptyLinkSnippetIsResolved(): void
    {
        $result = (new AiLinkTextSuggestionCandidateExtractor())->resolve(
            '<a href="https://example.com/contact" class="btn"></a>',
            ['rule_id' => 'rendered.empty_link']
        );

        self::assertIsArray($result);
        self::assertSame('', $result['text']);
        self::assertSame('https://example.com/contact', $result['href']);
    }

    #[Test]
    public function multipleAmbiguousEmptyLinksReturnNull(): void
    {
        $result = (new AiLinkTextSuggestionCandidateExtractor())->resolve(
            '<p><a href="/a"></a></p><p><a href="/b"></a></p>',
            ['rule_id' => 'rte.empty_link']
        );

        self::assertNull($result);
    }

    #[Test]
    public function emptyLinkWithAccessibleNameIsIgnored(): void
    {
        $result = (new AiLinkTextSuggestionCandidateExtractor())->resolve(
            '<p><a href="/contact" aria-label="Contact us"></a></p>',
            ['rule_id' => 'rte.empty_link']
        );

        self::assertNull($result);
    }

    #[Test]
    public function multipleAmbiguousGenericLinksReturnNull(): void
    {
        $result = (new AiLinkTextSuggestionCandidateExtractor())->resolve(
            '<p><a href="/a">read more</a></p><p><a href="/b">read more</a></p>',
            []
        );

        self::assertNull($result);
    }

    #[Test]
    public function storedContextPathDisambiguatesMultipleGenericLinks(): void
    {
        $result = (new AiLinkTextSuggestionCandidateExtractor())->resolve(
            '<p><a href="/a">read more</a></p><p><a href="/b">read more</a></p>',
            ['context_path' => 'p[2] > a']
        );

        self::assertIsArray($result);
        self::assertSame('/b', $result['href']);
    }
    #[Test]
    public function oversizedRuntimeHtmlReturnsNullInsteadOfParsing(): void
    {
        $html = '<p>' . str_repeat('x', 210000) . '<a href="/contact"></a></p>';

        $result = (new AiLinkTextSuggestionCandidateExtractor())->resolve(
            $html,
            ['rule_id' => 'rte.empty_link']
        );

        self::assertNull($result);
    }

    #[Test]
    public function tooManyRuntimeLinksReturnNullInsteadOfParsingUnboundedContext(): void
    {
        $html = str_repeat('<a href="/contact"></a>', 201);

        $result = (new AiLinkTextSuggestionCandidateExtractor())->resolve(
            $html,
            ['rule_id' => 'rte.empty_link']
        );

        self::assertNull($result);
    }

}
