<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiPrompts;
use Priebera\A11yQualityGate\Ai\Service\AiLinkTextSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationItemPresenter;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AQG 3.0.0 cross-module AI audit (owner QA, TYPO3 14.3.7, record #2076): a CKEditor Text element with an external
 * "Click here" link got "No safe suggestion" once and a suggestion on "Prepare again"; the item then went to the editor.
 * Root causes: the fix prompt (v3) did not say that an external address is a known destination; the queue showed the
 * editor step for every finished rich-text item. These tests pin the context, the prompt contract, the direct apply of
 * exactly that record (CRLF line endings as CKEditor stores them) and the status shown afterwards.
 */
final class RemediationCrossModuleConsistencyFunctionalTest extends AbstractDirectApplyFunctionalTestCase
{
    private const RECORD_2076 = "<p>For more information about our company, click the link below.</p>\r\n"
        . "<p>Learn more about the Priebera company on our website: <a href=\"https://www.priebera.sk?utm_source=chatgpt.com\">Click here</a></p>\r\n"
        . '<p>This paragraph should stay unchanged.</p>';

    protected function setUp(): void
    {
        parent::setUp();
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->insert('tt_content', ['uid' => 76, 'pid' => 1, 'CType' => 'text', 'header' => 'Record 2076', 'bodytext' => self::RECORD_2076, 'tstamp' => 1000]);
        $this->finding(76, 'rte.non_descriptive_link', self::RECORD_2076);
    }

    #[Test]
    public function theCkeditorLinkIsSuggestedFromItsParagraphAndExternalDestinationAndAppliedDirectly(): void
    {
        $this->login(self::EDITOR);
        $this->answer = ['status' => 'suggestion', 'value' => 'Visit the Priebera company website', 'reason' => 'The paragraph names the company website.'];

        $item = $this->generateAndView(76);

        $context = json_decode(substr($this->requests[0]->inputText, (int)strpos($this->requests[0]->inputText, '{')), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('https://www.priebera.sk', $context['link_target'], 'The destination, without the tracking query.');
        self::assertSame('Learn more about the Priebera company on our website: Click here', $context['surrounding_text'], 'The whole paragraph, not only the link.');
        self::assertArrayNotHasKey('page_title', $context);
        self::assertSame(EditorAiPrompts::FIX_PROMPT_VERSION, $this->requests[0]->promptVersion);
        self::assertStringContainsString('An external web address in link_target (http or https with a host name) is a known destination', $this->requests[0]->instructions);

        $applied = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::VERIFIED, $applied['status'], (string)$applied['status_reason']);
        self::assertSame(str_replace('>Click here</a>', '>Visit the Priebera company website</a>', self::RECORD_2076), $this->bodytext(76), 'CRLF line endings and the other paragraphs are byte-identical.');
        $presented = $this->get(RemediationItemPresenter::class)->present($this->item((int)$item['uid']));
        self::assertSame('server', $presented['applyMode'], 'Applied by AQG: the progress has no "In editor, unsaved" step.');
        self::assertFalse($presented['editorAlternative']);
    }

    #[Test]
    public function theEditorAlternativeKeepsTheEditorStepAndSaysNothingWasWritten(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Visit the Priebera company website', 'reason' => 'r'];
        $item = $this->generateAndView(76);

        $approved = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false);

        self::assertSame(RemediationStatus::APPROVED, $approved['status']);
        self::assertSame('', (string)$approved['status_reason'], 'The person chose the editor: no AQG reason.');
        self::assertSame('editor', $this->get(RemediationItemPresenter::class)->present($this->item((int)$item['uid']))['applyMode']);
        self::assertSame(self::RECORD_2076, $this->bodytext(76));
    }

    #[Test]
    public function anEditorApprovalAfterAFailedDirectApplyShowsTheEditorStep(): void
    {
        // Review finding: applied_at of a direct apply that ended in a conflict must not make a later editor approval
        // look like a write by AQG (no editor step, no redirect into the editor).
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Visit the Priebera company website', 'reason' => 'r'];
        $item = $this->generateAndView(76);
        $writer = new class (GeneralUtility::makeInstance(ConnectionPool::class)) extends \Priebera\A11yQualityGate\Remediation\Workflow\RichTextRecordWriter {
            public function write(string $table, int $uid, string $field, string $expected, string $value): string
            {
                return self::CONFLICT;
            }
        };
        self::assertSame(RemediationStatus::CONFLICT, $this->workflow($writer)->approve((int)$item['uid'], (int)$item['row_version'], false, '', true)['status']);
        self::assertGreaterThan(0, (int)$this->item((int)$item['uid'])['applied_at']);

        $workflow = $this->workflow();
        $workflow->generate((int)$item['uid'], (int)$this->item((int)$item['uid'])['row_version']);
        $fresh = $this->item((int)$item['uid']);
        $workflow->markViewed((int)$fresh['uid'], (int)$fresh['proposal_version']);
        $approved = $workflow->approve((int)$fresh['uid'], (int)$this->item((int)$fresh['uid'])['row_version'], false);

        self::assertSame(RemediationStatus::APPROVED, $approved['status']);
        self::assertSame(0, (int)$approved['applied_at']);
        self::assertSame('editor', $this->get(RemediationItemPresenter::class)->present($this->item((int)$fresh['uid']))['applyMode']);
        self::assertSame(self::RECORD_2076, $this->bodytext(76));
    }

    #[Test]
    public function explainSendsNoPageTitleForALink(): void
    {
        $this->login(self::ADMIN);
        $this->workflow()->explain($this->findings[76]);

        self::assertCount(1, $this->requests);
        self::assertStringNotContainsString('page_title', $this->requests[0]->inputText);
        self::assertStringNotContainsString('Bakery', $this->requests[0]->inputText, 'The title of the page the link is on.');
    }

    #[Test]
    public function aFallbackToTheEditorCarriesItsReason(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Visit the Priebera company website', 'reason' => 'r'];
        $item = $this->generateAndView(76);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_lockedrecords')->insert('sys_lockedrecords', [
            'userid' => self::EDITOR, 'username' => 'editor', 'tstamp' => time(), 'record_table' => 'tt_content', 'record_uid' => 76, 'record_pid' => 1,
        ]);

        $approved = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame([RemediationStatus::APPROVED, 'record_open'], [$approved['status'], $approved['status_reason']]);
        self::assertNotSame('', $this->get(RemediationItemPresenter::class)->present($this->item((int)$item['uid']))['statusReasonLabel']);
    }

    #[Test]
    public function explainAndTheOlderLinkTextCardFollowTheSameDestinationRule(): void
    {
        self::assertSame('aqg_editor_explain_v2', EditorAiPrompts::EXPLAIN_PROMPT_VERSION);
        self::assertStringContainsString('page_title is the page the link is on, never the link\'s destination', EditorAiPrompts::explainInstructions());
        self::assertSame('aqg_link_text_v2', AiLinkTextSuggestionRequestBuilder::PROMPT_VERSION);
        self::assertStringContainsString('Never use the title of the page the link is on as the destination.', AiLinkTextSuggestionRequestBuilder::DEVELOPER_INSTRUCTIONS);
        self::assertStringNotContainsString('page title, or surrounding', AiLinkTextSuggestionRequestBuilder::DEVELOPER_INSTRUCTIONS);
    }
}
