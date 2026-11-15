<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Direct reviewed apply in a TYPO3 workspace: the change goes to the workspace version through DataHandler; the live
 * record and the website stay unchanged until the workspace is published. The item says so (Not verified: published
 * with the workspace) instead of claiming a live fix.
 */
final class RemediationDirectApplyWorkspaceFunctionalTest extends AbstractDirectApplyFunctionalTestCase
{
    private const WORKSPACE = 1;

    protected array $coreExtensionsToLoad = ['backend', 'scheduler', 'rte_ckeditor', 'workspaces'];

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
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_workspace')
            ->insert('sys_workspace', ['uid' => self::WORKSPACE, 'title' => 'Draft', 'adminusers' => 'be_users_' . self::ADMIN]);
    }

    #[Test]
    public function aChangeAppliedInAWorkspaceIsStoredInTheVersionAndNotPublished(): void
    {
        $this->login(self::ADMIN);
        $GLOBALS['BE_USER']->setWorkspace(self::WORKSPACE);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);
        self::assertSame(self::WORKSPACE, (int)$item['workspace_id']);

        $applied = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(self::CONTACT, $this->bodytext(10), 'The live record is unchanged.');
        $version = BackendUtility::getRecordWSOL('tt_content', 10);
        self::assertSame(str_replace('class="more">click here</a>', 'class="more">Directions and parking</a>', self::CONTACT), (string)($version['bodytext'] ?? ''), 'The workspace version holds the change.');
        self::assertSame(RemediationStatus::NOT_VERIFIED, $applied['status']);
        self::assertSame('workspace_publish', $applied['status_reason']);
    }
}
