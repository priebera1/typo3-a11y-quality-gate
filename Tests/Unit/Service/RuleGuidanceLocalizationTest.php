<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\RemoteReportingSummaryService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

/**
 * Rule metadata, crawler guidance and axe help are English. A German backend user gets the rule's translation;
 * English users keep the stored texts (AQG 3.0 video QA: "Open the record and add a description …" and
 * "Use headings in a logical nested order." were shown in a German backend).
 */
final class RuleGuidanceLocalizationTest extends TestCase
{
    private function languages(): BackendLanguageService
    {
        $service = new BackendLanguageService();
        (new \ReflectionProperty(BackendLanguageService::class, 'explicitLanguageCatalogues'))->setValue($service, [
            'en:locallang.xlf' => [
                'rule.structured.file_reference_alt.title' => 'Image file reference is missing alt text',
                'rule.structured.file_reference_alt.howToFix' => 'Open the record and add a description in the "Alternative text" field for each image.',
                'rule.presentation.heading_order.title' => 'Fix heading order',
                'rule.presentation.heading_order.why' => 'Headings in a logical order help people understand the page outline.',
                'rule.presentation.heading_order.fix' => 'Use heading levels in a logical nested order.',
                'rule.presentation.empty_heading.title' => 'Remove empty headings',
                'rule.presentation.empty_heading.fix' => 'Remove the empty heading element or add meaningful heading text.',
            ],
            'de:locallang.xlf' => [
                'rule.structured.file_reference_alt.title' => 'Dem Bildverweis fehlt der Alternativtext',
                'rule.structured.file_reference_alt.howToFix' => 'Öffnen Sie den Datensatz und tragen Sie für jedes Bild eine Beschreibung ein.',
                'rule.presentation.heading_order.title' => 'Überschriftenreihenfolge korrigieren',
                'rule.presentation.heading_order.why' => 'Überschriften in logischer Reihenfolge helfen, die Gliederung zu verstehen.',
                'rule.presentation.heading_order.fix' => 'Verwenden Sie die Überschriftenebenen in logisch verschachtelter Reihenfolge.',
                'rule.presentation.empty_heading.title' => 'Leere Überschriften entfernen',
                'rule.presentation.empty_heading.fix' => 'Entfernen Sie das leere Überschriftselement.',
            ],
        ]);

        return $service;
    }

    #[Test]
    public function aStructuredRuleHintFollowsTheBackendLanguage(): void
    {
        $subject = new RuleMetadataPresentationService($this->languages());
        $issue = [
            'rule_id' => 'structured.file_reference_alt',
            'ruleMetadata' => [
                'title' => 'Image file reference is missing alt text',
                'howToFix' => 'Open the record and add a description in the "Alternative text" field for each image.',
            ],
        ];

        $german = $subject->present($issue, 'de');
        $english = $subject->present($issue, 'en');

        self::assertSame('Öffnen Sie den Datensatz und tragen Sie für jedes Bild eine Beschreibung ein.', $german['howToFix']);
        self::assertSame('Dem Bildverweis fehlt der Alternativtext', $german['title']);
        self::assertSame('Open the record and add a description in the "Alternative text" field for each image.', $english['howToFix']);
    }

    #[Test]
    public function storedEnglishGuidanceKeepsItsTextForEnglishUsers(): void
    {
        $subject = new RuleMetadataPresentationService($this->languages());
        $issue = [
            'rule_id' => 'heading-order',
            'help' => 'Heading levels should only increase by one',
            'guidance_how_to_fix' => 'Use headings in a logical nested order. Do not skip levels.',
        ];

        self::assertSame('Use headings in a logical nested order. Do not skip levels.', $subject->present($issue, 'en')['howToFix']);
        self::assertSame('Verwenden Sie die Überschriftenebenen in logisch verschachtelter Reihenfolge.', $subject->present($issue, 'de')['howToFix']);
        self::assertSame('Überschriftenreihenfolge korrigieren', $subject->present($issue, 'de')['title']);
    }

    #[Test]
    public function theAxeEmptyHeadingRuleUsesTheEmptyHeadingPresentation(): void
    {
        $subject = new RuleMetadataPresentationService($this->languages());

        $german = $subject->present(['rule_id' => 'empty-heading', 'help' => 'Headings should not be empty'], 'de');

        self::assertSame('Leere Überschriften entfernen', $german['title']);
        self::assertSame('Entfernen Sie das leere Überschriftselement.', $german['howToFix']);
    }

    #[Test]
    public function apiPriorityFixesShowTranslatedTitleAndFixToGermanUsers(): void
    {
        $languages = $this->languages();
        $service = new RemoteReportingSummaryService(new RuleMetadataPresentationService($languages), $languages);
        $priorityFixes = [[
            'ruleId' => 'heading-order',
            'help' => 'Heading levels should only increase by one',
            'impact' => 'moderate',
            'guidance' => [
                'title' => 'Fix heading order',
                'shortFix' => 'Use headings in a logical nested order.',
                'howToFix' => 'Use headings in a logical nested order. Do not skip levels only for visual styling.',
                'whyItMatters' => 'Correct heading order helps users understand the document outline.',
                'whoShouldFix' => 'mixed',
                'fixType' => 'content',
            ],
        ]];

        $german = $service->buildFromApiSummary([], $priorityFixes, 'de')['priorityFixes'][0];
        $english = $service->buildFromApiSummary([], $priorityFixes, 'en')['priorityFixes'][0];

        self::assertSame('Überschriftenreihenfolge korrigieren', $german['displayTitle']);
        self::assertSame('Verwenden Sie die Überschriftenebenen in logisch verschachtelter Reihenfolge.', $german['shortFix']);
        self::assertSame('Überschriften in logischer Reihenfolge helfen, die Gliederung zu verstehen.', $german['guidanceWhyItMatters']);
        self::assertNull($german['guidanceDetail']);

        self::assertSame('Use headings in a logical nested order.', $english['shortFix']);
        self::assertSame('Use headings in a logical nested order. Do not skip levels only for visual styling.', $english['guidanceDetail']);
    }

    #[Test]
    public function theCrawlersGenericGuidanceIsNotShownToGermanUsers(): void
    {
        $languages = $this->languages();
        $service = new RemoteReportingSummaryService(new RuleMetadataPresentationService($languages), $languages);
        $priorityFixes = [[
            'ruleId' => 'scrollable-region-focusable',
            'help' => 'Scrollable region must have keyboard access',
            'guidance' => [
                'shortFix' => 'Review the affected element in context.',
                'whyItMatters' => 'This automated finding may affect accessibility and should be reviewed in context.',
                'whoShouldFix' => 'unknown',
                'fixType' => 'unknown',
            ],
        ]];

        $german = $service->buildFromApiSummary([], $priorityFixes, 'de')['priorityFixes'][0];
        $english = $service->buildFromApiSummary([], $priorityFixes, 'en')['priorityFixes'][0];

        self::assertNull($german['shortFix']);
        self::assertNull($german['guidanceWhyItMatters']);
        self::assertTrue($german['guidanceIsFallback']);
        self::assertSame('Review the affected element in context.', $english['shortFix']);
    }
}
