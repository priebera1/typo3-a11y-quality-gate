<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionContext;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionRequestBuilder;

final class AiLinkTextSuggestionRequestBuilderTest extends TestCase
{
    #[Test]
    public function requestContainsOnlyMinimalLinkContext(): void
    {
        $request = (new AiLinkTextSuggestionRequestBuilder())->build(new AiLinkTextSuggestionContext(
            findingId: 12,
            siteIdentifier: 'main',
            pageUid: 10,
            languageUid: 0,
            ruleId: 'rte.non_descriptive_link',
            sourceTable: 'tt_content',
            sourceUid: 99,
            sourceField: 'bodytext',
            currentLinkText: 'click here',
            href: '/fileadmin/checklist.pdf',
            surroundingText: 'Download the accessibility checklist. click here for the PDF.',
            targetLocale: 'en-US',
            pageTitle: 'Accessibility resources',
        ));

        self::assertSame([
            'target_locale' => 'en-US',
            'rule_id' => 'rte.non_descriptive_link',
            'current_link_text' => 'click here',
            'href' => '/fileadmin/checklist.pdf',
            'surrounding_text' => 'Download the accessibility checklist. click here for the PDF.',
            'page_title' => 'Accessibility resources',
        ], $request->contextPayload());
    }


    #[Test]
    public function emptyLinkRequestKeepsRuleAndEmptyCurrentText(): void
    {
        $request = (new AiLinkTextSuggestionRequestBuilder())->build(new AiLinkTextSuggestionContext(
            findingId: 13,
            siteIdentifier: 'main',
            pageUid: 10,
            languageUid: 0,
            ruleId: 'rte.empty_link',
            sourceTable: 'tt_content',
            sourceUid: 99,
            sourceField: 'bodytext',
            currentLinkText: '',
            href: '/contact',
            surroundingText: 'Visit our contact page.',
            targetLocale: 'en-US',
            pageTitle: 'Contact',
        ));

        self::assertSame('rte.empty_link', $request->ruleId);
        self::assertSame('', $request->currentLinkText);
        self::assertSame('/contact', $request->href);
        self::assertSame('', $request->contextPayload()['current_link_text']);
    }

    #[Test]
    public function structuredResponseSchemaIsStrict(): void
    {
        $format = AiLinkTextSuggestionRequestBuilder::structuredOutputFormat();

        self::assertSame('json_schema', $format['type']);
        self::assertTrue($format['strict']);
        self::assertFalse($format['schema']['additionalProperties']);
        self::assertSame(
            ['status', 'suggested_link_text', 'reason', 'needs_review'],
            $format['schema']['required'],
        );
    }
}
