<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\SiteResolutionServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\FileReferenceRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageRemediationValidationException;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;

final class ImageFindingContextResolverTest extends TestCase
{
    #[Test]
    public function unsupportedRuleIsRejected(): void
    {
        $this->expectException(ImageRemediationValidationException::class);
        $this->subject($this->issue(['rule_id' => 'structured.other']), $this->reference())->resolve(12);
    }

    #[Test]
    public function nonStructuredFindingIsRejected(): void
    {
        $this->expectException(ImageRemediationValidationException::class);
        $this->subject($this->issue(['source_type' => 'rendered']), $this->reference())->resolve(12);
    }

    #[Test]
    public function missingOrDeletedReferenceIsStale(): void
    {
        $this->expectException(StaleImageFindingException::class);
        $this->subject($this->issue(), null)->resolve(12);
    }

    /**
     * @param array<string,mixed> $referenceOverrides
     */
    #[DataProvider('referenceMismatchProvider')]
    #[Test]
    public function referenceContextMismatchIsStale(array $referenceOverrides): void
    {
        $this->expectException(StaleImageFindingException::class);
        $this->subject($this->issue(), $this->reference($referenceOverrides))->resolve(12);
    }

    public static function referenceMismatchProvider(): iterable
    {
        yield 'source table mismatch' => [['tablenames' => 'pages']];
        yield 'source uid mismatch' => [['uid_foreign' => 99]];
        yield 'field mismatch' => [['fieldname' => 'media']];
        yield 'language mismatch' => [['sys_language_uid' => 1]];
    }

    #[Test]
    public function siteMismatchIsStale(): void
    {
        $this->expectException(StaleImageFindingException::class);
        $this->subject($this->issue(), $this->reference(), 'other-site')->resolve(12);
    }


    #[Test]
    public function qualityRuleIsSupported(): void
    {
        $context = $this->subject(
            $this->issue(['rule_id' => 'structured.file_reference_alt_quality']),
            $this->reference(),
        )->resolve(12);

        self::assertSame('structured.file_reference_alt_quality', $context->issue['rule_id']);
    }

    #[Test]
    public function allLanguageReferenceDoesNotCauseFalseStaleConflict(): void
    {
        $context = $this->subject(
            $this->issue(['source_lang_uid' => -1]),
            $this->reference(['sys_language_uid' => -1]),
        )->resolve(12);

        self::assertSame(-1, $context->languageUid);
        self::assertSame(-1, (int)$context->fileReference['sys_language_uid']);
    }

    #[Test]
    public function validReferenceReturnsExpectedContext(): void
    {
        $context = $this->subject($this->issue(), $this->reference())->resolve(12);

        self::assertSame(12, (int)$context->issue['uid']);
        self::assertSame(44, $context->fileReferenceUid);
        self::assertSame(55, $context->fileUid);
        self::assertSame('tt_content', $context->sourceTable);
        self::assertSame(42, $context->sourceUid);
        self::assertSame('assets', $context->sourceField);
        self::assertSame('main', $context->siteIdentifier);
        self::assertSame(0, $context->languageUid);
    }

    /** @param array<string,mixed> $issueOverrides */
    private function issue(array $issueOverrides = []): array
    {
        return array_replace([
            'uid' => 12,
            'rule_id' => 'structured.file_reference_alt',
            'source_type' => 'structured',
            'context_path' => 'tt_content:42 > assets > ref:44',
            'source_table' => 'tt_content',
            'source_uid' => 42,
            'source_field' => 'assets',
            'source_lang_uid' => 0,
            'site_identifier' => 'main',
            'page_uid' => 10,
            'fingerprint' => 'abc',
            'tstamp' => 100,
        ], $issueOverrides);
    }

    /** @param array<string,mixed> $referenceOverrides */
    private function reference(array $referenceOverrides = []): array
    {
        return array_replace([
            'uid' => 44,
            'uid_local' => 55,
            'tablenames' => 'tt_content',
            'uid_foreign' => 42,
            'fieldname' => 'assets',
            'sys_language_uid' => 0,
            'tstamp' => 200,
        ], $referenceOverrides);
    }

    private function subject(array $issue, ?array $reference, string $resolvedSite = 'main'): ImageFindingContextResolver
    {
        $issues = $this->createMock(IssueRemediationRepositoryInterface::class);
        $issues->method('findByUid')->with(12)->willReturn($issue);
        $references = $this->createMock(FileReferenceRepositoryInterface::class);
        $references->method('findByUid')->with(44)->willReturn($reference);
        $sites = $this->createMock(SiteResolutionServiceInterface::class);
        $sites->method('resolveSiteIdentifierForPageId')->with(10, '')->willReturn($resolvedSite);

        return new ImageFindingContextResolver($issues, $references, $sites);
    }
}
