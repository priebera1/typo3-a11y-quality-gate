<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ImageRemediationAjaxController;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingContextResolverInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingVersionTokenServiceInterface;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * M-1 (3.0.0 final review): the direct image endpoints ("Apply reviewed text", "Mark decorative", "Mark informative")
 * with EXT:workspaces and the real DataHandler. In a workspace TYPO3 would store the change as a version row while the
 * shared live finding was marked resolved although the live page still lacks the alternative text. A write is refused
 * there without touching anything; in LIVE the same editor fixes the image as before.
 */
final class ImageRemediationWorkspaceFunctionalTest extends AbstractFunctionalTestCase
{
    private const WORKSPACE = 1;
    private const EDITOR = 2;

    protected array $coreExtensionsToLoad = ['backend', 'scheduler', 'rte_ckeditor', 'workspaces'];

    public static function setUpBeforeClass(): void
    {
        // Skipped before an instance exists: a skip inside setUp() runs the framework's tearDown() on an instance that
        // was never set up and turns every test into an error on installations without EXT:workspaces.
        if (!class_exists(\TYPO3\CMS\Workspaces\Hook\DataHandlerHook::class)) {
            self::markTestSkipped('EXT:workspaces is not installed in this TYPO3 instance.');
        }
        parent::setUpBeforeClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10,
            'pid' => 0,
            'title' => 'Image editors',
            'explicit_allowdeny' => 'tt_content:CType:textmedia,tt_content:CType:image',
            'groupMods' => 'web,web_a11y',
            'tables_select' => 'pages,tt_content,sys_file_reference',
            'tables_modify' => 'tt_content,sys_file_reference',
            'non_exclude_fields' => 'sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative',
            'db_mountpoints' => '1',
            'TSconfig' => 'options.a11y_quality_gate.allowImageRemediation = 1',
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR,
            'username' => 'image-editor',
            'admin' => 0,
            'usergroup' => '10',
            // Live and custom workspaces.
            'workspace_perms' => 1,
        ]);
        $pool->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => self::WORKSPACE,
            'title' => 'Draft',
            'members' => 'be_users_' . self::EDITOR,
        ]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Workspace image fixture',
            'doktype' => 1,
            'is_siteroot' => 1,
            'perms_everybody' => 31,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10,
            'pid' => 1,
            'CType' => 'textmedia',
            'header' => 'Image fixture',
        ]);
        $pool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => 1, 'pid' => 0, 'storage' => 1, 'type' => 2, 'name' => 'fixture.png', 'identifier' => '/fixture-1.png', 'mime_type' => 'image/png',
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
            't3ver_wsid' => 0,
            'tstamp' => 200,
        ]);
        $pool->getConnectionForTable('tx_a11y_issue')->insert('tx_a11y_issue', [
            'uid' => 100,
            'pid' => 1,
            'site_identifier' => 'main',
            'page_uid' => 1,
            'source_lang_uid' => 0,
            'source_table' => 'tt_content',
            'source_uid' => 10,
            'source_field' => 'assets',
            'source_type' => 'structured',
            'rule_id' => 'structured.file_reference_alt',
            'severity' => 2,
            'message' => 'Missing alternative text.',
            'context_path' => 'Page:1 > tt_content:10 > assets > ref:20',
            'fingerprint' => 'workspace-image-fixture',
            'status' => IssueStatus::Open->value,
            'crdate' => 100,
            'tstamp' => 100,
        ]);

        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.test/');
    }

    #[Test]
    #[DataProvider('writeProvider')]
    public function aWorkspaceWriteIsRefusedAndLeavesTheLiveFindingOpen(string $action, array $liveValues): void
    {
        $this->setLiveValues($liveValues);
        $editor = $this->enterWorkspace(self::WORKSPACE);
        self::assertSame(self::WORKSPACE, (int)$editor->workspace, 'the editor may use the Draft workspace');
        $before = $this->state();

        $response = $this->get(ImageRemediationAjaxController::class)->{$action}($this->request($action));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode(), (string)$response->getBody());
        self::assertFalse($payload['success']);
        self::assertSame('permission_denied', $payload['code']);
        // Nothing changed: no version row, the live reference and the shared live finding as before.
        self::assertSame($before, $this->state());
        self::assertSame(0, $this->workspaceVersionCount());
        self::assertSame(IssueStatus::Open->value, $this->state()['status']);
    }

    #[Test]
    public function theSameEditorFixesTheImageInLive(): void
    {
        $this->enterWorkspace(0);

        $response = $this->get(ImageRemediationAjaxController::class)->applyAltAction($this->request('applyAltAction'));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertTrue($payload['success']);
        $state = $this->state();
        self::assertSame('Reviewed alternative', $state['alternative']);
        self::assertSame(IssueStatus::Resolved->value, $state['status']);
        self::assertSame(self::EDITOR, $state['resolvedBy']);
        self::assertSame(0, $this->workspaceVersionCount());
    }

    #[Test]
    public function aSuggestionStillReadsTheReferenceInAWorkspace(): void
    {
        $this->enterWorkspace(self::WORKSPACE);
        $context = $this->get(ImageFindingContextResolverInterface::class)->resolve(100);

        // The AI alt suggestion only reads (no fields to write); it is not a write and stays allowed.
        $this->get(\Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionService::class)
            ->assertCanModify($context, [], 'ai-alt-suggestion');
        self::assertSame(IssueStatus::Open->value, $this->state()['status']);
    }

    public static function writeProvider(): iterable
    {
        yield 'apply reviewed text' => ['applyAltAction', ['alternative' => '', 'tx_a11y_is_decorative' => 0]];
        yield 'mark decorative' => ['markDecorativeAction', ['alternative' => '', 'tx_a11y_is_decorative' => 0]];
        yield 'mark informative' => ['markInformativeAction', ['alternative' => '', 'tx_a11y_is_decorative' => 1]];
    }

    private function enterWorkspace(int $workspace): BackendUserAuthentication
    {
        $editor = $this->setUpBackendUser(self::EDITOR);
        $editor->setWorkspace($workspace);
        GeneralUtility::makeInstance(Context::class)->setAspect('workspace', new WorkspaceAspect((int)$editor->workspace));

        return $editor;
    }

    /** @param array<string, mixed> $values */
    private function setLiveValues(array $values): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', $values, ['uid' => 20]);
    }

    private function request(string $action): ServerRequest
    {
        $context = $this->get(ImageFindingContextResolverInterface::class)->resolve(100);
        $body = [
            'findingId' => 100,
            'expectedVersion' => $this->get(ImageFindingVersionTokenServiceInterface::class)->create($context),
        ];
        if ($action === 'applyAltAction') {
            $body['altText'] = 'Reviewed alternative';
        }

        return (new ServerRequest('https://example.test/', 'POST'))->withParsedBody($body);
    }

    private function workspaceVersionCount(): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->count('uid', 'sys_file_reference', ['t3ver_wsid' => self::WORKSPACE]);
    }

    /** @return array<string, int|string> */
    private function state(): array
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $reference = $pool->getConnectionForTable('sys_file_reference')->select(
            ['alternative', 'tx_a11y_is_decorative', 'tstamp'],
            'sys_file_reference',
            ['uid' => 20],
        )->fetchAssociative();
        $issue = $pool->getConnectionForTable('tx_a11y_issue')->select(
            ['status', 'resolved_by', 'resolved_at', 'tstamp'],
            'tx_a11y_issue',
            ['uid' => 100],
        )->fetchAssociative();

        return [
            'alternative' => (string)$reference['alternative'],
            'decorative' => (int)$reference['tx_a11y_is_decorative'],
            'referenceTstamp' => (int)$reference['tstamp'],
            'status' => (int)$issue['status'],
            'resolvedBy' => (int)$issue['resolved_by'],
            'resolvedAt' => (int)$issue['resolved_at'],
            'issueTstamp' => (int)$issue['tstamp'],
        ];
    }
}
