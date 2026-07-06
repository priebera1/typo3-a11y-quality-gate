<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Service\AiAltSuggestionValidator;
use Priebera\A11yQualityGate\Remediation\Contract\FileReferenceSchemaServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;

final class AiAltSuggestionValidatorTest extends TestCase
{
    #[Test]
    public function validSuggestionIsNormalizedWithoutTruncation(): void
    {
        $result = $this->subject()->validate(
            $this->createResult('suggestion', "  Hotel at dusk\nwith illuminated windows.  "),
            $this->request(),
        );

        self::assertSame('Hotel at dusk with illuminated windows.', $result->suggestion);
        self::assertSame('suggestion', $result->status);
    }

    #[Test]
    public function validNeedsReviewRequiresEmptyText(): void
    {
        $result = $this->subject()->validate(
            $this->createResult('needs_review', ''),
            $this->request(),
        );

        self::assertSame('needs_review', $result->status);
        self::assertSame('', $result->suggestion);
    }


    #[Test]
    public function linkedImageWithoutReliablePurposeIsForcedToNeedsReview(): void
    {
        $request = new AiAltSuggestionRequest(
            dataUrl: 'data:image/jpeg;base64,AAAA',
            mimeType: 'image/jpeg',
            targetLocale: 'en-GB',
            findingType: 'missing',
            isLinked: true,
            linkPurpose: null,
        );

        $result = $this->subject()->validate(
            $this->createResult('suggestion', 'Guessed link destination'),
            $request,
        );

        self::assertSame(AiAltSuggestionResult::STATUS_NEEDS_REVIEW, $result->status);
        self::assertSame('', $result->suggestion);
    }

    #[DataProvider('invalidResultProvider')]
    #[Test]
    public function invalidProviderResultsAreRejected(string $status, string $altText): void
    {
        $this->expectException(AiProviderException::class);
        $this->subject()->validate($this->createResult($status, $altText), $this->request());
    }

    public static function invalidResultProvider(): iterable
    {
        yield 'empty suggestion' => ['suggestion', ''];
        yield 'needs review with text' => ['needs_review', 'Partial output'];
        yield 'html' => ['suggestion', '<strong>Hotel</strong> at dusk'];
        yield 'control character' => ['suggestion', "Hotel\x01 at dusk"];
        yield 'filename only' => ['suggestion', 'hotel.jpg'];
        yield 'uppercase filename only' => ['suggestion', 'HOTEL.JPG'];
        yield 'jpeg filename only' => ['suggestion', 'hotel.jpeg'];
        yield 'png filename only' => ['suggestion', 'hotel.png'];
        yield 'webp filename only' => ['suggestion', 'hotel.webp'];
        yield 'forward-slash path only' => ['suggestion', 'folder/hotel.jpg'];
        yield 'backslash path only' => ['suggestion', 'folder\\hotel.jpg'];
        yield 'URL only' => ['suggestion', 'https://example.test/hotel.jpg'];
        yield 'unsupported status' => ['other', 'Hotel at dusk'];
    }

    #[DataProvider('validDescriptionProvider')]
    #[Test]
    public function normalDescriptionsContainingDotsOrExtensionTextAreAccepted(string $altText): void
    {
        $result = $this->subject()->validate(
            $this->createResult('suggestion', $altText),
            $this->request(),
        );

        self::assertSame($altText, $result->suggestion);
    }

    public static function validDescriptionProvider(): iterable
    {
        yield 'sentence ending with a period' => ['Hotel at dusk with illuminated windows.'];
        yield 'filename mentioned inside a description' => ['The file hotel.jpg shows the building illuminated at dusk.'];
        yield 'extension-like word inside a description' => ['The hotel.jpeg reference is shown beside the illuminated entrance.'];
    }

    #[Test]
    public function qualitySuggestionIdenticalToCurrentBadAltIsRejected(): void
    {
        $this->expectException(AiProviderException::class);

        $this->subject()->validate(
            $this->createResult('suggestion', 'HOTEL.JPG'),
            $this->request(currentAlt: 'hotel.jpg'),
        );
    }

    #[Test]
    public function overStorageLimitIsRejectedInsteadOfTruncated(): void
    {
        $this->expectException(AiProviderException::class);

        $this->subject(10)->validate(
            $this->createResult('suggestion', 'A description that is too long'),
            $this->request(),
        );
    }

    private function subject(int $storageLimit = 1024): AiAltSuggestionValidator
    {
        $schema = $this->createMock(FileReferenceSchemaServiceInterface::class);
        $schema->method('alternativeStorageLimit')->willReturn($storageLimit);

        return new AiAltSuggestionValidator(new ImageAltTextValidator($schema));
    }

    private function createResult(string $status, string $suggestion): AiAltSuggestionResult
    {
        return new AiAltSuggestionResult(
            status: $status,
            suggestion: $suggestion,
            provider: 'openai',
            model: 'gpt-5.4-mini',
            promptVersion: 'aqg_alt_text_v3',
        );
    }

    private function request(?string $currentAlt = 'old bad alternative'): AiAltSuggestionRequest
    {
        return new AiAltSuggestionRequest(
            dataUrl: 'data:image/jpeg;base64,AAAA',
            mimeType: 'image/jpeg',
            targetLocale: 'en',
            findingType: 'quality',
            currentAlt: $currentAlt,
            qualityReason: 'other_quality_issue',
        );
    }
}
