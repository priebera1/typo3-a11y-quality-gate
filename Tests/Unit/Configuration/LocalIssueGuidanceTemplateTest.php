<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class LocalIssueGuidanceTemplateTest extends TestCase
{
    #[Test]
    public function pageDetailUsesLocalGuidancePartialInsteadOfHintOnlyCallout(): void
    {
        $template = file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/PageDetail/Show.html');

        self::assertIsString($template);
        self::assertStringContainsString('partial="Issue/LocalGuidance"', $template);
        self::assertStringContainsString('<p class="aqg-rulecard__fix">', $template);
        self::assertStringContainsString('{rule.howToFix}', $template);
        self::assertStringNotContainsString('<div class="aqg-callout__text">{issue.hint}</div>', $template);
    }

    #[Test]
    public function localGuidancePartialGuardsOptionalBlocks(): void
    {
        $partial = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Issue/LocalGuidance.html');

        self::assertIsString($partial);
        self::assertStringContainsString('data-aqg-local-guidance="true"', $partial);
        self::assertStringContainsString('condition="{guidance.hasBadges}"', $partial);
        // The rule card splits the partial: "Why it matters" (why, affected users) and "Standards and rule reference".
        self::assertStringContainsString('condition="{guidance.whyItMatters} && {section} != \'reference\'"', $partial);
        // How to fix is the rule card's visible fix line; the Details only repeat it when asked to.
        self::assertStringContainsString('condition="{showHowToFix} && {guidance.howToFix}"', $partial);
        self::assertStringContainsString('condition="{guidance.hasAffectedUsers} && {section} != \'reference\'"', $partial);
        self::assertStringContainsString('&& {section} != \'why\'"', $partial);
        self::assertStringContainsString('condition="{guidance.hasStandardsAndImpact}"', $partial);
    }

    #[Test]
    public function localGuidancePartialUsesTranslationKeysForStaticLabels(): void
    {
        $partial = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Issue/LocalGuidance.html');
        $language = file_get_contents(__DIR__ . '/../../../Resources/Private/Language/locallang.xlf');

        self::assertIsString($partial);
        self::assertIsString($language);

        foreach ([
            'module.localGuidance.ariaLabel',
            'module.localGuidance.owner',
            'module.localGuidance.fixType',
            'module.localGuidance.affectedUsers',
            'module.localGuidance.standardsAndImpact',
            'module.localGuidance.wcagReference',
            'module.localGuidance.technique',
            'module.localGuidance.standards',
            'module.localGuidance.documentation',
            'module.localGuidance.technicalTags',
        ] as $translationKey) {
            self::assertStringContainsString($translationKey, $partial);
            self::assertStringContainsString('id="' . $translationKey . '"', $language);
        }

        self::assertStringNotContainsString('Owner: {guidance.ownerLabel}', $partial);
        self::assertStringNotContainsString('Fix type: {guidance.fixTypeLabel}', $partial);
        self::assertStringNotContainsString('Affected users:</span>', $partial);
        self::assertStringNotContainsString('<summary>Standards and impact</summary>', $partial);
    }

    #[Test]
    public function standardsAndImpactDetailsRenderOnlyMetadataShownInsideDetails(): void
    {
        $partial = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Issue/LocalGuidance.html');
        $service = file_get_contents(__DIR__ . '/../../../Classes/Service/LocalIssueGuidanceService.php');

        self::assertIsString($partial);
        self::assertIsString($service);

        self::assertStringContainsString('condition="{guidance.hasStandardsAndImpact}"', $partial);
        self::assertStringContainsString('$hasStandardsAndImpact = $wcagReferences !== []', $service);
        self::assertStringNotContainsString('$hasStandardsAndImpact = $affectedUserItems !== []', $service);
    }

    #[Test]
    public function localGuidanceStylesKeepBadgesAndMetadataWithinNarrowCard(): void
    {
        $styles = file_get_contents(__DIR__ . '/../../../Resources/Private/Scss/views/_page-detail.scss');

        self::assertIsString($styles);
        self::assertStringContainsString('.a11y-page-detail .aqg-local-guidance', $styles);
        self::assertStringContainsString('min-width: 0;', $styles);
        self::assertStringContainsString('max-width: 100%;', $styles);
    }
}
