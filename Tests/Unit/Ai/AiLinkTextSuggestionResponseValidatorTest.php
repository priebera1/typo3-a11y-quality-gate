<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiLinkTextSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionResponseValidator;

final class AiLinkTextSuggestionResponseValidatorTest extends TestCase
{
    #[Test]
    public function validSuggestionIsAcceptedAndMarkedReviewOnly(): void
    {
        $result = (new AiLinkTextSuggestionResponseValidator())->validate(
            new AiLinkTextSuggestionResult('suggestion', 'Download the accessibility checklist PDF', 'Clearer destination.', true),
            $this->request(),
        );

        self::assertSame('suggestion', $result->status);
        self::assertSame('Download the accessibility checklist PDF', $result->suggestedLinkText);
        self::assertTrue($result->needsReview);
    }

    #[DataProvider('unsafeSuggestionProvider')]
    #[Test]
    public function unsafeSuggestionIsRejected(string $suggestion): void
    {
        $this->expectException(AiProviderException::class);

        (new AiLinkTextSuggestionResponseValidator())->validate(
            new AiLinkTextSuggestionResult('suggestion', $suggestion, 'HTML is not allowed.', true),
            $this->request(),
        );
    }

    #[Test]
    public function htmlReasonIsRejected(): void
    {
        $this->expectException(AiProviderException::class);

        (new AiLinkTextSuggestionResponseValidator())->validate(
            new AiLinkTextSuggestionResult('suggestion', 'Download the accessibility checklist PDF', '<strong>HTML reason</strong>', true),
            $this->request(),
        );
    }

    #[Test]
    public function sameAsOriginalSuggestionIsRejected(): void
    {
        $this->expectException(AiProviderException::class);

        (new AiLinkTextSuggestionResponseValidator())->validate(
            new AiLinkTextSuggestionResult('suggestion', 'Click here', 'Same as original.', true),
            $this->request(),
        );
    }

    #[Test]
    public function unsupportedContextReturnsEmptySuggestion(): void
    {
        $result = (new AiLinkTextSuggestionResponseValidator())->validate(
            new AiLinkTextSuggestionResult('unsupported_context', 'ignored', 'The link is ambiguous.', true),
            $this->request(),
        );

        self::assertSame('unsupported_context', $result->status);
        self::assertSame('', $result->suggestedLinkText);
    }

    /** @return array<string,array{0:string}> */
    public static function unsafeSuggestionProvider(): array
    {
        return [
            'anchor tag' => ['<a href="https://example.com">Download report</a>'],
            'strong tag' => ['<strong>Download report</strong>'],
            'inline tag' => ['Download <em>report</em>'],
            'raw opening angle' => ['Download < report'],
            'raw closing angle' => ['Download report >'],
            'encoded tag' => ['&lt;strong&gt;Download report&lt;/strong&gt;'],
            'multiline text' => ["Download
report"],
            'control character' => ["Download\x01report"],
            'raw URL' => ['https://example.com/contact'],
            'generic link' => ['link'],
            'generic open' => ['open'],
            'generic go' => ['go'],
        ];
    }

    private function request(): AiLinkTextSuggestionRequest
    {
        return new AiLinkTextSuggestionRequest(
            targetLocale: 'en',
            ruleId: 'rte.non_descriptive_link',
            currentLinkText: 'click here',
            href: '/fileadmin/checklist.pdf',
            surroundingText: 'Download the accessibility checklist. click here for the PDF.',
            pageTitle: 'Accessibility resources',
        );
    }
}
