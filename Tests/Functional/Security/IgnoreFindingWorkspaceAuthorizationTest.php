<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Security;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\PageDetailController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * G-01 with EXT:workspaces: the ignore state is the live finding's state (it decides the live publish gate). In a
 * workspace a non-administrator needs live workspace access and the right to edit the record there, its version's stage
 * included. A finding stored for a workspace version row is never a target.
 */
final class IgnoreFindingWorkspaceAuthorizationTest extends AbstractFunctionalTestCase
{
    private const WORKSPACE = 1;
    private const SITE = 'g01ws';

    protected array $coreExtensionsToLoad = ['backend', 'scheduler', 'rte_ckeditor', 'workspaces'];

    private int $finding = 0;
    private int $versionFinding = 0;

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(\TYPO3\CMS\Workspaces\Hook\DataHandlerHook::class)) {
            self::markTestSkipped('EXT:workspaces is not installed in this TYPO3 instance.');
        }
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'perms_everybody' => 31]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 1, 'CType' => 'text', 'bodytext' => '<p><img src="a.jpg"></p>']);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'title' => 'Editors', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'explicit_allowdeny' => 'tt_content:CType:text', 'allowed_languages' => '0',
        ]);
        $users = $pool->getConnectionForTable('be_users');
        // 2: live and custom workspaces; 3: custom workspaces only (no live access).
        $users->insert('be_users', ['uid' => 2, 'username' => 'ws-editor', 'admin' => 0, 'usergroup' => '10', 'workspace_perms' => 1]);
        $users->insert('be_users', ['uid' => 3, 'username' => 'ws-only', 'admin' => 0, 'usergroup' => '10', 'workspace_perms' => 0]);
        $pool->getConnectionForTable('sys_workspace')->insert('sys_workspace', ['uid' => self::WORKSPACE, 'title' => 'Draft', 'members' => 'be_users_2,be_users_3']);

        $this->finding = $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 1, 'source_uid' => 10, 'fingerprint' => 'g01ws-live']);
        $this->versionFinding = $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 1, 'source_uid' => 30, 'fingerprint' => 'g01ws-version']);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, 1, 'https://g01ws.invalid/');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST'], $GLOBALS['LANG']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorWithLiveAccessIgnoresAndUnignoresInLiveAndInTheirWorkspace(): void
    {
        foreach ([0, self::WORKSPACE] as $workspace) {
            $this->enter(2, $workspace);
            $controller = $this->get(PageDetailController::class);

            $controller->ignoreAction($this->post(['issueUid' => $this->finding, 'reason' => 'Reviewed']));
            self::assertSame(IssueStatus::Ignored->value, $this->findingStatus($this->finding), 'workspace ' . $workspace);
            $controller->unignoreAction($this->post(['issueUid' => $this->finding]));
            self::assertSame(IssueStatus::Open->value, $this->findingStatus($this->finding), 'workspace ' . $workspace);
        }
    }

    #[Test]
    public function aWorkspaceOnlyEditorCannotChangeTheLiveFinding(): void
    {
        $editor = $this->enter(3, self::WORKSPACE);
        self::assertSame(self::WORKSPACE, (int)$editor->workspace);

        $this->get(PageDetailController::class)->ignoreAction($this->post(['issueUid' => $this->finding, 'reason' => 'Draft only']));
        $this->get(PageDetailController::class)->ignoreRuleOnPageAction($this->post(['ruleId' => 'rte.img_alt_missing', 'reason' => 'Draft only']));

        self::assertSame(IssueStatus::Open->value, $this->findingStatus($this->finding));
        self::assertSame(0, (int)$this->findByUid(Tables::ISSUE, $this->finding)['ignored_by']);
    }

    #[Test]
    public function theRecordsWorkspaceVersionStageDecides(): void
    {
        // Ready to publish (-10): only the workspace owner may still edit it, a member may not.
        $this->insertVersion(-10);
        $this->enter(2, self::WORKSPACE);
        $this->get(PageDetailController::class)->ignoreAction($this->post(['issueUid' => $this->finding, 'reason' => 'Locked stage']));
        self::assertSame(IssueStatus::Open->value, $this->findingStatus($this->finding));

        // In editing (0) the member edits the version and may ignore.
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['t3ver_stage' => 0], ['uid' => 30]);
        $this->get(PageDetailController::class)->ignoreAction($this->post(['issueUid' => $this->finding, 'reason' => 'Editing stage']));
        self::assertSame(IssueStatus::Ignored->value, $this->findingStatus($this->finding));
    }

    #[Test]
    public function aFindingOfAVersionRowIsNeverATarget(): void
    {
        $this->insertVersion(0);
        foreach ([0, self::WORKSPACE] as $workspace) {
            $this->enter(2, $workspace);
            $this->get(PageDetailController::class)->ignoreAction($this->post(['issueUid' => $this->versionFinding, 'reason' => 'Version']));
            self::assertSame(IssueStatus::Open->value, $this->findingStatus($this->versionFinding), 'workspace ' . $workspace);
        }
    }

    private function insertVersion(int $stage): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 30, 'pid' => 1, 'CType' => 'text', 'bodytext' => '<p>draft</p>',
            't3ver_wsid' => self::WORKSPACE, 't3ver_oid' => 10, 't3ver_state' => 0, 't3ver_stage' => $stage,
        ]);
    }

    private function enter(int $uid, int $workspace): \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
    {
        $backendUser = $this->setUpBackendUser($uid);
        $backendUser->setWorkspace($workspace);
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect((int)$backendUser->workspace));
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        self::assertSame($workspace, (int)$backendUser->workspace, 'fixture: the user may use workspace ' . $workspace);

        return $backendUser;
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y', 'POST'))
            ->withParsedBody($body + ['siteIdentifier' => self::SITE, 'pageUid' => 1])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function findingStatus(int $uid): int
    {
        return (int)$this->findByUid(Tables::ISSUE, $uid)['status'];
    }
}
