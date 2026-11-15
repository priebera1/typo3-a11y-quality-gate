<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Security;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\IssueApiController;
use Priebera\A11yQualityGate\Controller\PageDetailController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * G-01 (3.0.0 final security gate): ignore/unignore of a local finding needs current edit authority over the finding's
 * actual target — its record, its language, the page it is stored for — on every entry point (Page Detail single,
 * batch, rule on page, rule on site, unignore; CKEditor a11y_ignore). Request parameters are no proof.
 *
 * Pages: 1 site root, 2 editable, 3 visible but not editable, 20 = German translation of 2 (language 1).
 * Content on 2: 10 (EN), 11 (DE, translation of 10), 13 (EN, editlock), 16 (all languages, -1); on 3: 12 (EN); 15 deleted.
 * Users: 1 admin; 2 editor EN+DE; 3 editor EN only; 4 reader; 5 page editor without tt_content modify;
 *        6 editor EN+DE with settings access (site-wide rule ignore).
 */
final class IgnoreFindingAuthorizationTest extends AbstractFunctionalTestCase
{
    private const SITE = 'g01';
    private const RULE = 'rte.img_alt_missing';
    private const RENDERED_RULE = 'rendered.image_alt_missing';

    /** @var array<string, int> */
    private array $issue = [];

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        $pages = $pool->getConnectionForTable('pages');
        foreach ([[1, 0, 1, 31, 0, 0], [2, 1, 0, 31, 0, 0], [3, 1, 0, 1, 0, 0], [20, 1, 0, 31, 1, 2]] as [$uid, $pid, $root, $everybody, $language, $parent]) {
            $pages->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'slug' => '/p' . $uid, 'doktype' => 1, 'is_siteroot' => $root,
                'sys_language_uid' => $language, 'l10n_parent' => $parent,
                'perms_userid' => 99, 'perms_user' => 31, 'perms_groupid' => 0, 'perms_group' => 0, 'perms_everybody' => $everybody,
            ]);
        }
        $content = $pool->getConnectionForTable('tt_content');
        foreach ([[10, 2, 0, 0, 0, 0], [11, 2, 1, 10, 0, 0], [12, 3, 0, 0, 0, 0], [13, 2, 0, 0, 1, 0], [15, 2, 0, 0, 0, 1], [16, 2, -1, 0, 0, 0]] as [$uid, $pid, $language, $parent, $editlock, $deleted]) {
            $content->insert('tt_content', [
                'uid' => $uid, 'pid' => $pid, 'CType' => 'text', 'bodytext' => '<p><img src="i' . $uid . '.jpg"></p>',
                'sys_language_uid' => $language, 'l18n_parent' => $parent, 'editlock' => $editlock, 'deleted' => $deleted,
            ]);
        }

        $groups = $pool->getConnectionForTable('be_groups');
        $base = ['groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1', 'tables_select' => 'pages,tt_content', 'explicit_allowdeny' => 'tt_content:CType:text'];
        $groups->insert('be_groups', $base + ['uid' => 10, 'title' => 'Editors EN+DE', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => '0,1']);
        $groups->insert('be_groups', $base + ['uid' => 11, 'title' => 'Editors EN', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => '0']);
        $groups->insert('be_groups', $base + ['uid' => 12, 'title' => 'Readers', 'allowed_languages' => '0,1']);
        $groups->insert('be_groups', $base + ['uid' => 13, 'title' => 'Page editors', 'tables_modify' => 'pages', 'allowed_languages' => '0,1']);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        foreach ([[2, 'editor', '10'], [3, 'editor-en', '11'], [4, 'reader', '12'], [5, 'page-editor', '13'], [6, 'site-editor', '10']] as [$uid, $name, $group]) {
            $users->insert('be_users', ['uid' => $uid, 'username' => $name, 'admin' => 0, 'usergroup' => $group, 'disable' => 0, 'deleted' => 0]);
        }

        $finding = fn (string $key, array $values): int => $this->issue[$key] = $this->insertIssue($values + [
            'site_identifier' => self::SITE, 'page_uid' => 2, 'source_lang_uid' => 0, 'source_table' => 'tt_content',
            'source_field' => 'bodytext', 'rule_id' => self::RULE, 'severity' => 1, 'fingerprint' => 'g01-' . $key,
        ]);
        $finding('en', ['source_uid' => 10]);
        $finding('de', ['source_uid' => 11, 'source_lang_uid' => 1]);
        $finding('otherPage', ['source_uid' => 12, 'page_uid' => 3]);
        $finding('locked', ['source_uid' => 13]);
        $finding('deletedRecord', ['source_uid' => 15]);
        $finding('missingRecord', ['source_uid' => 999]);
        $finding('allLanguages', ['source_uid' => 16, 'source_lang_uid' => -1]);
        $finding('pageAllLanguages', ['source_table' => 'pages', 'source_uid' => 2, 'source_lang_uid' => -1, 'source_field' => '__rendered_html', 'rule_id' => self::RENDERED_RULE]);
        // Stale attribution: content 10 lies on page 2, the finding is stored for page 3 (whose gate it counts for).
        $finding('stalePage', ['source_uid' => 10, 'page_uid' => 3, 'fingerprint' => 'g01-stale']);
        $finding('renderedEn', ['source_table' => 'pages', 'source_uid' => 2, 'source_field' => '__rendered_html', 'rule_id' => self::RENDERED_RULE]);
        $finding('renderedDe', ['source_table' => 'pages', 'source_uid' => 2, 'source_lang_uid' => 1, 'source_field' => '__rendered_html', 'rule_id' => self::RENDERED_RULE]);
        // A page source stored for another page: inconsistent provenance.
        $finding('pageMismatch', ['source_table' => 'pages', 'source_uid' => 2, 'page_uid' => 3, 'source_field' => '__rendered_html', 'rule_id' => self::RENDERED_RULE]);
        $finding('ignoredDe', ['source_uid' => 11, 'source_lang_uid' => 1, 'status' => IssueStatus::Ignored->value, 'ignored_by' => 1, 'ignored_reason' => 'Admin decision', 'fingerprint' => 'g01-ignored-de']);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, 1, 'https://g01.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorOfTheFindingsRecordAndLanguageIgnoresAndUnignoresIt(): void
    {
        $this->user(2);
        $controller = $this->get(PageDetailController::class);

        foreach (['en', 'de', 'renderedEn', 'renderedDe'] as $key) {
            $controller->ignoreAction($this->post(['issueUid' => $this->issue[$key], 'reason' => 'Reviewed', 'pageUid' => 2]));
            $row = $this->row($key);
            self::assertSame(IssueStatus::Ignored->value, (int)$row['status'], $key);
            self::assertSame(2, (int)$row['ignored_by'], $key);

            $controller->unignoreAction($this->post(['issueUid' => $this->issue[$key], 'pageUid' => 2]));
            $row = $this->row($key);
            self::assertSame(IssueStatus::Open->value, (int)$row['status'], $key);
            self::assertSame(0, (int)$row['ignored_by'], $key);
        }
    }

    #[Test]
    public function anEditorWithoutTheFindingsLanguageChangesNothing(): void
    {
        $this->user(3);
        $controller = $this->get(PageDetailController::class);
        $before = $this->snapshot();

        $controller->ignoreAction($this->post(['issueUid' => $this->issue['de'], 'reason' => 'Not mine', 'pageUid' => 2]));
        $controller->ignoreAction($this->post(['issueUid' => $this->issue['renderedDe'], 'reason' => 'Not mine', 'pageUid' => 2, 'language' => 0]));
        $controller->unignoreAction($this->post(['issueUid' => $this->issue['ignoredDe'], 'pageUid' => 2]));
        $controller->batchIgnoreAction($this->post(['issueUids' => [$this->issue['de'], $this->issue['renderedDe']], 'reason' => 'Bulk', 'pageUid' => 2, 'siteIdentifier' => self::SITE]));

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function aRuleIgnoreWithoutLanguageOnlyTakesTheEditorsOwnFindings(): void
    {
        // The audit reproduction: "ignore rule on this page" without a language parameter.
        $this->user(3);
        $openDeBefore = $this->openCount(1);

        $this->get(PageDetailController::class)->ignoreRuleOnPageAction($this->post(['ruleId' => self::RULE, 'reason' => 'Decorative', 'pageUid' => 2, 'siteIdentifier' => self::SITE]));

        self::assertSame(IssueStatus::Ignored->value, (int)$this->row('en')['status']);
        foreach (['de', 'locked', 'deletedRecord', 'missingRecord', 'otherPage', 'stalePage'] as $key) {
            self::assertSame(IssueStatus::Open->value, (int)$this->row($key)['status'], $key);
        }
        self::assertSame($openDeBefore, $this->openCount(1), 'the German Quality Gate still counts its findings');
    }

    #[Test]
    public function aFindingOfAnAllLanguagesRecordStaysIgnorableByItsEditors(): void
    {
        // Review correction: TYPO3 grants "all languages" (-1) to every editor of the record; a page row never is -1.
        $this->user(3);
        $controller = $this->get(PageDetailController::class);

        $controller->ignoreAction($this->post(['issueUid' => $this->issue['allLanguages'], 'reason' => 'Shared element', 'pageUid' => 2]));
        self::assertSame(IssueStatus::Ignored->value, (int)$this->row('allLanguages')['status']);
        $controller->unignoreAction($this->post(['issueUid' => $this->issue['allLanguages'], 'pageUid' => 2]));
        self::assertSame(IssueStatus::Open->value, (int)$this->row('allLanguages')['status']);

        $controller->ignoreAction($this->post(['issueUid' => $this->issue['pageAllLanguages'], 'reason' => 'x', 'pageUid' => 2]));
        self::assertSame(IssueStatus::Open->value, (int)$this->row('pageAllLanguages')['status']);

        // A reader of the same record still changes nothing.
        $this->user(4);
        $this->get(PageDetailController::class)->ignoreAction($this->post(['issueUid' => $this->issue['allLanguages'], 'reason' => 'x', 'pageUid' => 2]));
        self::assertSame(IssueStatus::Open->value, (int)$this->row('allLanguages')['status']);
    }

    #[Test]
    public function aPageEditorWithoutRecordRightsCannotIgnoreRecordFindings(): void
    {
        $this->user(5);
        $controller = $this->get(PageDetailController::class);

        $controller->ignoreAction($this->post(['issueUid' => $this->issue['en'], 'reason' => 'Page is mine', 'pageUid' => 2]));
        $controller->ignoreRuleOnPageAction($this->post(['ruleId' => self::RULE, 'reason' => 'Page is mine', 'pageUid' => 2, 'siteIdentifier' => self::SITE, 'language' => -1]));
        self::assertSame(IssueStatus::Open->value, (int)$this->row('en')['status']);
        self::assertSame(IssueStatus::Open->value, (int)$this->row('de')['status']);

        // The page's own rendered findings stay theirs (page edit right).
        $controller->ignoreAction($this->post(['issueUid' => $this->issue['renderedEn'], 'reason' => 'Page is mine', 'pageUid' => 2]));
        self::assertSame(IssueStatus::Ignored->value, (int)$this->row('renderedEn')['status']);
    }

    #[Test]
    public function aReaderChangesNothing(): void
    {
        $this->user(4);
        $controller = $this->get(PageDetailController::class);
        $before = $this->snapshot();

        $controller->ignoreAction($this->post(['issueUid' => $this->issue['en'], 'reason' => 'x', 'pageUid' => 2]));
        $controller->unignoreAction($this->post(['issueUid' => $this->issue['ignoredDe'], 'pageUid' => 2]));
        $controller->ignoreRuleOnPageAction($this->post(['ruleId' => self::RULE, 'reason' => 'x', 'pageUid' => 2, 'siteIdentifier' => self::SITE]));

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function forgedRequestParametersCannotReachAnUnauthorizedFinding(): void
    {
        $this->user(2);
        $controller = $this->get(PageDetailController::class);
        $before = $this->snapshot();

        // Another page's finding claimed for the editable page, an explicit language, a foreign site identifier.
        $controller->ignoreAction($this->post(['issueUid' => $this->issue['otherPage'], 'reason' => 'x', 'pageUid' => 2, 'language' => 0, 'siteIdentifier' => self::SITE]));
        $controller->batchIgnoreAction($this->post(['issueUids' => [$this->issue['otherPage'], $this->issue['stalePage']], 'reason' => 'x', 'pageUid' => 3, 'siteIdentifier' => self::SITE]));
        $controller->ignoreRuleOnPageAction($this->post(['ruleId' => self::RULE, 'reason' => 'x', 'pageUid' => 3, 'siteIdentifier' => self::SITE]));
        // Stale, missing, deleted, locked and mismatched targets on the editable page itself.
        foreach (['stalePage', 'missingRecord', 'deletedRecord', 'locked', 'pageMismatch'] as $key) {
            $controller->ignoreAction($this->post(['issueUid' => $this->issue[$key], 'reason' => 'x', 'pageUid' => 2]));
        }

        self::assertSame($before, $this->snapshot());
    }

    #[Test]
    public function batchIgnoreOnlyChangesTheAuthorizedSelection(): void
    {
        $this->user(3);

        $this->get(PageDetailController::class)->batchIgnoreAction($this->post([
            'issueUids' => [$this->issue['en'], $this->issue['de'], $this->issue['locked'], $this->issue['renderedEn'], $this->issue['renderedDe']],
            'reason' => 'Bulk', 'pageUid' => 2, 'siteIdentifier' => self::SITE, 'language' => -1,
        ]));

        self::assertSame(IssueStatus::Ignored->value, (int)$this->row('en')['status']);
        self::assertSame(IssueStatus::Ignored->value, (int)$this->row('renderedEn')['status']);
        self::assertSame('Bulk', $this->row('en')['ignored_reason']);
        foreach (['de', 'locked', 'renderedDe'] as $key) {
            self::assertSame(IssueStatus::Open->value, (int)$this->row($key)['status'], $key);
            self::assertSame(0, (int)$this->row($key)['ignored_by'], $key);
        }
    }

    #[Test]
    public function aSiteWideRuleIgnoreSkipsFindingsOutsideTheEditorsRights(): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->update('be_groups', ['allowed_languages' => '0'], ['uid' => 10]);
        $this->user(6);

        $this->get(PageDetailController::class)->ignoreRuleOnSiteAction($this->post(['ruleId' => self::RULE, 'reason' => 'Site decision', 'pageUid' => 2, 'siteIdentifier' => self::SITE]));

        self::assertSame(IssueStatus::Ignored->value, (int)$this->row('en')['status']);
        foreach (['de', 'otherPage', 'stalePage', 'locked', 'missingRecord'] as $key) {
            self::assertSame(IssueStatus::Open->value, (int)$this->row($key)['status'], $key);
        }
    }

    #[Test]
    public function administratorsKeepTheirWorkflow(): void
    {
        $this->user(1);
        $controller = $this->get(PageDetailController::class);

        $controller->ignoreAction($this->post(['issueUid' => $this->issue['otherPage'], 'reason' => 'Admin', 'pageUid' => 3]));
        $controller->unignoreAction($this->post(['issueUid' => $this->issue['ignoredDe'], 'pageUid' => 2]));
        $controller->ignoreRuleOnSiteAction($this->post(['ruleId' => self::RULE, 'reason' => 'Admin site', 'pageUid' => 2, 'siteIdentifier' => self::SITE]));

        self::assertSame(IssueStatus::Ignored->value, (int)$this->row('otherPage')['status']);
        foreach (['en', 'de', 'ignoredDe', 'locked', 'stalePage'] as $key) {
            self::assertSame(IssueStatus::Ignored->value, (int)$this->row($key)['status'], $key);
        }
    }

    #[Test]
    public function theEditorEndpointAppliesTheSameRule(): void
    {
        $controller = $this->get(IssueApiController::class);

        $this->user(3);
        self::assertSame(403, $controller->ignoreAction($this->editorRequest('g01-de', 11))->getStatusCode());
        self::assertSame(403, $controller->ignoreAction($this->editorRequest('g01-locked', 13))->getStatusCode());
        self::assertSame(IssueStatus::Open->value, (int)$this->row('de')['status']);
        self::assertSame(IssueStatus::Open->value, (int)$this->row('locked')['status']);

        $this->user(2);
        $response = $controller->ignoreAction($this->editorRequest('g01-de', 11));
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame(IssueStatus::Ignored->value, (int)$this->row('de')['status']);
    }

    // ---------------------------------------------------------------- helpers

    private function user(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y', 'POST'))
            ->withParsedBody($body + ['siteIdentifier' => self::SITE])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function editorRequest(string $fingerprint, int $recordUid): ServerRequest
    {
        $body = json_encode(['fingerprint' => $fingerprint, 'recordUid' => $recordUid, 'fieldName' => 'bodytext', 'siteIdentifier' => self::SITE, 'pageUid' => 2], JSON_THROW_ON_ERROR);
        $request = (new ServerRequest('https://typo3-testing.local/typo3/ajax/a11y/ignore', 'POST'))
            ->withBody((new StreamFactory())->createStream($body))
            ->withHeader('Content-Type', 'application/json')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    /** @return array<string, mixed> */
    private function row(string $key): array
    {
        return (array)$this->findByUid(Tables::ISSUE, $this->issue[$key]);
    }

    /** @return array<int, array<string, mixed>> status and audit columns of every finding */
    private function snapshot(): array
    {
        $rows = $this->getConnectionPool()->getConnectionForTable(Tables::ISSUE)->select(
            ['uid', 'status', 'ignored_reason', 'ignored_by', 'ignored_by_username', 'ignored_at', 'ignored_until', 'tstamp'],
            Tables::ISSUE,
        )->fetchAllAssociative();

        return array_column($rows, null, 'uid');
    }

    private function openCount(int $languageUid): int
    {
        return array_sum($this->createIssueRepository()->countOpenBySeverity(2, self::SITE, $languageUid));
    }
}
