<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationException;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;

/**
 * AI Fixes beyond images (AQG 3.0.0): scan finding → generate → review → apply → save → verify, live workspace.
 */
final class RemediationDirectApplyFunctionalTest extends AbstractDirectApplyFunctionalTestCase
{
    #[Test]
    public function aReviewedLinkTextIsAppliedSavedAndVerifiedWithEveryOtherByteKept(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions & parking "Lindenhof"', 'reason' => 'The paragraph names what the page offers.'];
        $item = $this->generateAndView(10);

        $applied = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::VERIFIED, $applied['status'], (string)$applied['status_reason']);
        self::assertSame(
            str_replace('class="more">click here</a>', 'class="more">Directions &amp; parking "Lindenhof"</a>', self::CONTACT),
            $this->bodytext(10),
            'Only the link text changed (escaped); the commented-out link, the attribute with ">" and all formatting stayed.'
        );
        self::assertSame(1, $this->rows('tx_a11y_ai_usage'), 'One provider request was recorded; applying asks no provider.');
        self::assertCount(1, $this->requests);
        self::assertContains('saved', $this->eventActions((int)$item['uid']));
        self::assertContains('verified', $this->eventActions((int)$item['uid']));
        self::assertStringContainsString('direct', implode(' ', $this->eventDetails((int)$item['uid'])));
    }

    #[Test]
    public function anIframeTitleGoesToExactlyTheSecondOfTwoIdenticalIframes(): void
    {
        $this->login(self::EDITOR);
        $this->answer = ['status' => 'suggestion', 'value' => 'Map: our bakery', 'reason' => 'The heading names the location.'];
        $item = $this->generateAndView(11);
        // Observed case A: the iframe's source is sent, so a website can be named when the text says nothing.
        self::assertStringContainsString('maps.example.test', $this->requests[0]->inputText);
        self::assertStringContainsString('Embedded content from example.com', $this->requests[0]->instructions);

        $applied = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::VERIFIED, $applied['status'], (string)$applied['status_reason']);
        $expected = substr_replace(self::IFRAMES, ' title="Map: our bakery"', strrpos(self::IFRAMES, ' width="600"></iframe>') + strlen(' width="600"'), 0);
        self::assertSame($expected, $this->bodytext(11), 'The first iframe is untouched.');
    }

    #[Test]
    public function aButtonNameIsApplied(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Search', 'reason' => 'A search form.'];
        $button = $this->generateAndView(12);
        self::assertSame(RemediationStatus::VERIFIED, $this->workflow()->approve((int)$button['uid'], (int)$button['row_version'], false, '', true)['status']);
        self::assertSame(str_replace('class="icon-search"></button>', 'class="icon-search" aria-label="Search"></button>', self::BUTTON), $this->bodytext(12));
    }

    #[Test]
    public function anEmptyHeadingIsApplied(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Breads and cakes from regional flour', 'reason' => 'Summarises the paragraph.'];
        $heading = $this->generateAndView(14);
        self::assertSame(RemediationStatus::VERIFIED, $this->workflow()->approve((int)$heading['uid'], (int)$heading['row_version'], false, '', true)['status']);
        self::assertSame(str_replace('<h3></h3>', '<h3>Breads and cakes from regional flour</h3>', self::HEADING), $this->bodytext(14));
    }

    #[Test]
    public function aPlainHtmlElementIsAppliedLikeRichText(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Video: how we bake', 'reason' => 'r'];
        $item = $this->generateAndView(15);

        self::assertSame(RemediationStatus::VERIFIED, $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true)['status']);
        self::assertSame('<div class="embed"><iframe src="https://video.example.test/v/42" title="Video: how we bake"></iframe></div>', $this->bodytext(15));
    }

    #[Test]
    public function aLinkWithMarkupInsideIsNeverPreparedAndNoProviderIsAsked(): void
    {
        // The target resolver already refuses an element with markup of its own (an icon inside the link): it is
        // fixed in the editor. RichTextElementWriter refuses it again should one ever reach the write.
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Online shop', 'reason' => 'r'];

        $prepared = $this->workflow()->prepare([$this->findings[13]], RemediationAccessService::ORIGIN_EDITOR_REQUEST)['items'][0];

        self::assertSame(['skipped', 'manual_only'], [$prepared['outcome'], $prepared['reason']]);
        self::assertSame([], $this->requests);
        self::assertSame(self::ICON_LINK, $this->bodytext(13));
    }

    #[Test]
    public function aRecordChangedAtTheElementAfterReviewIsAConflictAndNothingIsWritten(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);
        $changed = str_replace('click here', 'see here', self::CONTACT);
        $this->setBodytext(10, $changed);

        $result = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::CONFLICT, $result['status']);
        self::assertSame($changed, $this->bodytext(10));
    }

    #[Test]
    public function anEditElsewhereInTheFieldIsKeptWhenTheChangeIsApplied(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);
        $edited = str_replace('kept</p>', 'kept, edited later</p>', self::CONTACT);
        $this->setBodytext(10, $edited);

        $result = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::VERIFIED, $result['status'], (string)$result['status_reason']);
        self::assertSame(str_replace('class="more">click here</a>', 'class="more">Directions and parking</a>', $edited), $this->bodytext(10), 'The later edit and the commented-out link are kept.');
    }

    #[Test]
    public function aUserWithoutEditRightsCannotApplyAndNothingIsWritten(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);
        $this->login(self::READER);

        try {
            $workflow = $this->workflow();
            $workflow->markViewed((int)$item['uid'], (int)$item['proposal_version']);
            $workflow->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);
            self::fail('A reader must not apply.');
        } catch (RemediationException $exception) {
            self::assertContains($exception->httpStatus, [403, 404], 'Refused (the item is not even shown to a reader).');
        }
        self::assertSame(self::CONTACT, $this->bodytext(10));
    }

    #[Test]
    public function aTranslationIsChangedInItsOwnRecordOnly(): void
    {
        $this->login(self::EDITOR);
        $this->answer = ['status' => 'suggestion', 'value' => 'Wegbeschreibung und Parken', 'reason' => 'r'];
        $item = $this->generateAndView(20);

        self::assertSame(RemediationStatus::VERIFIED, $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true)['status']);
        self::assertSame('<p>Wegbeschreibung: <a href="/de/contact">Wegbeschreibung und Parken</a>.</p>', $this->bodytext(20));
        self::assertSame(self::CONTACT, $this->bodytext(10), 'The default-language record is untouched.');
    }

    #[Test]
    public function aSecondApplyOfTheSameItemWritesNothingAgain(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);
        $workflow = $this->workflow();
        $workflow->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);
        $after = $this->bodytext(10);

        try {
            $workflow->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);
            self::fail('The item is applied already.');
        } catch (RemediationException $exception) {
            self::assertSame(409, $exception->httpStatus);
        }
        self::assertSame($after, $this->bodytext(10));
        self::assertSame(1, substr_count($this->bodytext(10), 'Directions and parking'));
    }

    #[Test]
    public function aRefusalOrAnUnavailableProviderLeavesNothingToApply(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'needs_review', 'value' => '', 'reason' => 'The destination is not known.'];
        $refused = $this->generateAndView(10, false);
        self::assertSame(RemediationStatus::NO_SUGGESTION, $refused['status']);

        $this->providerDown = true;
        $this->workflow()->generate((int)$refused['uid'], (int)$refused['row_version']);
        $failed = $this->item((int)$refused['uid']);
        self::assertContains($failed['status'], [RemediationStatus::FAILED, RemediationStatus::NO_SUGGESTION]);

        try {
            $this->workflow()->approve((int)$failed['uid'], (int)$failed['row_version'], false, '', true);
            self::fail('Nothing to approve.');
        } catch (RemediationException $exception) {
            self::assertSame(409, $exception->httpStatus);
        }
        self::assertSame(self::CONTACT, $this->bodytext(10));
    }

    #[Test]
    public function withoutTheApplyModeRichTextIsStillApprovedForTheEditor(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);

        self::assertSame(RemediationStatus::APPROVED, $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false)['status']);
        self::assertSame(self::CONTACT, $this->bodytext(10), 'The editor flow writes nothing on the server.');
    }
}
