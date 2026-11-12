<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantContext;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantContextResolver;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantService;
use Priebera\A11yQualityGate\Controller\ToolbarScanController;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The toolbar assistant resolves what the user works on from identifiers only and shows stored findings there. TYPO3
 * permissions decide: a record or page the user may not show resolves like one that does not exist, a forged table is
 * ignored, a language the user may not edit is not shown. Without AI (no licence here) the deterministic guidance is
 * shown and no AI action is offered. Real be_users, be_groups, page permissions and tt_content rows.
 */
final class ToolbarAssistantFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'client-a';

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        // [uid, pid, title, is_siteroot, owner, group, perms_user, perms_group, perms_everybody]
        foreach ([
            [1, 0, 'Client A', 1, 0, 0, 0, 0, 31],
            [2, 1, 'Open page', 0, 0, 0, 0, 0, 31],
            [3, 1, 'Board minutes', 0, 99, 99, 31, 31, 0],      // nobody else may show it
        ] as [$uid, $pid, $title, $root, $owner, $group, $permsUser, $permsGroup, $permsEverybody]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $uid === 1 ? '/' : '/p' . $uid, 'doktype' => 1,
                'is_siteroot' => $root, 'perms_userid' => $owner, 'perms_groupid' => $group, 'perms_user' => $permsUser,
                'perms_group' => $permsGroup, 'perms_everybody' => $permsEverybody, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        foreach ([
            [10, 2, 'Opening hours', 0],
            [11, 3, 'Salaries 2027', 0],
            [12, 2, 'Öffnungszeiten', 1],
        ] as [$uid, $pid, $header, $language]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'header' => $header, 'CType' => 'text', 'sys_language_uid' => $language,
                'bodytext' => '<p>secret body text ' . $uid . '</p>', 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'title' => 'Client A editors', 'groupMods' => 'web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => '0',
        ]);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 2, 'username' => 'editor', 'admin' => 0, 'usergroup' => '10', 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 3, 'username' => 'nomodule', 'admin' => 0, 'usergroup' => '', 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 11, 'title' => 'German translators', 'groupMods' => 'web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => '1',
        ]);
        $users->insert('be_users', ['uid' => 4, 'username' => 'translator', 'admin' => 0, 'usergroup' => '11', 'disable' => 0, 'deleted' => 0]);

        $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 2, 'source_uid' => 10, 'source_field' => 'bodytext', 'severity' => 1, 'rule_id' => 'rte.img_alt_missing', 'context_snippet' => '<img src="secret.jpg">']);
        $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 2, 'source_uid' => 10, 'source_field' => 'header', 'severity' => 2, 'rule_id' => 'rte.empty_link']);
        $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 2, 'source_uid' => 99, 'source_field' => 'bodytext', 'severity' => 2, 'rule_id' => 'rte.empty_link']);
        $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 3, 'source_uid' => 11, 'source_field' => 'bodytext', 'severity' => 1, 'rule_id' => 'rte.img_alt_missing']);
        $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 2, 'source_uid' => 10, 'source_field' => 'bodytext', 'severity' => 1, 'rule_id' => 'rte.img_alt_missing', 'status' => 2]);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, 1, 'https://a.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function theOpenContentElementIsTheContextWithItsOwnOpenFindingsOnly(): void
    {
        $this->asUser(2);

        $view = $this->assistant(['editTable' => 'tt_content', 'editUid' => '10']);

        self::assertSame('record', $view['context']['type']);
        self::assertSame('Opening hours', $view['context']['title']);
        self::assertSame('Open page', $view['context']['pageTitle']);
        $issues = $this->issues(['editTable' => 'tt_content', 'editUid' => '10']);
        self::assertCount(2, $issues, 'the other element\'s finding and the resolved one are not part of the context');
        self::assertSame(1, (int)$issues[0]['severity'], 'most severe first');
        $answer = $this->ask(['editTable' => 'tt_content', 'editUid' => '10'], 'first');
        self::assertNotSame('', $answer['citations'][0]['detailUrl']);
        self::assertSame('', $answer['citations'][0]['editUrl'], 'no "Edit" link for the element that is already open');
        self::assertStringNotContainsString('secret', json_encode([$view, $answer], JSON_THROW_ON_ERROR), 'no stored snippet or field content');
    }

    #[Test]
    public function anElementOnAPageTheEditorMayNotShowResolvesLikeAMissingOne(): void
    {
        $this->asUser(2);

        $hidden = $this->assistant(['editTable' => 'tt_content', 'editUid' => '11']);
        $missing = $this->assistant(['editTable' => 'tt_content', 'editUid' => '4711']);

        self::assertSame('unavailable', $hidden['context']['type']);
        self::assertSame($missing, $hidden);
        self::assertSame([], $this->issues(['editTable' => 'tt_content', 'editUid' => '11']));
        self::assertStringNotContainsString('Salaries', json_encode($hidden, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('Board', json_encode($hidden, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aPageTheEditorMayNotShowIsNotAnalysedButTheAdministratorSeesIt(): void
    {
        $this->asUser(2);
        self::assertSame('unavailable', $this->assistant(['id' => '3'])['context']['type']);

        $this->asUser(1);
        $view = $this->assistant(['id' => '3']);
        self::assertSame('page', $view['context']['type']);
        self::assertCount(1, $this->issues(['id' => '3']));
    }

    #[Test]
    public function thePageContextListsTheReadablePagesFindings(): void
    {
        $this->asUser(2);

        $view = $this->assistant(['id' => '2', 'language' => '0']);

        self::assertSame('page', $view['context']['type']);
        // Two open findings of the readable record 10; the finding of the missing record 99 is withheld (Sol QA-02:
        // a finding is shown only while its source record exists and is readable).
        self::assertCount(2, $this->issues(['id' => '2', 'language' => '0']));
    }

    #[Test]
    public function aForgedTableIsIgnored(): void
    {
        $this->asUser(2);

        $view = $this->assistant(['editTable' => 'be_users', 'editUid' => '1']);

        self::assertSame('none', $view['context']['type']);
        self::assertStringNotContainsString('admin', json_encode($view['context'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function anElementInALanguageTheEditorMayNotUseIsNotShown(): void
    {
        $this->asUser(2);

        self::assertSame('unavailable', $this->assistant(['editTable' => 'tt_content', 'editUid' => '12'])['context']['type']);
    }

    #[Test]
    public function unsavedChangesAreNamedAndMarkTheFindingsOfChangedFields(): void
    {
        $this->asUser(2);

        $view = $this->assistant(['editTable' => 'tt_content', 'editUid' => '10', 'unsaved' => '1', 'changed' => 'bodytext,<script>,x"y']);

        self::assertContains('unsaved', $view['notes']);
        $context = $this->get(ToolbarAssistantContextResolver::class)->resolve($this->request(['editTable' => 'tt_content', 'editUid' => '10', 'unsaved' => '1', 'changed' => 'bodytext,<script>,x"y']));
        self::assertSame(['bodytext'], $context->changedFields, 'only valid field names, never values');
    }

    #[Test]
    public function aWorkspaceUserIsToldThatFindingsDescribeTheLiveSite(): void
    {
        $this->asUser(2);
        $GLOBALS['BE_USER']->workspace = 1;

        self::assertContains('workspace', $this->assistant(['id' => '2'])['notes']);
    }

    #[Test]
    public function withoutAiTheGuidanceStaysAndNoAiActionIsOffered(): void
    {
        $this->asUser(1);

        $view = $this->assistant(['id' => '2']);

        self::assertFalse($view['ai']['ready']);
        self::assertTrue($view['showAiState']);
        $html = (string)$this->get(ToolbarScanController::class)->assistantAction($this->request(['id' => '2']))->getBody();
        self::assertStringContainsString('data-aqt-ask-input', $html, 'the question field is there without AI too');
        self::assertStringContainsString('data-ai="0"', $html);
        self::assertStringNotContainsString('name="aqt-ask-scope"', $html, 'no AI scope choice without AI');
    }

    #[Test]
    public function aMissingFileIsUnavailable(): void
    {
        $this->asUser(2);

        self::assertSame('unavailable', $this->assistant(['fileUid' => '4711'])['context']['type']);
        self::assertSame('folder', $this->assistant(['folder' => '1'])['context']['type']);
    }

    #[Test]
    public function aFilesImageFindingsComeFromItsReferencesOnReadablePagesOnly(): void
    {
        $pool = $this->getConnectionPool();
        foreach ([[30, 10], [31, 11]] as [$referenceUid, $contentUid]) {
            $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
                'uid' => $referenceUid, 'pid' => $contentUid === 10 ? 2 : 3, 'uid_local' => 7, 'uid_foreign' => $contentUid,
                'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => '', 'sys_language_uid' => 0,
            ]);
            $this->insertIssue([
                'site_identifier' => self::SITE, 'page_uid' => $contentUid === 10 ? 2 : 3, 'source_uid' => $contentUid,
                'source_field' => 'image', 'source_type' => 'structured', 'rule_id' => 'structured.file_reference_alt',
                'context_path' => 'Page > tt_content:' . $contentUid . ' > image > ref:' . $referenceUid,
            ]);
        }
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 32, 'pid' => 2, 'uid_local' => 7, 'uid_foreign' => 12, 'tablenames' => 'tt_content', 'fieldname' => 'image',
            'alternative' => '', 'sys_language_uid' => 1,
        ]);
        $this->insertIssue([
            'site_identifier' => self::SITE, 'page_uid' => 2, 'source_uid' => 12, 'source_lang_uid' => 1, 'source_field' => 'image',
            'source_type' => 'structured', 'rule_id' => 'structured.file_reference_alt', 'context_path' => 'Page > tt_content:12 > image > ref:32',
        ]);
        // A finding naming one of the file's references but stored for another element is not this file's finding.
        $this->insertIssue([
            'site_identifier' => self::SITE, 'page_uid' => 2, 'source_uid' => 10, 'source_field' => 'media',
            'source_type' => 'structured', 'rule_id' => 'structured.file_reference_alt', 'context_path' => 'Page > tt_content:10 > media > ref:30',
        ]);
        $this->asUser(2);

        $issues = (new \ReflectionMethod(ToolbarAssistantService::class, 'openImageIssuesOfFile'))->invoke($this->get(ToolbarAssistantService::class), 7);

        self::assertSame([2], array_values(array_unique(array_map(static fn (array $issue): int => (int)$issue['page_uid'], $issues))), 'the reference on the hidden page is not listed');
        self::assertCount(1, $issues, 'not the German element (no language access), not the mismatching field');
    }

    #[Test]
    public function aVersionOfAnotherWorkspaceResolvesLikeAMissingElement(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 20, 'pid' => 2, 'header' => 'Draft of someone else', 'CType' => 'text', 'sys_language_uid' => 0,
            't3ver_wsid' => 5, 't3ver_oid' => 10, 'deleted' => 0, 'hidden' => 0,
        ]);
        $this->asUser(2);

        $view = $this->assistant(['editTable' => 'tt_content', 'editUid' => '20']);

        self::assertSame('unavailable', $view['context']['type']);
        self::assertStringNotContainsString('Draft of someone else', json_encode($view, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function aPageTranslationStandsForItsPageInThatLanguageAndNeedsLanguageAccess(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', [
            'uid' => 4, 'pid' => 1, 'title' => 'Offene Seite', 'slug' => '/p2', 'doktype' => 1, 'sys_language_uid' => 1,
            'l10n_parent' => 2, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0,
        ]);
        $this->asUser(2);
        $denied = $this->assistant(['editTable' => 'pages', 'editUid' => '4']);
        self::assertSame('unavailable', $denied['context']['type'], 'the editor may not use language 1');
        self::assertStringNotContainsString('Offene Seite', json_encode($denied, JSON_THROW_ON_ERROR));

        $this->asUser(1);
        $view = $this->assistant(['editTable' => 'pages', 'editUid' => '4']);
        self::assertSame('page', $view['context']['type']);
        self::assertSame(2, $view['context']['pageUid']);
        self::assertSame(1, $view['context']['languageUid']);
    }

    #[Test]
    public function aDisallowedLanguageFallsBackToTheDefaultOnlyWhenThatIsAllowed(): void
    {
        $this->asUser(2);
        self::assertSame(0, $this->assistant(['id' => '2', 'language' => '1'])['context']['languageUid']);

        $this->asUser(4);
        self::assertSame('unavailable', $this->assistant(['id' => '2', 'language' => '0'])['context']['type'], 'language 1 only');
        self::assertSame(1, $this->assistant(['id' => '2', 'language' => '1'])['context']['languageUid']);
    }

    #[Test]
    public function aUserWithoutTheAccessibilityModuleGetsNothing(): void
    {
        $this->asUser(3);

        $response = $this->get(ToolbarScanController::class)->assistantAction($this->request(['id' => '2']));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', (string)$response->getBody());
    }

    #[Test]
    public function theRenderedAssistantNamesTheContextAndEscapesStoredText(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['header' => 'Hours <b onmouseover=x>'], ['uid' => 10]);
        $this->asUser(2);

        $html = (string)$this->get(ToolbarScanController::class)->assistantAction($this->request(['editTable' => 'tt_content', 'editUid' => '10']))->getBody();

        self::assertStringContainsString('data-aqt-assistant-root', $html);
        self::assertStringContainsString('Hours &lt;b onmouseover=x&gt;', $html);
        self::assertStringNotContainsString('<b onmouseover', $html);
        self::assertStringContainsString('data-aqt-ask-input', $html);
        self::assertStringNotContainsString('data-aqt-action="explain"', $html, 'no AI action without AI');
    }

    #[Test]
    public function theDropdownListsNoFindingsButAnswersGroupThemWithTheirOccurrences(): void
    {
        foreach ([20, 21, 22, 23] as $uid) {
            $this->insertIssue(['uid' => $uid, 'site_identifier' => self::SITE, 'page_uid' => 2, 'source_table' => 'pages', 'source_uid' => 2, 'source_field' => '', 'source_type' => 'rendered', 'severity' => 2, 'rule_id' => 'rendered.landmark_unique', 'context_path' => 'nav#' . $uid]);
        }
        $this->asUser(2);

        $html = (string)$this->get(ToolbarScanController::class)->assistantAction($this->request(['id' => '2', 'language' => '0']))->getBody();
        foreach (['Fix first', 'Also open', 'aqt-finding', 'aqt-others', 'data-aqt-finding'] as $removed) {
            self::assertStringNotContainsString($removed, $html, 'no automatic findings list on open');
        }
        self::assertStringContainsString('data-aqt-ask-input', $html);

        $answer = $this->ask(['id' => '2', 'language' => '0'], 'first');
        // Six readable occurrences: the finding of the missing record 99 is withheld (Sol QA-02).
        self::assertSame(['issue_types' => 3, 'occurrences' => 6], $answer['totals']);
        $landmark = array_values(array_filter($answer['citations'], static fn (array $citation): bool => str_contains((string)$citation['detailUrl'], 'landmark_unique')));
        self::assertCount(1, $landmark, 'four identical findings are one group');
        self::assertSame(4, $landmark[0]['count']);
    }

    #[Test]
    public function aFreeTextQuestionWithoutAiIsAnsweredFromTheFindings(): void
    {
        $this->asUser(1);
        $response = $this->get(ToolbarScanController::class)->askAction($this->request([])->withMethod('POST')->withBody($this->jsonBody(['question' => 'Why does this matter for screen readers?', 'scope' => 'page', 'context' => ['id' => '2']])));
        $payload = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['aqg', 'why'], [$payload['source'], $payload['intent']]);
        self::assertContains('Answered from your AQG findings without AI.', $payload['notes']);
        self::assertSame(0, (int)$this->getConnectionPool()->getConnectionForTable('tx_a11y_ai_usage')->count('*', 'tx_a11y_ai_usage', []));
    }

    #[Test]
    public function withoutAiEveryQuestionIsAnsweredFromTheFindingsAndNothingIsSent(): void
    {
        $this->asUser(1);
        $controller = $this->get(ToolbarScanController::class);

        $quick = $controller->askAction($this->request([])->withMethod('POST')->withBody($this->jsonBody(['intent' => 'first', 'question' => 'What should I fix first?', 'context' => ['id' => '2']])));
        $payload = json_decode((string)$quick->getBody(), true);
        self::assertSame(200, $quick->getStatusCode(), 'no licence needed for answers from the findings');
        self::assertSame(['aqg', 'answer'], [$payload['source'], $payload['status']]);
        self::assertStringNotContainsString('secret', (string)$quick->getBody());
        self::assertSame(0, (int)$this->getConnectionPool()->getConnectionForTable('tx_a11y_ai_usage')->count('*', 'tx_a11y_ai_usage', []), 'no AI usage row');

        $free = $controller->askAction($this->request([])->withMethod('POST')->withBody($this->jsonBody(['question' => 'Which images need attention?', 'scope' => 'page', 'context' => ['id' => '2']])));
        self::assertSame(200, $free->getStatusCode(), 'without a licence a free question is answered from the findings, not refused');
        self::assertSame('aqg', json_decode((string)$free->getBody(), true)['source']);
        self::assertSame(0, (int)$this->getConnectionPool()->getConnectionForTable('tx_a11y_ai_usage')->count('*', 'tx_a11y_ai_usage', []), 'still no AI usage');
    }

    #[Test]
    public function everyQuestionChecksTheEditorsAccessAgain(): void
    {
        $this->asUser(2);
        $controller = $this->get(ToolbarScanController::class);
        $ask = fn () => $controller->askAction($this->request([])->withMethod('POST')->withBody($this->jsonBody(['intent' => 'first', 'context' => ['id' => '2']])));
        self::assertSame(200, $ask()->getStatusCode());

        // The page is withdrawn from everybody between two questions.
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 2]);
        $refused = $ask();
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame('no_context', json_decode((string)$refused->getBody(), true)['code']);
        self::assertStringNotContainsString('Opening hours', (string)$refused->getBody());
    }

    #[Test]
    public function answersChangeNoContent(): void
    {
        $this->asUser(1);
        $before = $this->getConnectionPool()->getConnectionForTable('tt_content')->select(['uid', 'header', 'bodytext'], 'tt_content')->fetchAllAssociative();
        foreach (['first', 'why', 'where', 'site'] as $intent) {
            $this->get(ToolbarScanController::class)->askAction($this->request([])->withMethod('POST')->withBody($this->jsonBody(['intent' => $intent, 'context' => ['id' => '2']])));
        }
        self::assertSame($before, $this->getConnectionPool()->getConnectionForTable('tt_content')->select(['uid', 'header', 'bodytext'], 'tt_content')->fetchAllAssociative());
    }

    #[Test]
    public function aConversationsSpendIsSummedFromItsOwnUsageRowsOnly(): void
    {
        $usage = $this->getConnectionPool()->getConnectionForTable('tx_a11y_ai_usage');
        $now = time();
        foreach ([
            [2, 'aaaaaaaaaaaaaaaaaaaaaaaa', 1200, 1, $now],
            [2, 'aaaaaaaaaaaaaaaaaaaaaaaa', 800, 1, $now],
            [2, 'aaaaaaaaaaaaaaaaaaaaaaaa', 0, 0, $now],
            [3, 'aaaaaaaaaaaaaaaaaaaaaaaa', 5000, 1, $now],      // another user, same ref
            [2, 'bbbbbbbbbbbbbbbbbbbbbbbb', 5000, 1, $now],      // another conversation
            [2, 'aaaaaaaaaaaaaaaaaaaaaaaa', 5000, 1, $now - 99999], // before the conversation was issued
        ] as [$user, $ref, $cost, $known, $created]) {
            $usage->insert('tx_a11y_ai_usage', ['site_identifier' => self::SITE, 'be_user' => $user, 'feature' => 'assistant', 'conversation_ref' => $ref, 'estimated_cost_micro_usd' => $cost, 'cost_known' => $known, 'crdate' => $created]);
        }

        $spent = $this->get(\Priebera\A11yQualityGate\Domain\Repository\AiUsageRepository::class)->summarizeConversation(self::SITE, 2, 'aaaaaaaaaaaaaaaaaaaaaaaa', $now - 60);

        self::assertSame(['requests' => 3, 'cost_micro_usd' => 2000, 'cost_unknown' => 1], $spent);
    }

    #[Test]
    public function askingNeedsTheModuleAndAPost(): void
    {
        $this->asUser(3);
        $denied = $this->get(ToolbarScanController::class)->askAction($this->request([])->withMethod('POST')->withBody($this->jsonBody(['question' => 'x?'])));
        self::assertSame(403, $denied->getStatusCode());

        $this->asUser(2);
        self::assertSame(405, $this->get(ToolbarScanController::class)->askAction($this->request(['question' => 'What now?']))->getStatusCode());
    }

    #[Test]
    public function aWebsiteAnswerNeedsTheSiteRootAndCountsOnlyReadablePages(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 12, 'title' => 'Open page only', 'groupMods' => 'web_a11y', 'db_mountpoints' => '2',
            'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => '0',
        ]);
        $this->getConnectionPool()->getConnectionForTable('be_users')->insert('be_users', ['uid' => 5, 'username' => 'subtree', 'admin' => 0, 'usergroup' => '12', 'disable' => 0, 'deleted' => 0]);
        $ask = $this->get(\Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgService::class);
        $repository = $this->get(\Priebera\A11yQualityGate\Domain\Repository\IssueRepository::class);
        $scope = $this->get(\Priebera\A11yQualityGate\Service\ScopeAccessService::class);

        $this->asUser(5);
        self::assertFalse($ask->canAskSite($this->get(ToolbarAssistantContextResolver::class)->resolve($this->request(['id' => '2']))), 'the root page is outside the mount');

        $this->asUser(2);
        self::assertTrue($ask->canAskSite($this->get(ToolbarAssistantContextResolver::class)->resolve($this->request(['id' => '2']))));
        $summary = $repository->summarizeOpenByRuleForSite(self::SITE, 0, $scope->readablePagesClause());
        $alt = array_values(array_filter($summary, static fn (array $row): bool => $row['rule_id'] === 'rte.img_alt_missing'));
        self::assertSame(1, $alt[0]['occurrences'], 'the finding on the page the editor may not show is not counted');
        self::assertSame('Open page', $alt[0]['top_page_title']);

        $this->asUser(1);
        $all = $repository->summarizeOpenByRuleForSite(self::SITE, 0, $scope->readablePagesClause());
        self::assertSame(2, array_values(array_filter($all, static fn (array $row): bool => $row['rule_id'] === 'rte.img_alt_missing'))[0]['occurrences']);
    }

    private function jsonBody(array $data): \TYPO3\CMS\Core\Http\Stream
    {
        $stream = new \TYPO3\CMS\Core\Http\Stream('php://temp', 'rw');
        $stream->write(json_encode($data, JSON_THROW_ON_ERROR));
        $stream->rewind();

        return $stream;
    }

    /**
     * "Check saved page now" in the expanded Content scan section: offered with the original gate (Scan now and edit
     * access to the open page), for the page or the page of the open element, and never for pages the user may only
     * show, may not open, or without Scan now. The scan route checks both again.
     */
    #[Test]
    public function checkSavedPageNowIsOfferedOnlyForAPageTheUserMayScan(): void
    {
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 5, 'pid' => 1, 'title' => 'Show only', 'slug' => '/p5', 'doktype' => 1, 'is_siteroot' => 0,
            'perms_userid' => 99, 'perms_groupid' => 99, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 1, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 12, 'title' => 'Editors without Scan now', 'groupMods' => 'web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => '0',
            'TSconfig' => "options.a11y_quality_gate.showScanNow = 0\n",
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 5, 'username' => 'noscan', 'admin' => 0, 'usergroup' => '12', 'disable' => 0, 'deleted' => 0]);

        $this->asUser(2);
        self::assertSame(['pageUid' => 2, 'languageUid' => 0], $this->assistant(['id' => '2', 'language' => '0'])['contentScan']);
        self::assertSame(2, $this->assistant(['editTable' => 'tt_content', 'editUid' => '10'])['contentScan']['pageUid'], 'the page of the open element');
        self::assertSame(0, $this->assistant(['id' => '5'])['contentScan']['pageUid'], 'show permission only: no scan');
        self::assertSame(0, $this->assistant(['id' => '3'])['contentScan']['pageUid'], 'a page the editor may not open');

        $this->asUser(5);
        self::assertSame(0, $this->assistant(['id' => '2'])['contentScan']['pageUid'], 'without Scan now');

        $this->asUser(1);
        self::assertSame(3, $this->assistant(['id' => '3'])['contentScan']['pageUid']);
    }

    /**
     * The action sits inside the collapsed Content scan section (hidden until the assistant's offer shows it); the
     * Frontend scan section keeps its status and progress only, and nothing lists findings when the dropdown opens.
     */
    #[Test]
    public function theContentScanSectionCarriesTheActionAndTheDropdownListsNoFindings(): void
    {
        $this->asUser(2);
        $item = $this->get(A11yScanToolbarItem::class);
        $item->setRequest($this->request(['id' => '2']));
        $html = $item->getDropDown();

        self::assertMatchesRegularExpression('/id="aqt-scan-panel-content"[^>]*hidden="hidden".*data-aqt-content-scan="true"[^>]*hidden="hidden".*data-aqt-content-scan-button="true"/s', $html);
        $frontendPanel = (string)strstr($html, 'id="aqt-scan-panel-frontend"');
        self::assertStringNotContainsString('data-aqt-content-scan', $frontendPanel);
        foreach (['Fix first', 'Also open', 'data-aqt-finding'] as $removed) {
            self::assertStringNotContainsString($removed, $html);
        }
        $assistant = (string)$this->get(ToolbarScanController::class)->assistantAction($this->request(['id' => '2', 'language' => '0']))->getBody();
        self::assertStringContainsString('data-content-scan-page="2"', $assistant);
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function assistant(array $query): array
    {
        $context = $this->get(ToolbarAssistantContextResolver::class)->resolve($this->request($query));
        self::assertInstanceOf(ToolbarAssistantContext::class, $context);

        return $this->get(ToolbarAssistantService::class)->build($context);
    }

    /**
     * @param array<string, string> $query
     * @return list<array<string, mixed>>
     */
    private function issues(array $query): array
    {
        return $this->get(ToolbarAssistantService::class)->openIssues($this->get(ToolbarAssistantContextResolver::class)->resolve($this->request($query)));
    }

    /**
     * @param array<string, string> $query
     * @return array<string, mixed>
     */
    private function ask(array $query, string $intent): array
    {
        return $this->get(\Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgService::class)
            ->answerDeterministically($this->get(ToolbarAssistantContextResolver::class)->resolve($this->request($query)), $intent);
    }

    /**
     * @param array<string, string> $query
     */
    private function request(array $query): ServerRequest
    {
        $request = (new ServerRequest('https://a.test/typo3/ajax/a11y/toolbar/assistant'))
            ->withQueryParams($query)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    /** A backend request always has the user's language service (record titles need it). */
    private function asUser(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }
}
