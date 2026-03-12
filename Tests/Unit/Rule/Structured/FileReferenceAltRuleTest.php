<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Structured;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Structured\FileReferenceAltRule;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class FileReferenceAltRuleTest extends TestCase
{
    private function makeRule(): FileReferenceAltRule
    {
        return new FileReferenceAltRule($this->createMock(ConnectionPool::class));
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
    public function doesNotSupportNonTtContentTable(): void
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

        self::assertFalse($this->makeRule()->supports($ctx));
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
