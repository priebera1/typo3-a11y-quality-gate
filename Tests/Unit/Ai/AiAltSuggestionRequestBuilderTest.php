<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Dto\AiImagePayload;
use Priebera\A11yQualityGate\Ai\Service\AiAltSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiContextSanitizer;
use Priebera\A11yQualityGate\Ai\Service\AiFindingMetadataResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\Locale;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\Site\SiteFinder;

final class AiAltSuggestionRequestBuilderTest extends TestCase
{
    #[Test]
    public function targetLocaleAndMissingTypeAreDerivedOnServer(): void
    {
        $request = $this->subject(languageId: 2, localeName: 'de-AT', languageCode: 'de')->build(
            $this->context('structured.file_reference_alt', 2, 2, ''),
            new AiImagePayload('data:image/jpeg;base64,AAAA', 'image/jpeg'),
        );

        self::assertSame('de-AT', $request->targetLocale);
        self::assertSame('missing', $request->findingType);
        self::assertNull($request->currentAlt);
        self::assertNull($request->qualityReason);
        self::assertSame([
            'target_locale' => 'de-AT',
            'finding_type' => 'missing',
        ], $request->contextPayload());
    }

    #[DataProvider('localeProvider')]
    #[Test]
    public function targetLocalePreservesNormalizedBcp47Locale(string $locale, string $expected): void
    {
        $request = $this->subject(languageId: 2, localeName: $locale, languageCode: 'de')->build(
            $this->context('structured.file_reference_alt', 2, 2, ''),
            new AiImagePayload('data:image/jpeg;base64,AAAA', 'image/jpeg'),
        );

        self::assertSame($expected, $request->targetLocale);
    }

    public static function localeProvider(): iterable
    {
        yield 'hyphenated Austrian German' => ['de-AT', 'de-AT'];
        yield 'underscored German locale' => ['de_DE', 'de-DE'];
        yield 'British English locale' => ['en-GB', 'en-GB'];
        yield 'US English locale' => ['en-US', 'en-US'];
        yield 'Slovak locale' => ['sk-SK', 'sk-SK'];
        yield 'script and region' => ['zh_hant_tw', 'zh-Hant-TW'];
    }

    #[Test]
    public function invalidFullLocaleFallsBackToPrimaryLanguage(): void
    {
        $request = $this->subject(languageId: 2, localeName: 'not a locale', languageCode: 'de-DE')->build(
            $this->context('structured.file_reference_alt', 2, 2, ''),
            new AiImagePayload('data:image/jpeg;base64,AAAA', 'image/jpeg'),
        );

        self::assertSame('de', $request->targetLocale);
    }

    #[Test]
    public function unavailableSiteLocaleFallsBackToEnglish(): void
    {
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->willThrowException(new \RuntimeException('missing'));
        $subject = new AiAltSuggestionRequestBuilder(
            $siteFinder,
            $this->createMock(ConnectionPool::class),
            new AiContextSanitizer(),
            new AiFindingMetadataResolver(),
        );

        $request = $subject->build(
            $this->context('structured.file_reference_alt', 0, 0, ''),
            new AiImagePayload('data:image/jpeg;base64,AAAA', 'image/jpeg'),
        );

        self::assertSame('en', $request->targetLocale);
    }

    #[DataProvider('qualityReasonProvider')]
    #[Test]
    public function qualityReasonIsMappedForModelContext(
        string $alternative,
        string $message,
        string $expectedReason,
    ): void {
        $request = $this->subject(0, 'en-US', 'en')->build(
            $this->context(
                'structured.file_reference_alt_quality',
                0,
                0,
                $alternative,
                sprintf('sys_file_reference uid:44, alt: "%s"', $alternative),
                $message,
            ),
            new AiImagePayload('data:image/jpeg;base64,AAAA', 'image/jpeg'),
        );

        self::assertSame('quality', $request->findingType);
        self::assertSame(trim($alternative), $request->currentAlt);
        self::assertSame($expectedReason, $request->qualityReason);
    }

    public static function qualityReasonProvider(): iterable
    {
        yield 'filename' => ['hotel.jpg', '', 'filename_only'];
        yield 'redundant intro' => ['Photo of the hotel', '', 'redundant_intro'];
        yield 'too long' => [str_repeat('Long alternative text ', 8), 'Alternative text is too long', 'too_long'];
        yield 'other issue' => ['Unclear description', 'Improve this alternative text', 'other_quality_issue'];
    }

    #[Test]
    public function allLanguageReferenceFallsBackToFullSiteDefaultLocale(): void
    {
        $request = $this->subject(0, 'de-DE', 'de')->build(
            $this->context('structured.file_reference_alt', -1, -1, ''),
            new AiImagePayload('data:image/jpeg;base64,AAAA', 'image/jpeg'),
        );

        self::assertSame('de-DE', $request->targetLocale);
    }

    private function subject(int $languageId, string $localeName, string $languageCode): AiAltSuggestionRequestBuilder
    {
        $locale = $this->createMock(Locale::class);
        $locale->method('getName')->willReturn($localeName);
        $locale->method('getLanguageCode')->willReturn($languageCode);

        $language = $this->createMock(SiteLanguage::class);
        $language->method('getLanguageId')->willReturn($languageId);
        $language->method('getLocale')->willReturn($locale);

        $site = $this->createMock(Site::class);
        $site->method('getAllLanguages')->willReturn([$language]);
        $site->method('getDefaultLanguage')->willReturn($language);

        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getSiteByIdentifier')->with('test')->willReturn($site);

        return new AiAltSuggestionRequestBuilder(
            $siteFinder,
            $this->createMock(ConnectionPool::class),
            new AiContextSanitizer(),
            new AiFindingMetadataResolver(),
        );
    }

    private function context(
        string $ruleId,
        int $languageUid,
        int $referenceLanguageUid,
        string $alternative,
        string $contextSnippet = '',
        string $message = '',
    ): ImageFindingContext {
        return new ImageFindingContext(
            issue: [
                'rule_id' => $ruleId,
                'context_snippet' => $contextSnippet,
                'message' => $message,
                'hint' => '',
            ],
            fileReference: [
                'alternative' => $alternative,
                'sys_language_uid' => $referenceLanguageUid,
                'link' => '',
            ],
            siteIdentifier: 'test',
            pageUid: 0,
            languageUid: $languageUid,
            sourceTable: '',
            sourceUid: 0,
            sourceField: 'image',
            fileReferenceUid: 44,
            fileUid: 55,
            fingerprint: 'abc',
            issueTimestamp: 100,
            fileReferenceTimestamp: 200,
        );
    }
}
