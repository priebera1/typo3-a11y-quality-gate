<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationItemPresenter;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationRuleTitle;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;

/**
 * Atlas audit F-11 / F-31: the AI fixes queue and the page plan name a finding by its plain rule title, like Page
 * Detail, never by a humanised rule id ("File reference alt") or a grouped presentation title; the change log shows
 * the outcome an event reached, not the item's final state on every row.
 */
final class RemediationRuleTitleTest extends TestCase
{
    private function metadata(): RuleMetadataPresentationService
    {
        $languages = new BackendLanguageService();
        (new \ReflectionProperty(BackendLanguageService::class, 'explicitLanguageCatalogues'))->setValue($languages, [
            'en:locallang.xlf' => [
                'rule.structured.file_reference_alt.title' => 'Image file reference is missing alt text',
                'rule.rendered.empty_link.title' => 'Link has no accessible name',
                'rule.rte.empty_link.title' => 'Link has no accessible name',
                'rule.presentation.link_name.title' => 'Links without discernible text',
            ],
            'de:locallang.xlf' => [
                'rule.structured.file_reference_alt.title' => 'Dem Bildverweis fehlt der Alternativtext',
                'rule.rendered.empty_link.title' => 'Link hat keinen zugänglichen Namen',
                'rule.presentation.link_name.title' => 'Links ohne erkennbaren Text',
            ],
        ]);

        return new RuleMetadataPresentationService($languages);
    }

    #[Test]
    public function aQueueItemIsNamedByItsRuleTitleInEnglishAndGerman(): void
    {
        $metadata = $this->metadata();

        self::assertSame('Image file reference is missing alt text', RemediationRuleTitle::plain($metadata, 'structured.file_reference_alt', 'en'));
        self::assertSame('Dem Bildverweis fehlt der Alternativtext', RemediationRuleTitle::plain($metadata, 'structured.file_reference_alt', 'de'));
        self::assertNotSame('File reference alt', RemediationRuleTitle::plain($metadata, 'structured.file_reference_alt', 'en'));
    }

    #[Test]
    public function theRuleTitleWinsOverTheGroupedPresentationTitle(): void
    {
        self::assertSame('Link has no accessible name', RemediationRuleTitle::plain($this->metadata(), 'rendered.empty_link', 'en'));
    }

    #[Test]
    public function theEditorNamesAnRteLinkFindingLikePageDetailAndTheQueue(): void
    {
        // CKEditor / plain HTML popovers and review dialogs read ruleTitle from IssueApiController (independent review).
        self::assertSame('Link has no accessible name', RemediationRuleTitle::plain($this->metadata(), 'rte.empty_link', 'en'));
        $source = (string)file_get_contents(dirname(__DIR__, 4) . '/Classes/Controller/IssueApiController.php');
        self::assertStringContainsString('RemediationRuleTitle::plain(', $source);
        self::assertStringNotContainsString('friendlyTitleForRule(', $source);
    }

    #[Test]
    public function aRuleWithoutTranslatedTitleFallsBackToTheStoredMessage(): void
    {
        $metadata = $this->metadata();

        self::assertSame('', RemediationRuleTitle::localized($metadata, 'custom.unknown_rule', 'en'));
        self::assertSame('Custom thing is wrong.', RemediationRuleTitle::plain($metadata, 'custom.unknown_rule', 'en', 'Custom thing is wrong.'));
    }

    #[Test]
    public function aLogRowShowsTheOutcomeItsEventReachedOnly(): void
    {
        self::assertSame('verified', RemediationItemPresenter::eventOutcome('saved', 'verified'));
        self::assertSame('saved', RemediationItemPresenter::eventOutcome('applying', 'saved'));
        self::assertSame('', RemediationItemPresenter::eventOutcome('pending_review', 'applying'));
        self::assertSame('', RemediationItemPresenter::eventOutcome('preparing', 'pending_review'));
        self::assertSame('', RemediationItemPresenter::eventOutcome('saved', 'saved'));
    }
}
