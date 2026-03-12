<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\ImgAltMissingRule;

final class ImgAltMissingRuleTest extends TestCase
{
    private ImgAltMissingRule $rule;

    protected function setUp(): void
    {
        $this->rule = new ImgAltMissingRule();
    }

    #[Test]
    public function ruleIdIsStable(): void
    {
        self::assertSame('rte.img_alt_missing', $this->rule->getRuleId());
    }

    #[Test]
    public function defaultSeverityIsCritical(): void
    {
        self::assertSame(Severity::Critical, $this->rule->getDefaultSeverity());
    }

    #[Test]
    public function supportsNonEmptyStringContent(): void
    {
        self::assertTrue($this->rule->supports($this->ctx('<p>text</p>')));
    }

    #[Test]
    public function doesNotSupportEmptyString(): void
    {
        self::assertFalse($this->rule->supports($this->ctx('')));
    }

    #[Test]
    public function doesNotSupportNullContent(): void
    {
        self::assertFalse($this->rule->supports($this->ctx(null)));
    }

    #[Test]
    public function doesNotSupportIntegerContent(): void
    {
        self::assertFalse($this->rule->supports($this->ctx(42)));
    }

    #[Test]
    public function noImagesProducesNoViolations(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx('<p>Just text.</p>')));
    }

    #[Test]
    public function imageWithMeaningfulAltPasses(): void
    {
        $html = '<img src="team.jpg" alt="Our team at the 2024 conference">';

        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function decorativeImageWithEmptyAltAndPresentationRolePasses(): void
    {
        $html = '<img src="divider.png" alt="" role="presentation">';

        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function decorativeImageWithEmptyAltAndNoneRolePasses(): void
    {
        $html = '<img src="divider.png" alt="" role="none">';

        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function missingAltAttributeProducesCriticalViolation(): void
    {
        $violations = $this->rule->check($this->ctx('<img src="team.jpg">'));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Critical, $violations[0]->severity);
        self::assertSame('rte.img_alt_missing', $violations[0]->ruleId);
    }

    #[Test]
    public function emptyAltWithoutDecorativeRoleProducesCriticalViolation(): void
    {
        $violations = $this->rule->check($this->ctx('<img src="photo.jpg" alt="">'));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Critical, $violations[0]->severity);
    }

    #[Test]
    public function multipleImagesProduceOneViolationPerOffendingImage(): void
    {
        $html = '
            <img src="ok.jpg" alt="Descriptive alt">
            <img src="bad-missing.jpg">
            <img src="bad-empty.jpg" alt="">
            <img src="deco.jpg" alt="" role="presentation">
        ';

        $violations = $this->rule->check($this->ctx($html));

        self::assertCount(2, $violations);
    }

    #[Test]
    public function violationContextSnippetContainsSrcAttribute(): void
    {
        $violations = $this->rule->check($this->ctx('<img src="conference-2024.jpg">'));

        self::assertStringContainsString('conference-2024.jpg', $violations[0]->contextSnippet);
    }

    #[Test]
    public function nestedImageInsideLinkIsChecked(): void
    {
        $html = '<a href="/about"><img src="icon.png"></a>';

        self::assertCount(1, $this->rule->check($this->ctx($html)));
    }

    #[Test]
    public function fingerprintIsStableAcrossMultipleCalls(): void
    {
        $ctx = $this->ctx('<img src="team.jpg">');
        $violations = $this->rule->check($ctx);

        self::assertSame(
            $violations[0]->fingerprint($ctx),
            $violations[0]->fingerprint($ctx)
        );
    }

    #[Test]
    public function fingerprintDiffersForDifferentLanguages(): void
    {
        $html = '<img src="team.jpg">';
        $ctxEn = $this->ctx($html, langUid: 0);
        $ctxDe = $this->ctx($html, langUid: 1);

        $violation = $this->rule->check($ctxEn)[0];

        self::assertNotSame(
            $violation->fingerprint($ctxEn),
            $violation->fingerprint($ctxDe)
        );
    }

    #[Test]
    public function fingerprintDiffersForDifferentSites(): void
    {
        $html = '<img src="team.jpg">';
        $ctxSiteA = $this->ctx($html, site: 'site-a');
        $ctxSiteB = $this->ctx($html, site: 'site-b');
        $violation = $this->rule->check($ctxSiteA)[0];

        self::assertNotSame(
            $violation->fingerprint($ctxSiteA),
            $violation->fingerprint($ctxSiteB)
        );
    }

    #[Test]
    public function twoIdenticalImagesInSameFieldProduceDifferentFingerprints(): void
    {
        $html = '
            <p><img src="x.jpg"></p>
            <p><img src="x.jpg"></p>
        ';
        $ctx = $this->ctx($html);
        $violations = $this->rule->check($ctx);

        self::assertCount(2, $violations);
        self::assertNotSame(
            $violations[0]->fingerprint($ctx),
            $violations[1]->fingerprint($ctx)
        );
    }

    #[Test]
    public function malformedHtmlDoesNotThrow(): void
    {
        $violations = $this->rule->check($this->ctx('<img src="broken.jpg"><p>Unclosed <b>tag'));

        self::assertCount(1, $violations);
        self::assertSame(Severity::Critical, $violations[0]->severity);
    }

    private function ctx(
        mixed $content,
        string $site = 'main',
        int $langUid = 0,
    ): CheckContext {
        return new CheckContext(
            siteIdentifier: $site,
            pageUid: 1,
            sourceLangUid: $langUid,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 42,
            sourceField: 'bodytext',
            content: $content,
        );
    }
}
