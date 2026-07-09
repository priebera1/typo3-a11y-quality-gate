<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionContext;
use Priebera\A11yQualityGate\Ai\Service\AiIframeTitleSuggestionRequestBuilder;

final class AiIframeTitleSuggestionRequestBuilderTest extends TestCase
{
    #[Test]
    public function requestContainsOnlyMinimalIframeContext(): void
    {
        $request = (new AiIframeTitleSuggestionRequestBuilder())->build(new AiIframeTitleSuggestionContext(
            findingId: 12,
            siteIdentifier: 'main',
            pageUid: 10,
            languageUid: 0,
            ruleId: 'rendered.iframe_missing_title',
            iframeSrc: 'https://www.youtube.com/embed/abc123',
            contextPath: 'main > iframe',
            cssSelector: 'main > iframe:nth-of-type(1)',
            frontendUrl: 'https://example.test/products',
            targetLocale: 'en-US',
            pageTitle: 'Product video',
        ));

        self::assertSame([
            'target_locale' => 'en-US',
            'rule_id' => 'rendered.iframe_missing_title',
            'iframe_src' => 'https://www.youtube.com/embed/abc123',
            'context_path' => 'main > iframe',
            'css_selector' => 'main > iframe:nth-of-type(1)',
            'frontend_url' => 'https://example.test/products',
            'page_title' => 'Product video',
        ], $request->contextPayload());
    }

    #[Test]
    public function structuredResponseSchemaIsStrict(): void
    {
        $format = AiIframeTitleSuggestionRequestBuilder::structuredOutputFormat();

        self::assertSame('json_schema', $format['type']);
        self::assertTrue($format['strict']);
        self::assertFalse($format['schema']['additionalProperties']);
        self::assertSame(
            ['status', 'suggested_iframe_title', 'reason', 'needs_review'],
            $format['schema']['required'],
        );
    }
}
