<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ImageRemediationPermissionFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10,
            'pid' => 0,
            'title' => 'AQG editors',
            'groupMods' => 'web,web_a11y',
            'tables_select' => 'pages,tt_content,sys_file_reference',
            'tables_modify' => 'tt_content,sys_file_reference',
            'non_exclude_fields' => 'sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative',
            'db_mountpoints' => '1',
            'TSconfig' => "options.a11y_quality_gate.allowImageRemediation = 1",
            'deleted' => 0,
            'hidden' => 0,
        ]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 11,
            'pid' => 0,
            'title' => 'Restricted editors',
            'groupMods' => 'web,web_a11y',
            'tables_select' => 'pages,tt_content,sys_file_reference',
            'tables_modify' => 'tt_content,sys_file_reference',
            'non_exclude_fields' => 'sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative',
            'db_mountpoints' => '1',
            'deleted' => 0,
            'hidden' => 0,
        ]);
        $this->insertPermissionGroup(12, 'No file-reference modify', 'tt_content', 'sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative', '1');
        $this->insertPermissionGroup(13, 'No content modify', 'sys_file_reference', 'sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative', '1');
        $this->insertPermissionGroup(14, 'No alternative field', 'tt_content,sys_file_reference', 'sys_file_reference:tx_a11y_is_decorative', '1');
        $this->insertPermissionGroup(15, 'No decorative field', 'tt_content,sys_file_reference', 'sys_file_reference:alternative', '1');
        $this->insertPermissionGroup(16, 'Outside web mount', 'tt_content,sys_file_reference', 'sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative', '2');
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'username' => 'admin',
            'admin' => 1,
            'disable' => 0,
            'deleted' => 0,
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 2,
            'username' => 'allowed-editor',
            'admin' => 0,
            'usergroup' => '10',
            'workspace_perms' => '1',
            'disable' => 0,
            'deleted' => 0,
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 3,
            'username' => 'restricted-editor',
            'admin' => 0,
            'usergroup' => '11',
            'workspace_perms' => '1',
            'disable' => 0,
            'deleted' => 0,
        ]);
        $this->insertPermissionUser(4, 'no-file-reference-modify', 12);
        $this->insertPermissionUser(5, 'no-content-modify', 13);
        $this->insertPermissionUser(6, 'no-alternative-field', 14);
        $this->insertPermissionUser(7, 'no-decorative-field', 15);
        $this->insertPermissionUser(8, 'outside-web-mount', 16);

        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Fixture page',
            'doktype' => 1,
            'perms_userid' => 1,
            'perms_groupid' => 0,
            'perms_user' => 0,
            'perms_group' => 0,
            'perms_everybody' => 31,
            'deleted' => 0,
            'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10,
            'pid' => 1,
            'CType' => '',
            'header' => 'Fixture content',
            'deleted' => 0,
            'hidden' => 0,
        ]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 20,
            'pid' => 1,
            'uid_local' => 1,
            'uid_foreign' => 10,
            'tablenames' => 'tt_content',
            'fieldname' => 'assets',
            'alternative' => '',
            'tx_a11y_is_decorative' => 0,
            'sys_language_uid' => 0,
            'deleted' => 0,
            'hidden' => 0,
        ]);
    }

    #[Test]
    public function administratorWithMatchingLanguageAndWorkspaceIsAllowed(): void
    {
        $this->setUpBackendUser(1);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify($this->context());
        self::addToAssertionCount(1);
    }

    #[Test]
    public function regularEditorWithCapabilityAndRecordPermissionCanSaveThroughDataHandler(): void
    {
        $backendUser = $this->setUpBackendUser(2);
        self::assertTrue($this->get(AccessControlServiceInterface::class)->canRemediateImages($backendUser));
        self::assertSame(2, (int)($GLOBALS['BE_USER']->user['uid'] ?? 0));
        self::assertSame('1', (string)($backendUser->getTSConfig()['options.']['a11y_quality_gate.']['allowImageRemediation'] ?? ''));
        $this->get(ImageRemediationPermissionService::class)->assertCanModify($this->context());

        self::assertTrue(
            $GLOBALS['BE_USER']->check('tables_modify', 'sys_file_reference'),
            'The editor must be allowed to modify sys_file_reference records.',
        );
        self::assertTrue(
            $GLOBALS['BE_USER']->check('non_exclude_fields', 'sys_file_reference:alternative'),
            'The editor must have field-level access to sys_file_reference.alternative.',
        );
        self::assertTrue(
            $GLOBALS['BE_USER']->check('non_exclude_fields', 'sys_file_reference:tx_a11y_is_decorative'),
            'The editor must have field-level access to the AQG decorative flag.',
        );

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([
            'sys_file_reference' => [
                20 => [
                    'alternative' => 'Allowed editor description',
                    'tx_a11y_is_decorative' => 1,
                ],
            ],
        ], []);
        $dataHandler->process_datamap();

        self::assertSame(
            [],
            $dataHandler->errorLog,
            'DataHandler rejected the permitted editor payload: ' . json_encode(
                $dataHandler->errorLog,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
        );
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->fetchAssociative(
                'SELECT alternative, tx_a11y_is_decorative FROM sys_file_reference WHERE uid = 20',
            );
        self::assertSame('Allowed editor description', $row['alternative']);
        self::assertSame(0, (int)$row['tx_a11y_is_decorative']);
    }

    #[Test]
    public function editorWithoutCapabilityIsRejectedDespiteIdenticalRecordPermission(): void
    {
        $backendUser = $this->setUpBackendUser(3);
        self::assertSame(3, (int)($GLOBALS['BE_USER']->user['uid'] ?? 0));
        self::assertNull($backendUser->getTSConfig()['options.']['a11y_quality_gate.']['allowImageRemediation'] ?? null);
        self::assertFalse($this->get(AccessControlServiceInterface::class)->canRemediateImages($backendUser));
        self::assertTrue($GLOBALS['BE_USER']->check('tables_modify', 'tt_content'));
        self::assertTrue($GLOBALS['BE_USER']->check('tables_modify', 'sys_file_reference'));
        self::assertTrue($GLOBALS['BE_USER']->check('non_exclude_fields', 'sys_file_reference:alternative'));
        self::assertTrue($GLOBALS['BE_USER']->check('non_exclude_fields', 'sys_file_reference:tx_a11y_is_decorative'));

        $this->expectException(ImageRemediationPermissionException::class);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify($this->context());
    }

    #[Test]
    public function referenceFromAnotherLanguageIsRejected(): void
    {
        $this->setUpBackendUser(1);
        $this->expectException(ImageRemediationPermissionException::class);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify(
            $this->context(referenceLanguage: 1),
        );
    }

    #[Test]
    public function referenceFromAnotherWorkspaceIsRejected(): void
    {
        $this->setUpBackendUser(1);
        $this->expectException(ImageRemediationPermissionException::class);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify(
            $this->context(referenceWorkspace: 1),
        );
    }

    #[Test]
    public function editorWithoutPageContentEditPermissionIsRejected(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('pages')
            ->update('pages', [
                'perms_user' => 0,
                'perms_group' => 0,
                'perms_everybody' => 1,
            ], ['uid' => 1]);
        $this->setUpBackendUser(2);

        $this->expectException(ImageRemediationPermissionException::class);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify(
            $this->context(),
            ['alternative'],
        );
    }

    #[Test]
    public function editorWithoutFileReferenceModifyPermissionIsRejected(): void
    {
        $this->setUpBackendUser(4);
        $this->expectException(ImageRemediationPermissionException::class);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify(
            $this->context(),
            ['alternative'],
        );
    }

    #[Test]
    public function editorWithoutContentModifyPermissionIsRejected(): void
    {
        $this->setUpBackendUser(5);
        $this->expectException(ImageRemediationPermissionException::class);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify(
            $this->context(),
            ['alternative'],
        );
    }

    #[Test]
    public function editorWithoutAlternativeFieldPermissionIsRejected(): void
    {
        $this->setUpBackendUser(6);
        $this->expectException(ImageRemediationPermissionException::class);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify(
            $this->context(),
            ['alternative'],
        );
    }

    #[Test]
    public function editorWithoutDecorativeFieldPermissionIsRejected(): void
    {
        $this->setUpBackendUser(7);
        $this->expectException(ImageRemediationPermissionException::class);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify(
            $this->context(),
            ['tx_a11y_is_decorative'],
        );
    }

    #[Test]
    public function editorOutsideParentPageWebMountIsRejected(): void
    {
        $this->setUpBackendUser(8);
        $this->expectException(ImageRemediationPermissionException::class);
        $this->get(ImageRemediationPermissionService::class)->assertCanModify(
            $this->context(),
            ['alternative'],
        );
    }

    private function insertPermissionGroup(
        int $uid,
        string $title,
        string $tablesModify,
        string $nonExcludeFields,
        string $dbMountpoints,
    ): void {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_groups')
            ->insert('be_groups', [
                'uid' => $uid,
                'pid' => 0,
                'title' => $title,
                'groupMods' => 'web,web_a11y',
                'tables_select' => 'pages,tt_content,sys_file_reference',
                'tables_modify' => $tablesModify,
                'non_exclude_fields' => $nonExcludeFields,
                'db_mountpoints' => $dbMountpoints,
                'TSconfig' => "options.a11y_quality_gate.allowImageRemediation = 1",
                'deleted' => 0,
                'hidden' => 0,
            ]);
    }

    private function insertPermissionUser(int $uid, string $username, int $groupUid): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->insert('be_users', [
                'uid' => $uid,
                'username' => $username,
                'admin' => 0,
                'usergroup' => (string)$groupUid,
                'workspace_perms' => '1',
                'disable' => 0,
                'deleted' => 0,
            ]);
    }

    private function context(int $referenceLanguage = 0, int $referenceWorkspace = 0): ImageFindingContext
    {
        return new ImageFindingContext(
            issue: ['uid' => 100],
            fileReference: [
                'uid' => 20,
                'uid_foreign' => 10,
                'tablenames' => 'tt_content',
                'fieldname' => 'assets',
                'sys_language_uid' => $referenceLanguage,
                't3ver_wsid' => $referenceWorkspace,
            ],
            siteIdentifier: 'main',
            pageUid: 1,
            languageUid: 0,
            sourceTable: 'tt_content',
            sourceUid: 10,
            sourceField: 'assets',
            fileReferenceUid: 20,
            fileUid: 1,
            fingerprint: 'functional-permission-fixture',
            issueTimestamp: 100,
            fileReferenceTimestamp: 200,
        );
    }
}
