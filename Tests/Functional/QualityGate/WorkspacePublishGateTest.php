<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\QualityGate;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\EventListener\WorkspacePublishGateHookOrder;
use Priebera\A11yQualityGate\Hook\WorkspacePublishGateHook;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\QualityGate\Contract\GateLicenceInterface;
use Priebera\A11yQualityGate\QualityGate\QualityGateChecker;
use Priebera\A11yQualityGate\QualityGate\WorkspacePublishEvaluator;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Workspace\PublishPreviewContext;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The Quality Gate for workspace publishing, with EXT:workspaces and the real DataHandler: a publish that makes a page
 * visible is judged on the content this very publish puts live, before anything is written. A blocked publish changes
 * no live record and keeps every workspace version; an allowed one publishes as TYPO3 always does.
 */
final class WorkspacePublishGateTest extends AbstractFunctionalTestCase
{
    private const SITE = 'fixture';
    private const ROOT = 1;
    private const PAGE = 2;
    private const CONTENT = 10;
    private const WORKSPACE = 1;
    private const CLEAN = '<p>Opening hours are listed below.</p>';
    private const CRITICAL = '<p><a href="/contact"></a></p>';

    protected array $coreExtensionsToLoad = ['backend', 'scheduler', 'rte_ckeditor', 'workspaces'];

    private BackendUserAuthentication $backendUser;
    public bool $licenceValid = true;

    protected function setUp(): void
    {
        if (!class_exists(\TYPO3\CMS\Workspaces\Hook\DataHandlerHook::class)) {
            self::markTestSkipped('EXT:workspaces is not installed in this TYPO3 instance.');
        }
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $pool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => self::WORKSPACE, 'title' => 'Launch', 'adminusers' => 'be_users_1']);
        $pages = $pool->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => self::ROOT, 'pid' => 0, 'title' => 'Fixture', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => self::PAGE, 'pid' => self::ROOT, 'title' => 'Launch page', 'slug' => '/launch', 'doktype' => 1, 'hidden' => 1]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => self::CONTENT, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Hours', 'bodytext' => self::CLEAN]);
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text', 'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);
        $this->gateMode(2);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, self::ROOT, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->backendUser = $this->setUpBackendUser(1);
        $this->backendUser->setWorkspace(self::WORKSPACE);
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(self::WORKSPACE));

        // Only the licence answer is fixed; everything else is the real gate.
        $licence = new class ($this) implements GateLicenceInterface {
            public function __construct(private readonly WorkspacePublishGateTest $test) {}

            public function canBlock(Site $site): bool
            {
                return $this->test->licenceValid;
            }
        };
        $this->getContainer()->set(WorkspacePublishGateHook::class, new WorkspacePublishGateHook(
            GeneralUtility::makeInstance(ConnectionPool::class),
            $this->get(PublishPreviewContext::class),
            $this->get(WorkspacePublishEvaluator::class),
            $this->get(QualityGateChecker::class),
            $this->get(SiteResolutionService::class),
            $licence,
            $this->get(BackendContextService::class),
        ));
    }

    protected function tearDown(): void
    {
        // Skipped before the instance was set up: nothing to tear down.
        if (class_exists(\TYPO3\CMS\Workspaces\Hook\DataHandlerHook::class)) {
            parent::tearDown();
        }
    }

    #[Test]
    public function theGateRunsAfterTheWorkspacesHookResolvedTheCommandMap(): void
    {
        $hooks = array_keys($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']);

        self::assertSame(WorkspacePublishGateHookOrder::HOOK_KEY, end($hooks));
        self::assertLessThan(array_search(WorkspacePublishGateHookOrder::HOOK_KEY, $hooks, true), array_search('workspaces', $hooks, true));
    }

    #[Test]
    public function aPublishThatWouldShowACriticalIssueIsStoppedAndChangesNothingLive(): void
    {
        $this->edit(['pages' => [self::PAGE => ['hidden' => 0]], 'tt_content' => [self::CONTENT => ['bodytext' => self::CRITICAL]]]);
        $pageVersion = $this->versionOf('pages', self::PAGE);
        $contentVersion = $this->versionOf('tt_content', self::CONTENT);
        $liveBefore = [$this->row('pages', self::PAGE), $this->row('tt_content', self::CONTENT)];

        $errors = $this->publish(['pages' => [self::PAGE => $pageVersion], 'tt_content' => [self::CONTENT => $contentVersion]]);

        self::assertSame($liveBefore, [$this->row('pages', self::PAGE), $this->row('tt_content', self::CONTENT)], 'No live record changed.');
        self::assertSame(self::WORKSPACE, (int)$this->row('pages', $pageVersion)['t3ver_wsid'], 'The page version is still a draft.');
        self::assertSame(self::WORKSPACE, (int)$this->row('tt_content', $contentVersion)['t3ver_wsid'], 'The content version is still a draft.');
        self::assertCount(1, $errors);
        self::assertStringContainsString('AQG Quality Gate: publishing stopped for page "Launch page"', $errors[0]);
        self::assertStringContainsString('1 critical issue exceeds threshold 0', $errors[0]);
        self::assertSame(0, $this->countRows('tx_a11y_issue'), 'The check stores nothing.');
    }

    #[Test]
    public function aCleanPublishGoesLiveAsTypo3PublishesIt(): void
    {
        $this->edit(['pages' => [self::PAGE => ['hidden' => 0]], 'tt_content' => [self::CONTENT => ['bodytext' => '<p>New opening hours.</p>']]]);

        $errors = $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)], 'tt_content' => [self::CONTENT => $this->versionOf('tt_content', self::CONTENT)]]);

        self::assertSame([], $errors);
        self::assertSame(0, (int)$this->row('pages', self::PAGE)['hidden'], 'The page is live.');
        self::assertSame('<p>New opening hours.</p>', $this->row('tt_content', self::CONTENT)['bodytext']);
        self::assertSame(0, $this->countRows('pages', ['t3ver_wsid' => self::WORKSPACE]) + $this->countRows('tt_content', ['t3ver_wsid' => self::WORKSPACE]), 'No draft is left.');
    }

    #[Test]
    public function onlyWhatThisPublishPutsLiveIsJudged(): void
    {
        // The broken text stays a draft: making the page visible publishes it with its clean live content.
        $this->edit(['pages' => [self::PAGE => ['hidden' => 0]], 'tt_content' => [self::CONTENT => ['bodytext' => self::CRITICAL]]]);
        $contentVersion = $this->versionOf('tt_content', self::CONTENT);

        $errors = $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)]]);

        self::assertSame([], $errors);
        self::assertSame(0, (int)$this->row('pages', self::PAGE)['hidden']);
        self::assertSame(self::CLEAN, $this->row('tt_content', self::CONTENT)['bodytext']);
        self::assertSame(self::WORKSPACE, (int)$this->row('tt_content', $contentVersion)['t3ver_wsid']);
    }

    #[Test]
    public function aNewPageWithANewBrokenElementIsStoppedAndStaysADraft(): void
    {
        $this->edit([
            'pages' => ['NEW_page' => ['pid' => self::ROOT, 'title' => 'Campaign', 'hidden' => 0]],
            'tt_content' => ['NEW_content' => ['pid' => 'NEW_page', 'CType' => 'text', 'header' => 'Offer', 'bodytext' => self::CRITICAL]],
        ]);
        $page = (int)$this->connection('pages')->fetchOne('SELECT uid FROM pages WHERE title = ? AND t3ver_wsid = ?', ['Campaign', self::WORKSPACE]);
        $content = (int)$this->connection('tt_content')->fetchOne('SELECT uid FROM tt_content WHERE pid = ? AND t3ver_wsid = ?', [$page, self::WORKSPACE]);

        $errors = $this->publish(['pages' => [$page => $page], 'tt_content' => [$content => $content]]);

        self::assertStringContainsString('page "Campaign"', $errors[0] ?? '');
        self::assertSame([self::WORKSPACE, self::WORKSPACE], [(int)$this->row('pages', $page)['t3ver_wsid'], (int)$this->row('tt_content', $content)['t3ver_wsid']]);
        self::assertSame(0, $this->countRows('pages', ['title' => 'Campaign', 't3ver_wsid' => 0]));
    }

    #[Test]
    public function aTranslationIsJudgedInItsLanguageAndStaysHiddenWhenStopped(): void
    {
        $this->connection('pages')->insert('pages', ['uid' => 4, 'pid' => self::ROOT, 'title' => 'Startseite Launch', 'slug' => '/launch', 'doktype' => 1, 'hidden' => 1, 'sys_language_uid' => 1, 'l10n_parent' => self::PAGE]);
        $this->connection('tt_content')->insert('tt_content', ['uid' => 12, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Zeiten', 'bodytext' => self::CLEAN, 'sys_language_uid' => 1, 'l18n_parent' => self::CONTENT]);
        $this->edit(['pages' => [4 => ['hidden' => 0]], 'tt_content' => [12 => ['bodytext' => self::CRITICAL]]]);
        $live = [$this->row('pages', 4), $this->row('tt_content', 12), $this->row('pages', self::PAGE)];

        $errors = $this->publish(['pages' => [4 => $this->versionOf('pages', 4)], 'tt_content' => [12 => $this->versionOf('tt_content', 12)]]);

        self::assertStringContainsString('page "Startseite Launch"', $errors[0] ?? '');
        self::assertSame($live, [$this->row('pages', 4), $this->row('tt_content', 12), $this->row('pages', self::PAGE)]);

        // Publishing only the translated page leaves the broken text a draft: the translation goes live with its clean
        // live content.
        self::assertSame([], $this->publish(['pages' => [4 => $this->versionOf('pages', 4)]]));
        self::assertSame([0, self::CLEAN], [(int)$this->row('pages', 4)['hidden'], $this->row('tt_content', 12)['bodytext']]);
        self::assertSame(self::WORKSPACE, (int)$this->row('tt_content', $this->versionOf('tt_content', 12))['t3ver_wsid']);
    }

    #[Test]
    public function aLiveTranslationThatGoesOnlineWithItsDefaultPageIsJudgedInItsLanguage(): void
    {
        // ML-05: the German translation is live and not hidden; only its hidden default page kept it offline.
        $this->addSiteLanguage(1, 'German', 'de');
        $this->connection('pages')->insert('pages', ['uid' => 4, 'pid' => self::ROOT, 'title' => 'Startseite Launch', 'slug' => '/launch', 'doktype' => 1, 'hidden' => 0, 'sys_language_uid' => 1, 'l10n_parent' => self::PAGE]);
        $this->connection('tt_content')->insert('tt_content', ['uid' => 12, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Zeiten', 'bodytext' => self::CRITICAL, 'sys_language_uid' => 1, 'l18n_parent' => self::CONTENT]);
        $this->edit(['pages' => [self::PAGE => ['hidden' => 0]]]);
        $live = [$this->row('pages', self::PAGE), $this->row('pages', 4)];

        $errors = $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)]]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('page "Startseite Launch"', $errors[0]);
        self::assertSame($live, [$this->row('pages', self::PAGE), $this->row('pages', 4)], 'Blocked: the default page stays hidden.');

        // A hidden translation stays offline with the default page and is not judged.
        $this->connection('pages')->update('pages', ['hidden' => 1], ['uid' => 4]);
        self::assertSame([], $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)]]));
        self::assertSame(0, (int)$this->row('pages', self::PAGE)['hidden']);
    }

    #[Test]
    public function aLiveTranslationThatThisPublishDeletesIsNotJudged(): void
    {
        $this->addSiteLanguage(1, 'German', 'de');
        $this->connection('pages')->insert('pages', ['uid' => 4, 'pid' => self::ROOT, 'title' => 'Startseite Launch', 'slug' => '/launch', 'doktype' => 1, 'hidden' => 0, 'sys_language_uid' => 1, 'l10n_parent' => self::PAGE]);
        $this->connection('tt_content')->insert('tt_content', ['uid' => 12, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Zeiten', 'bodytext' => self::CRITICAL, 'sys_language_uid' => 1, 'l18n_parent' => self::CONTENT]);
        $this->edit(['pages' => [self::PAGE => ['hidden' => 0]]]);
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], ['pages' => [4 => ['delete' => 1]]], $this->backendUser);
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog, 'The workspace delete itself succeeds.');

        $errors = $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE), 4 => $this->versionOf('pages', 4)]]);

        self::assertSame([], $errors);
        self::assertSame(0, (int)$this->row('pages', self::PAGE)['hidden']);
    }

    #[Test]
    public function aLiveTranslationInALanguageTheSiteDoesNotServeIsNotJudged(): void
    {
        $this->connection('pages')->insert('pages', ['uid' => 4, 'pid' => self::ROOT, 'title' => 'Startseite Launch', 'slug' => '/launch', 'doktype' => 1, 'hidden' => 0, 'sys_language_uid' => 1, 'l10n_parent' => self::PAGE]);
        $this->connection('tt_content')->insert('tt_content', ['uid' => 12, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Zeiten', 'bodytext' => self::CRITICAL, 'sys_language_uid' => 1, 'l18n_parent' => self::CONTENT]);
        $this->edit(['pages' => [self::PAGE => ['hidden' => 0]]]);

        self::assertSame([], $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)]]));
        self::assertSame(0, (int)$this->row('pages', self::PAGE)['hidden']);
    }

    #[Test]
    public function onlyTheFailingTranslationIsNamedAndAHiddenTranslationIsNotJudged(): void
    {
        // ML-05, several languages: German is clean, Slovak has a critical issue, French is hidden (with a critical issue).
        $this->addSiteLanguage(1, 'German', 'de');
        $this->addSiteLanguage(3, 'Slovak', 'sk');
        $this->addSiteLanguage(5, 'French', 'fr');
        foreach ([[4, 1, 'Startseite', 0, 12, self::CLEAN], [5, 3, 'Domov', 0, 13, self::CRITICAL], [6, 5, 'Accueil', 1, 14, self::CRITICAL]] as [$page, $language, $title, $hidden, $content, $body]) {
            $this->connection('pages')->insert('pages', ['uid' => $page, 'pid' => self::ROOT, 'title' => $title, 'slug' => '/launch', 'doktype' => 1, 'hidden' => $hidden, 'sys_language_uid' => $language, 'l10n_parent' => self::PAGE]);
            $this->connection('tt_content')->insert('tt_content', ['uid' => $content, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'T', 'bodytext' => $body, 'sys_language_uid' => $language, 'l18n_parent' => self::CONTENT]);
        }
        $this->edit(['pages' => [self::PAGE => ['hidden' => 0]]]);
        $live = array_map(fn (int $uid): array => $this->row('pages', $uid), [self::PAGE, 4, 5, 6]);

        $errors = $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)]]);

        self::assertCount(1, $errors);
        self::assertStringContainsString('page "Domov"', $errors[0]);
        self::assertSame($live, array_map(fn (int $uid): array => $this->row('pages', $uid), [self::PAGE, 4, 5, 6]), 'Blocked: nothing changed live.');

        // Warn: the publish happens and only the Slovak translation is reported.
        $this->gateMode(1);
        $queue = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessageService::class)->getMessageQueueByIdentifier();
        $queue->clear();

        self::assertSame([], $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)]]));
        self::assertSame([0, 0, 0, 1], array_map(fn (int $uid): int => (int)$this->row('pages', $uid)['hidden'], [self::PAGE, 4, 5, 6]));
        $messages = implode("\n", array_map(static fn ($m): string => $m->getMessage(), $queue->getAllMessages()));
        self::assertStringContainsString('"Domov"', $messages);
        self::assertStringNotContainsString('"Startseite"', $messages);
        self::assertStringNotContainsString('"Accueil"', $messages);
    }

    #[Test]
    public function unhidingOneTranslationDoesNotJudgeTheOtherLiveTranslations(): void
    {
        // The default page is already online; the Slovak translation is online with a critical issue (not this
        // publish's business). Publishing the clean German translation visible must not be stopped by Slovak.
        $this->addSiteLanguage(1, 'German', 'de');
        $this->addSiteLanguage(3, 'Slovak', 'sk');
        $this->connection('pages')->update('pages', ['hidden' => 0], ['uid' => self::PAGE]);
        $this->connection('pages')->insert('pages', ['uid' => 4, 'pid' => self::ROOT, 'title' => 'Startseite', 'slug' => '/launch', 'doktype' => 1, 'hidden' => 1, 'sys_language_uid' => 1, 'l10n_parent' => self::PAGE]);
        $this->connection('pages')->insert('pages', ['uid' => 5, 'pid' => self::ROOT, 'title' => 'Domov', 'slug' => '/launch', 'doktype' => 1, 'hidden' => 0, 'sys_language_uid' => 3, 'l10n_parent' => self::PAGE]);
        $this->connection('tt_content')->insert('tt_content', ['uid' => 12, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'T', 'bodytext' => self::CLEAN, 'sys_language_uid' => 1, 'l18n_parent' => self::CONTENT]);
        $this->connection('tt_content')->insert('tt_content', ['uid' => 13, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'T', 'bodytext' => self::CRITICAL, 'sys_language_uid' => 3, 'l18n_parent' => self::CONTENT]);
        $this->edit(['pages' => [4 => ['hidden' => 0]]]);

        self::assertSame([], $this->publish(['pages' => [4 => $this->versionOf('pages', 4)]]));
        self::assertSame([0, 0], [(int)$this->row('pages', 4)['hidden'], (int)$this->row('pages', 5)['hidden']]);
    }

    #[Test]
    public function anIgnoredFindingOfALiveTranslationDoesNotStopThePublish(): void
    {
        $this->addSiteLanguage(1, 'German', 'de');
        $this->connection('pages')->insert('pages', ['uid' => 4, 'pid' => self::ROOT, 'title' => 'Startseite', 'slug' => '/launch', 'doktype' => 1, 'hidden' => 0, 'sys_language_uid' => 1, 'l10n_parent' => self::PAGE]);
        $this->connection('tt_content')->insert('tt_content', ['uid' => 12, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'T', 'bodytext' => self::CRITICAL, 'sys_language_uid' => 1, 'l18n_parent' => self::CONTENT]);
        $this->backendUser->setWorkspace(0);
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        $this->get(ScanOrchestrator::class)->scanPage(self::SITE, self::PAGE, 1);
        self::assertGreaterThan(0, $this->countRows('tx_a11y_issue', ['source_lang_uid' => 1, 'status' => 0]));
        $this->connection('tx_a11y_issue')->update('tx_a11y_issue', ['status' => 2, 'ignored_until' => 0], ['page_uid' => self::PAGE, 'source_lang_uid' => 1]);
        $this->backendUser->setWorkspace(self::WORKSPACE);
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(self::WORKSPACE));
        $this->edit(['pages' => [self::PAGE => ['hidden' => 0]]]);

        self::assertSame([], $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)]]));
        self::assertSame([0, 0], [(int)$this->row('pages', self::PAGE)['hidden'], (int)$this->row('pages', 4)['hidden']]);
    }

    #[Test]
    public function inWarningModeOrWithoutLicenceThePublishHappens(): void
    {
        foreach ([[1, true], [2, false]] as [$mode, $licence]) {
            $this->gateMode($mode);
            $this->licenceValid = $licence;
            $this->connection('pages')->update('pages', ['hidden' => 1], ['uid' => self::PAGE]);
            $this->edit(['pages' => [self::PAGE => ['hidden' => 0]], 'tt_content' => [self::CONTENT => ['bodytext' => self::CRITICAL]]]);

            $errors = $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)], 'tt_content' => [self::CONTENT => $this->versionOf('tt_content', self::CONTENT)]]);

            self::assertSame([], $errors, 'mode ' . $mode);
            self::assertSame([0, self::CRITICAL], [(int)$this->row('pages', self::PAGE)['hidden'], $this->row('tt_content', self::CONTENT)['bodytext']]);
            $this->connection('tt_content')->update('tt_content', ['bodytext' => self::CLEAN], ['uid' => self::CONTENT]);
        }
    }

    #[Test]
    public function anIgnoredFindingDoesNotStopThePublish(): void
    {
        // The finding of the live element was ignored by an editor; the same element goes live unchanged.
        $this->connection('tt_content')->update('tt_content', ['bodytext' => self::CRITICAL], ['uid' => self::CONTENT]);
        $this->backendUser->setWorkspace(0);
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(0));
        $this->get(ScanOrchestrator::class)->scanPage(self::SITE, self::PAGE, 0);
        $this->connection('tx_a11y_issue')->update('tx_a11y_issue', ['status' => 2, 'ignored_until' => 0], ['page_uid' => self::PAGE]);
        $this->backendUser->setWorkspace(self::WORKSPACE);
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(self::WORKSPACE));
        $this->edit(['pages' => [self::PAGE => ['hidden' => 0]]]);

        $errors = $this->publish(['pages' => [self::PAGE => $this->versionOf('pages', self::PAGE)]]);

        self::assertSame([], $errors);
        self::assertSame(0, (int)$this->row('pages', self::PAGE)['hidden']);
    }

    #[Test]
    public function theCountsBeforePublishingEqualWhatAContentScanStoresForTheSameContent(): void
    {
        $this->connection('tt_content')->update('tt_content', ['bodytext' => self::CRITICAL . '<p><a href="/a">click here</a></p><img src="x.png">'], ['uid' => self::CONTENT]);
        $this->backendUser->setWorkspace(0);
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect(0));

        $before = $this->get(WorkspacePublishEvaluator::class)->countsFor(self::SITE, self::PAGE, 0);
        $this->get(ScanOrchestrator::class)->scanPage(self::SITE, self::PAGE, 0);
        $stored = $this->get(IssueRepository::class)->countOpenBySeverity(self::PAGE, self::SITE, 0);

        self::assertGreaterThan(1, array_sum($stored));
        self::assertSame($stored, $before);
    }

    private function addSiteLanguage(int $languageId, string $title, string $code): void
    {
        $configuration = $this->get(SiteFinder::class)->getSiteByIdentifier(self::SITE)->getConfiguration();
        $configuration['languages'][] = [
            'languageId' => $languageId,
            'title' => $title,
            'enabled' => true,
            'base' => '/' . $code . '/',
            'locale' => $code . '_' . strtoupper($code) . '.UTF-8',
            'navigationTitle' => $title,
            'flag' => $code,
        ];
        $this->get(SiteWriter::class)->write(self::SITE, $configuration);
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    private function gateMode(int $mode): void
    {
        $rulesets = $this->connection('tx_a11y_ruleset');
        $rulesets->delete('tx_a11y_ruleset', ['is_default' => 1]);
        $rulesets->insert('tx_a11y_ruleset', ['is_default' => 1, 'is_global' => 1, 'publish_mode' => $mode, 'threshold_critical' => 0, 'threshold_warning' => -1]);
    }

    /** @param array<string, array<int|string, array<string, mixed>>> $data */
    private function edit(array $data): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, [], $this->backendUser);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, 'The workspace edit itself succeeds.');
    }

    /**
     * Publishes like the Workspaces module ("version" → "publish" per record) and returns the DataHandler errors.
     *
     * @param array<string, array<int, int>> $records table → live uid (or new uid) → version uid
     * @return list<string>
     */
    private function publish(array $records): array
    {
        $commands = [];
        foreach ($records as $table => $versions) {
            foreach ($versions as $liveUid => $versionUid) {
                $commands[$table][$liveUid]['version'] = ['action' => 'publish', 'swapWith' => $versionUid];
            }
        }
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([], $commands, $this->backendUser);
        $dataHandler->process_cmdmap();

        return array_values($dataHandler->errorLog);
    }

    private function versionOf(string $table, int $liveUid): int
    {
        return (int)$this->connection($table)->fetchOne(
            'SELECT uid FROM ' . $table . ' WHERE t3ver_oid = ? AND t3ver_wsid = ?',
            [$liveUid, self::WORKSPACE],
        );
    }

    /** @return array<string, mixed> */
    private function row(string $table, int $uid): array
    {
        // As stored: hidden and draft rows included.
        return $this->connection($table)->fetchAssociative('SELECT * FROM ' . $table . ' WHERE uid = ?', [$uid]) ?: [];
    }

    private function connection(string $table): Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
    }
}
