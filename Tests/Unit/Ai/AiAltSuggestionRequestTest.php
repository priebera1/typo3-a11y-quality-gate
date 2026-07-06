<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionRequest;

final class AiAltSuggestionRequestTest extends TestCase
{
    #[Test]
    public function missingFindingOmitsQualityAndEmptyOptionalFields(): void
    {
        $request = new AiAltSuggestionRequest(
            dataUrl: 'data:image/jpeg;base64,AAAA',
            mimeType: 'image/jpeg',
            targetLocale: 'de',
            findingType: 'missing',
            currentAlt: null,
            qualityReason: null,
            pageTitle: 'Über uns',
            contentTitle: null,
            caption: '',
            isLinked: null,
            linkPurpose: null,
        );

        self::assertSame([
            'target_locale' => 'de',
            'finding_type' => 'missing',
            'page_title' => 'Über uns',
        ], $request->contextPayload());
    }

    #[Test]
    public function linkedQualityFindingIncludesOnlyReliableServerContext(): void
    {
        $request = new AiAltSuggestionRequest(
            dataUrl: 'data:image/jpeg;base64,AAAA',
            mimeType: 'image/jpeg',
            targetLocale: 'en',
            findingType: 'quality',
            currentAlt: 'hotel.jpg',
            qualityReason: 'filename_only',
            pageTitle: 'Contact',
            contentTitle: 'Our location',
            caption: 'Headquarters in Vienna',
            isLinked: true,
            linkPurpose: 'Hotel details',
        );

        self::assertSame([
            'target_locale' => 'en',
            'finding_type' => 'quality',
            'current_alt' => 'hotel.jpg',
            'quality_reason' => 'filename_only',
            'page_title' => 'Contact',
            'content_title' => 'Our location',
            'caption' => 'Headquarters in Vienna',
            'link_purpose' => 'Hotel details',
            'is_linked' => true,
        ], $request->contextPayload());
    }

    #[Test]
    public function linkedFlagIsSentEvenWithoutReliablePurpose(): void
    {
        $request = new AiAltSuggestionRequest(
            dataUrl: 'data:image/jpeg;base64,AAAA',
            mimeType: 'image/jpeg',
            targetLocale: 'en',
            findingType: 'missing',
            isLinked: true,
            linkPurpose: null,
        );

        self::assertTrue($request->contextPayload()['is_linked']);
        self::assertArrayNotHasKey('link_purpose', $request->contextPayload());
    }
}
