<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Mcp\McpSiteSettingsRepository;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Mcp\McpUserContext;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Owner MCP pilot QA 2026-11-15: `aqg_get_finding_context` said `canEdit: false` for tt_content 2075 (an Image element,
 * finding structured.file_reference_alt) under the administrator "DW Admin".
 *
 * Cause: the connector builds the token's TYPO3 user without a session. TYPO3's FormEngine reads the session identifier
 * while compiling file references (TcaGroup → Clipboard module data) and threw; AQG's field visibility check caught the
 * error and failed closed. Every record with file references or group fields was "not editable" for agents — also for
 * administrators — while rich-text records were not affected. The user now carries an in-memory anonymous session;
 * permissions are evaluated exactly as before (no rule changed): a user without edit rights, or a non-administrator on
 * an edit-locked page, still gets false. Nothing is persisted.
 */
final class McpConnectorFileFieldEditAccessFunctionalTest extends AbstractFunctionalTestCase
{
    private const SCOPES = ['findings:read', 'content:read'];
    private const ADMIN = 5;
    private const EDITOR = 6;
    private const READ_ONLY = 7;

    private int $issue = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'is_siteroot' => 1, 'slug' => '/',
            'perms_userid' => 0, 'perms_groupid' => 0, 'perms_user' => 0, 'perms_group' => 0, 'perms_everybody' => 31,
        ]);
        $connect = "options.a11y_quality_gate.mcp.connect = 1\n";
        foreach ([[10, 'pages,tt_content,sys_file_reference'], [11, '']] as [$uid, $modify]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'pid' => 0, 'title' => 'Group ' . $uid, 'groupMods' => 'web,web_a11y,web_layout',
                'tables_select' => 'pages,tt_content,sys_file_reference,sys_file', 'tables_modify' => $modify, 'db_mountpoints' => '1',
                'TSconfig' => $connect, 'non_exclude_fields' => 'tt_content:image,sys_file_reference:alternative',
                'explicit_allowdeny' => 'tt_content:CType:image', 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        foreach ([[self::ADMIN, 'dw-admin', 1, ''], [self::EDITOR, 'editor', 0, '10'], [self::READ_ONLY, 'reader', 0, '11']] as [$uid, $name, $admin, $groups]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => $name, 'admin' => $admin, 'usergroup' => $groups, 'options' => 3, 'workspace_perms' => 1,
                'TSconfig' => $connect, 'uc' => '', 'disable' => 0, 'deleted' => 0,
            ]);
        }
        $pool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', [
            'uid' => 1, 'name' => 'fileadmin', 'driver' => 'Local', 'is_online' => 1, 'is_browsable' => 1, 'is_writable' => 1, 'is_public' => 1, 'is_default' => 1,
            'configuration' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF">'
                . '<field index="basePath"><value index="vDEF">fileadmin/</value></field><field index="pathType"><value index="vDEF">relative</value></field>'
                . '<field index="caseSensitive"><value index="vDEF">1</value></field></language></sheet></data></T3FlexForms>',
        ]);
        $pool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => 65, 'storage' => 1, 'identifier' => '/user_upload/hotel2.jpeg', 'name' => 'hotel2.jpeg', 'type' => 2, 'mime_type' => 'image/jpeg', 'extension' => 'jpeg', 'missing' => 0,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 2075, 'pid' => 1, 'CType' => 'image', 'image' => 1, 'colPos' => 0, 'sys_language_uid' => 0]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 2311, 'pid' => 1, 'uid_local' => 65, 'uid_foreign' => 2075, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => '', 'sorting_foreign' => 1,
        ]);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://main.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->get(McpSiteSettingsRepository::class)->save('main', true, self::SCOPES, 1);
        $this->issue = $this->insertIssue([
            'site_identifier' => 'main', 'page_uid' => 1, 'source_table' => 'tt_content', 'source_uid' => 2075, 'source_field' => 'image',
            'source_type' => 'structured', 'rule_id' => 'structured.file_reference_alt', 'severity' => 1, 'context_path' => 'tt_content:2075 > image > ref:2311',
        ]);
    }

    #[Test]
    public function anImageElementIsEditableForUsersWhoMayEditIt(): void
    {
        foreach ([self::ADMIN, self::EDITOR] as $user) {
            $context = $this->context($user);
            self::assertTrue($context['source']['canEdit'], 'user ' . $user);
            self::assertSame('record_edit', $context['navigation']['target']);
            self::assertTrue($this->asConnectorUser($user, fn (): bool => $this->get(BackendRecordAccessService::class)->canEditRecordFields('sys_file_reference', 2311, ['alternative'])),
                'user ' . $user . ': the image reference\'s alternative text (AI Fixes target) is editable too');
        }
    }

    #[Test]
    public function permissionsStillDecide(): void
    {
        self::assertFalse($this->context(self::READ_ONLY)['source']['canEdit'], 'no tables_modify: read only');
        self::assertFalse($this->asConnectorUser(self::READ_ONLY, fn (): bool => $this->get(BackendRecordAccessService::class)->canEditRecordFields('sys_file_reference', 2311, ['alternative'])));

        // TYPO3's edit lock binds non-administrators only (core semantics, unchanged).
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['editlock' => 1], ['uid' => 2075]);
        self::assertFalse($this->context(self::EDITOR)['source']['canEdit']);
        self::assertTrue($this->context(self::ADMIN)['source']['canEdit']);
    }

    #[Test]
    public function theConnectorSessionIsNeverPersisted(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $ucBefore = $pool->getConnectionForTable('be_users')->select(['uid', 'uc'], 'be_users', [], [], ['uid' => 'ASC'])->fetchAllAssociative();
        $this->context(self::ADMIN);
        $this->context(self::EDITOR);
        self::assertSame(0, $pool->getConnectionForTable('be_sessions')->count('*', 'be_sessions', []), 'no backend session row');
        self::assertSame($ucBefore, $pool->getConnectionForTable('be_users')->select(['uid', 'uc'], 'be_users', [], [], ['uid' => 'ASC'])->fetchAllAssociative(), 'no user configuration written');
    }

    /** @return array<string, mixed> */
    private function context(int $user): array
    {
        return $this->get(McpToolExecutor::class)->execute('aqg_get_finding_context', ['siteIdentifier' => 'main', 'findingId' => $this->issue], [
            'tokenId' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'tokenSuffix' => 'test', 'clientName' => 'Test agent', 'beUserUid' => $user,
            'scopes' => self::SCOPES, 'siteIdentifiers' => ['main'], 'expiresAt' => '2026-12-01T00:00:00Z',
        ]);
    }

    private function asConnectorUser(int $user, callable $check): bool
    {
        return (bool)$this->get(McpUserContext::class)->runAs($user, static fn (): bool => $check());
    }
}
