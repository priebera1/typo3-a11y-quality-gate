<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\RuleMetadataKeyResolver;

final class RuleMetadataKeyResolverTest extends TestCase
{
    #[Test]
    #[DataProvider('localRuleAliasProvider')]
    public function localRuleIdsResolveToExistingMetadataKeys(string $localRuleId, string $expectedMetadataKey): void
    {
        $subject = new RuleMetadataKeyResolver([
            'image-alt',
            'link-name',
            'button-name',
            'frame-title',
            'form_control_missing_label',
            'file_reference_alt_quality',
            'empty_heading',
        ]);

        self::assertSame($expectedMetadataKey, $subject->resolveFriendlyRuleKey($localRuleId));
    }

    /** @return iterable<string, array{0:string,1:string}> */
    public static function localRuleAliasProvider(): iterable
    {
        yield 'rendered image alt' => ['rendered.img_missing_alt', 'image-alt'];
        yield 'rendered empty link' => ['rendered.empty_link', 'link-name'];
        yield 'rte empty link' => ['rte.empty_link', 'link-name'];
        yield 'rendered empty button' => ['rendered.empty_button', 'button-name'];
        yield 'rendered iframe title' => ['rendered.iframe_missing_title', 'frame-title'];
        yield 'rte iframe title' => ['rte.iframe_missing_title', 'frame-title'];
        yield 'rendered form label' => ['rendered.form_control_missing_label', 'form_control_missing_label'];
        yield 'structured file reference alt' => ['structured.file_reference_alt_quality', 'file_reference_alt_quality'];
        yield 'rendered empty heading' => ['rendered.empty_heading', 'empty_heading'];
        yield 'rte empty heading' => ['rte.empty_heading', 'empty_heading'];
    }

    #[Test]
    public function unknownLocalRuleKeepsFallbackPath(): void
    {
        $subject = new RuleMetadataKeyResolver(['image-alt', 'link-name']);

        self::assertSame('', $subject->resolveFriendlyRuleKey('custom_extension.unknown_rule_without_metadata'));
    }
}
