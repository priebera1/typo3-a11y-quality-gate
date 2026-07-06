<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiFindingMetadataResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Remediation\ImageRemediationValidationException;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;

final class AiFindingMetadataResolverTest extends TestCase
{
    #[Test]
    public function missingFindingDoesNotExposeCurrentAltOrReason(): void
    {
        $metadata = (new AiFindingMetadataResolver())->resolve($this->context(
            ruleId: 'structured.file_reference_alt',
            alternative: '',
        ));

        self::assertSame('missing', $metadata->findingType);
        self::assertNull($metadata->currentAlt);
        self::assertNull($metadata->qualityReason);
    }


    #[Test]
    public function missingFindingWithCurrentAltIsStale(): void
    {
        $this->expectException(StaleImageFindingException::class);
        $this->expectExceptionCode(1771002504);

        (new AiFindingMetadataResolver())->resolve($this->context(
            ruleId: 'structured.file_reference_alt',
            alternative: 'Already fixed',
        ));
    }

    #[Test]
    public function decorativeMissingFindingIsStale(): void
    {
        $this->expectException(StaleImageFindingException::class);
        $this->expectExceptionCode(1771002504);

        $context = $this->context(
            ruleId: 'structured.file_reference_alt',
            alternative: '',
            decorative: true,
        );

        (new AiFindingMetadataResolver())->resolve($context);
    }

    #[Test]
    public function qualityFindingReadsCurrentAltFromFileReferenceAndMapsReason(): void
    {
        $metadata = (new AiFindingMetadataResolver())->resolve($this->context(
            ruleId: 'structured.file_reference_alt_quality',
            alternative: 'hotel.jpg',
            contextSnippet: 'sys_file_reference uid:44, file: hotel.jpg, alt: "hotel.jpg"',
        ));

        self::assertSame('quality', $metadata->findingType);
        self::assertSame('hotel.jpg', $metadata->currentAlt);
        self::assertSame('img_alt_is_filename', $metadata->qualityReason);
    }

    #[DataProvider('filenameAlternativeProvider')]
    #[Test]
    public function filenameQualityAlternativesMapToSpecificReason(string $alternative): void
    {
        $metadata = (new AiFindingMetadataResolver())->resolve($this->context(
            ruleId: 'structured.file_reference_alt_quality',
            alternative: $alternative,
            contextSnippet: 'sys_file_reference uid:44, alt: "' . $alternative . '"',
        ));

        self::assertSame('img_alt_is_filename', $metadata->qualityReason);
    }

    public static function filenameAlternativeProvider(): iterable
    {
        yield 'lowercase JPEG' => ['hotel.jpg'];
        yield 'uppercase JPEG' => ['HOTEL.JPG'];
        yield 'JPEG long extension' => ['hotel.jpeg'];
        yield 'PNG' => ['hotel.png'];
        yield 'WebP' => ['hotel.webp'];
        yield 'forward-slash path' => ['folder/hotel.jpg'];
        yield 'backslash path' => ['folder\\hotel.jpg'];
        yield 'URL' => ['https://example.com/hotel.jpg'];
    }

    #[Test]
    public function descriptiveAltContainingFilenameTextUsesFallbackReason(): void
    {
        $alternative = 'The file hotel.jpg shows the building illuminated at dusk.';
        $metadata = (new AiFindingMetadataResolver())->resolve($this->context(
            ruleId: 'structured.file_reference_alt_quality',
            alternative: $alternative,
            contextSnippet: 'sys_file_reference uid:44, alt: "' . $alternative . '"',
        ));

        self::assertSame('img_alt_quality_other', $metadata->qualityReason);
    }

    #[Test]
    public function changedQualityAltIsRejectedAsStale(): void
    {
        $this->expectException(StaleImageFindingException::class);
        $this->expectExceptionCode(1771002503);

        (new AiFindingMetadataResolver())->resolve($this->context(
            ruleId: 'structured.file_reference_alt_quality',
            alternative: 'A newly reviewed alternative',
            contextSnippet: 'sys_file_reference uid:44, file: hotel.jpg, alt: "hotel.jpg"',
        ));
    }

    #[Test]
    public function qualityFindingWithoutCurrentReferenceAltIsRejectedAsStale(): void
    {
        $this->expectException(StaleImageFindingException::class);
        $this->expectExceptionCode(1771002502);

        (new AiFindingMetadataResolver())->resolve($this->context(
            ruleId: 'structured.file_reference_alt_quality',
            alternative: '',
        ));
    }

    #[Test]
    public function unsupportedRuleIsRejectedServerSide(): void
    {
        $this->expectException(ImageRemediationValidationException::class);

        (new AiFindingMetadataResolver())->resolve($this->context(
            ruleId: 'rte.img_alt_is_filename',
            alternative: 'hotel.jpg',
        ));
    }

    private function context(
        string $ruleId,
        string $alternative,
        string $contextSnippet = '',
        bool $decorative = false,
    ): ImageFindingContext {
        return new ImageFindingContext(
            issue: [
                'rule_id' => $ruleId,
                'message' => $ruleId === 'structured.file_reference_alt_quality'
                    ? 'Image alternative text quality issue in media record.'
                    : 'Missing image alternative text.',
                'hint' => '',
                'context_snippet' => $contextSnippet,
            ],
            fileReference: [
                'alternative' => $alternative,
                'tx_a11y_is_decorative' => $decorative ? 1 : 0,
            ],
            siteIdentifier: 'test',
            pageUid: 10,
            languageUid: 0,
            sourceTable: 'tt_content',
            sourceUid: 42,
            sourceField: 'image',
            fileReferenceUid: 44,
            fileUid: 55,
            fingerprint: 'abc',
            issueTimestamp: 100,
            fileReferenceTimestamp: 200,
        );
    }
}
