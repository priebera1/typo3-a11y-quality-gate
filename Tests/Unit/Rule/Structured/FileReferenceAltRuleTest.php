<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Structured;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Structured\FileReferenceAltRule;
use Priebera\A11yQualityGate\Domain\Repository\Contract\FileReferenceRepositoryInterface;

final class FileReferenceAltRuleTest extends TestCase
{
    private function makeRule(): FileReferenceAltRule
    {
        return new FileReferenceAltRule($this->createMock(FileReferenceRepositoryInterface::class));
    }

    #[Test]
    public function ruleIdIsStable(): void
    {
        self::assertSame('structured.file_reference_alt', $this->makeRule()->getRuleId());
    }

    #[Test]
    public function defaultSeverityIsCritical(): void
    {
        self::assertSame(Severity::Critical, $this->makeRule()->getDefaultSeverity());
    }

    #[Test]
    public function supportsImageField(): void
    {
        self::assertTrue($this->makeRule()->supports($this->ctx(field: 'image', content: 42)));
    }

    #[Test]
    public function supportsAssetsField(): void
    {
        self::assertTrue($this->makeRule()->supports($this->ctx(field: 'assets', content: 5)));
    }

    #[Test]
    public function supportsMediaField(): void
    {
        self::assertTrue($this->makeRule()->supports($this->ctx(field: 'media', content: 5)));
    }

    #[Test]
    public function supportsStringContentForImageField(): void
    {
        self::assertTrue($this->makeRule()->supports($this->ctx(field: 'image', content: '<img src="x.jpg">')));
    }

    #[Test]
    public function supportsZeroValueForImageField(): void
    {
        self::assertTrue($this->makeRule()->supports($this->ctx(field: 'image', content: 0)));
    }

    #[Test]
    public function doesNotSupportNonImageField(): void
    {
        self::assertFalse($this->makeRule()->supports($this->ctx(field: 'bodytext', content: 42)));
    }

    #[Test]
    public function supportsNonTtContentTableBecauseFilteringNowHappensAtRepositoryLevel(): void
    {
        $ctx = new CheckContext(
            siteIdentifier: 'main',
            pageUid: 1,
            sourceLangUid: 0,
            sourceTable: 'tx_news_domain_model_news',
            sourceUid: 5,
            sourceField: 'image',
            content: 5,
        );

        self::assertTrue($this->makeRule()->supports($ctx));
    }


    #[Test]
    public function explicitDecorativeFlagAllowsEmptyAlternativeText(): void
    {
        $rule = $this->makeRuleWithReferences([
            $this->reference(alternative: '', decorative: true),
        ]);

        self::assertSame([], $rule->check($this->ctx()));
    }

    #[Test]
    public function emptyAlternativeWithoutDecorativeFlagProducesFinding(): void
    {
        $rule = $this->makeRuleWithReferences([
            $this->reference(alternative: '', decorative: false),
        ]);

        $violations = $rule->check($this->ctx());

        self::assertCount(1, $violations);
        self::assertSame('structured.file_reference_alt', $violations[0]->ruleId);
    }

    #[Test]
    public function canonicalDecorativeFlagSuppressesFinding(): void
    {
        $reference = $this->reference(alternative: '', decorative: false);
        $reference['tx_a11y_is_decorative'] = 1;
        $rule = $this->makeRuleWithReferences([$reference]);

        self::assertSame([], $rule->check($this->ctx()));
    }

    #[Test]
    public function reviewedAlternativeTextKeepsImageInformative(): void
    {
        $rule = $this->makeRuleWithReferences([
            $this->reference(alternative: 'A cyclist crossing a bridge', decorative: false),
        ]);

        self::assertSame([], $rule->check($this->ctx()));
    }

    /** @param list<array<string, mixed>> $references */
    private function makeRuleWithReferences(array $references): FileReferenceAltRule
    {
        $repository = $this->createMock(FileReferenceRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('findVisibleImageReferencesWithMetadata')
            ->with(Tables::TT_CONTENT, 42, 'image')
            ->willReturn($references);

        return new FileReferenceAltRule($repository);
    }

    /** @return array<string, mixed> */
    private function reference(string $alternative, bool $decorative): array
    {
        return [
            'uid' => 10,
            'uid_local' => 20,
            'identifier' => '/images/example.jpg',
            'alternative' => $alternative,
            'title' => null,
            'metadata_alternative' => 'Metadata fallback must not override an explicit reference value.',
            'metadata_title' => null,
            'tx_a11y_is_decorative' => $decorative ? 1 : 0,
        ];
    }

    private function ctx(string $field = 'image', mixed $content = 42): CheckContext
    {
        return new CheckContext(
            siteIdentifier: 'main',
            pageUid: 1,
            sourceLangUid: 0,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 42,
            sourceField: $field,
            content: $content,
        );
    }
}
