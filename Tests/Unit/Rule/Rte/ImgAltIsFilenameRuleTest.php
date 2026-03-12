<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\ImgAltIsFilenameRule;

final class ImgAltIsFilenameRuleTest extends TestCase
{
    private ImgAltIsFilenameRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ImgAltIsFilenameRule();
    }

    #[Test]
    public function ruleIdIsStable(): void
    {
        self::assertSame('rte.img_alt_is_filename', $this->rule->getRuleId());
    }

    #[Test]
    public function defaultSeverityIsWarning(): void
    {
        self::assertSame(Severity::Warning, $this->rule->getDefaultSeverity());
    }

    #[Test]
    public function meaningfulAltPasses(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<img src="team.jpg" alt="Our team at the conference">')));
    }

    #[Test]
    public function missingAltIsIgnored(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<img src="photo.jpg">')));
    }

    #[Test]
    public function emptyAltIsIgnored(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<img src="photo.jpg" alt="">')));
    }

    #[Test]
    #[DataProvider('filenameAltProvider')]
    public function filenameAltFails(string $alt): void
    {
        $html = sprintf('<img src="x.jpg" alt="%s">', htmlspecialchars($alt, ENT_QUOTES));
        self::assertCount(1, $this->rule->check($this->ctx($html)));
    }

    public static function filenameAltProvider(): array
    {
        return [
            'jpg' => ['team.jpg'],
            'jpeg' => ['image.jpeg'],
            'png' => ['banner.png'],
            'webp' => ['hero.webp'],
            'uppercase' => ['PHOTO.JPG'],
            'underscore' => ['IMG_0042.jpg'],
        ];
    }

    private function ctx(string $content): CheckContext
    {
        return new CheckContext(
            siteIdentifier: 'main',
            pageUid: 1,
            sourceLangUid: 0,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 42,
            sourceField: 'bodytext',
            content: $content,
        );
    }
}
