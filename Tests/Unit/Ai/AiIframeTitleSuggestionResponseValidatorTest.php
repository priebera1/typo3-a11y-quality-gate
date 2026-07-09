<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiIframeTitleSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Service\AiIframeTitleSuggestionResponseValidator;

final class AiIframeTitleSuggestionResponseValidatorTest extends TestCase
{
    #[Test]
    public function validSuggestionIsAcceptedAndMarkedReviewOnly(): void
    {
        $result = (new AiIframeTitleSuggestionResponseValidator())->validate(
            new AiIframeTitleSuggestionResult('suggestion', 'Product introduction video', 'The iframe source indicates an embedded video.', true),
            $this->request(),
        );

        self::assertSame('suggestion', $result->status);
        self::assertSame('Product introduction video', $result->suggestedIframeTitle);
        self::assertTrue($result->needsReview);
    }

    #[DataProvider('unsafeTitleProvider')]
    #[Test]
    public function unsafeTitleIsRejected(string $title): void
    {
        $this->expectException(AiProviderException::class);

        (new AiIframeTitleSuggestionResponseValidator())->validate(
            new AiIframeTitleSuggestionResult('suggestion', $title, 'HTML is not allowed.', true),
            $this->request(),
        );
    }

    #[Test]
    public function htmlReasonIsRejected(): void
    {
        $this->expectException(AiProviderException::class);

        (new AiIframeTitleSuggestionResponseValidator())->validate(
            new AiIframeTitleSuggestionResult('suggestion', 'Product introduction video', '<strong>HTML reason</strong>', true),
            $this->request(),
        );
    }

    #[DataProvider('genericTitleProvider')]
    #[Test]
    public function genericTitleIsRejected(string $title): void
    {
        $this->expectException(AiProviderException::class);

        (new AiIframeTitleSuggestionResponseValidator())->validate(
            new AiIframeTitleSuggestionResult('suggestion', $title, 'Too generic.', true),
            $this->request(),
        );
    }

    #[Test]
    public function suggestionWithoutTitleIsRejected(): void
    {
        $this->expectException(AiProviderException::class);

        (new AiIframeTitleSuggestionResponseValidator())->validate(
            new AiIframeTitleSuggestionResult('suggestion', '', 'No title was returned.', true),
            $this->request(),
        );
    }

    #[Test]
    public function needsReviewWithoutTitleIsAcceptedAsNoSuggestionState(): void
    {
        $result = (new AiIframeTitleSuggestionResponseValidator())->validate(
            new AiIframeTitleSuggestionResult('needs_review', '', 'The iframe source is a generic placeholder URL.', true),
            $this->request(),
        );

        self::assertSame('needs_review', $result->status);
        self::assertSame('', $result->suggestedIframeTitle);
        self::assertSame('The iframe source is a generic placeholder URL.', $result->reason);
        self::assertTrue($result->needsReview);
    }

    #[Test]
    public function unsupportedContextReturnsEmptySuggestion(): void
    {
        $result = (new AiIframeTitleSuggestionResponseValidator())->validate(
            new AiIframeTitleSuggestionResult('unsupported_context', 'ignored', 'The iframe source is ambiguous.', true),
            $this->request(),
        );

        self::assertSame('unsupported_context', $result->status);
        self::assertSame('', $result->suggestedIframeTitle);
    }

    /** @return array<string,array{0:string}> */
    public static function unsafeTitleProvider(): array
    {
        return [
            'anchor tag' => ['<a href="https://example.com">Map</a>'],
            'strong tag' => ['<strong>Map</strong>'],
            'inline tag' => ['Product <em>video</em>'],
            'raw opening angle' => ['Product < video'],
            'raw closing angle' => ['Product video >'],
            'encoded tag' => ['&lt;strong&gt;Product video&lt;/strong&gt;'],
            'multiline text' => ["Product\nvideo"],
            'control character' => ["Product\x01video"],
            'raw url' => ['https://www.youtube.com/embed/abc123'],
        ];
    }

    /** @return array<string,array{0:string}> */
    public static function genericTitleProvider(): array
    {
        return [
            'iframe' => ['Iframe'],
            'embedded content' => ['Embedded content'],
            'external content' => ['External content'],
        ];
    }

    private function request(): AiIframeTitleSuggestionRequest
    {
        return new AiIframeTitleSuggestionRequest(
            targetLocale: 'en',
            ruleId: 'rendered.iframe_missing_title',
            iframeSrc: 'https://www.youtube.com/embed/abc123',
            contextPath: 'main > iframe',
            cssSelector: 'main > iframe:nth-of-type(1)',
            frontendUrl: 'https://example.test/products',
            pageTitle: 'Product video',
        );
    }
}
