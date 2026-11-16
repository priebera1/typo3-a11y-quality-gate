<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Mcp;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Controller\McpChangeApprovalController;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Mcp\Intelligence\McpIntelligenceTools;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\Remediation\McpApprovedChangeTools;
use Priebera\A11yQualityGate\Mcp\Remediation\McpChangeApprovalRepository;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationException;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationItemData;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetResolver;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * remediations:apply-approved for an image use's alternative text (post-3.0.0): the approved text is written through
 * AQG's allow-listed image write (DataHandler), read back and verified by the structured image rule; a text changed in
 * TYPO3 after the approval is never overwritten.
 */
final class McpApprovedImageChangeFunctionalTest extends AbstractFunctionalTestCase
{
    private const TOKEN = '6f1c2a5e-0d3b-4c1a-9e7f-2b8d4c6a1e01';
    private int $findingUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $sites = $this->instancePath . '/typo3conf/sites/test';
        @mkdir($sites, 0777, true);
        file_put_contents($sites . '/config.yaml', "rootPageId: 1\nbase: 'https://example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n");
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Water tariffs', 'doktype' => 1, 'is_siteroot' => 1, 'perms_userid' => 1, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', ['uid' => 1, 'pid' => 0, 'name' => 'fileadmin', 'driver' => 'Local', 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1, 'is_online' => 1,
            'configuration' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="basePath"><value index="vDEF">fileadmin/</value></field><field index="pathType"><value index="vDEF">relative</value></field><field index="caseSensitive"><value index="vDEF">1</value></field></language></sheet></data></T3FlexForms>']);
        $pool->getConnectionForTable('sys_file')->insert('sys_file', ['uid' => 5, 'pid' => 0, 'storage' => 1, 'identifier' => '/chart.png', 'identifier_hash' => sha1('/chart.png'), 'folder_hash' => sha1('/'), 'name' => 'chart.png', 'extension' => 'png', 'mime_type' => 'image/png', 'type' => 2, 'size' => 70, 'missing' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 1, 'CType' => 'image', 'header' => 'Tariffs', 'deleted' => 0, 'hidden' => 0, 'image' => 1]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', ['uid' => 20, 'pid' => 1, 'uid_local' => 5, 'uid_foreign' => 10, 'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => '', 'tstamp' => 100, 'deleted' => 0, 'hidden' => 0, 'sys_language_uid' => 0]);
        $this->findingUid = $this->insertIssue([
            'site_identifier' => 'test', 'page_uid' => 1, 'source_table' => 'tt_content', 'source_uid' => 10, 'source_field' => 'image',
            'source_type' => 'structured', 'rule_id' => 'structured.file_reference_alt', 'context_path' => 'Page:1 > tt_content:10 > image > ref:20',
            'context_snippet' => 'chart.png',
        ]);
        $pool->getConnectionForTable('be_users')->update('be_users', ['TSconfig' => 'options.a11y_quality_gate.mcp.connect = 1'], ['uid' => 1]);
        $this->setUpBackendUser(1);
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['a11y_quality_gate']['mcpApprovedChanges'] = '1';
    }

    #[Test]
    public function anApprovedImageTextIsWrittenOnceReadBackAndVerified(): void
    {
        $uid = $this->pendingItem('Bar chart of water prices 2024 to 2026');
        $request = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => $uid], $this->principal(), true);
        self::assertSame('awaiting_approval', $request['outcome'], (string)($request['reason'] ?? ''));
        self::assertSame(['', 'Bar chart of water prices 2024 to 2026'], [$request['preview']['untrusted']['before'], $request['preview']['untrusted']['after']]);
        self::assertSame('', $this->alternative(), 'requesting writes nothing');
        $this->approve($request['approvalId']);

        $applied = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());

        self::assertSame('verified', $applied['outcome'], (string)$applied['reason']);
        self::assertTrue($applied['accessibilityVerified']);
        self::assertSame('Bar chart of water prices 2024 to 2026', $this->alternative());
        self::assertSame('replayed', $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal())['call']);
    }

    #[Test]
    public function anImageTextChangedAfterTheApprovalIsNeverOverwritten(): void
    {
        $uid = $this->pendingItem('Bar chart of water prices');
        $request = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => $uid], $this->principal(), true);
        $this->approve($request['approvalId']);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->update('sys_file_reference', ['alternative' => 'Typed by an editor'], ['uid' => 20]);

        $applied = $this->tools()->apply($GLOBALS['BE_USER'], $this->site(), ['approvalId' => $request['approvalId']], $this->principal());

        self::assertSame('stale_content', $applied['outcome']);
        self::assertSame('Typed by an editor', $this->alternative());
    }

    #[Test]
    public function theApprovalPageShowsTheFilesTextWhenTheImageUseHasNoneOfItsOwn(): void
    {
        // NULL on the reference: visitors get the file's alternative text, so BEFORE is that text, not "empty".
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('sys_file_reference')->update('sys_file_reference', ['alternative' => null], ['uid' => 20]);
        $pool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', ['uid' => 1, 'pid' => 0, 'file' => 5, 'sys_language_uid' => 0, 'alternative' => 'Chart']);
        $uid = $this->pendingItem('Bar chart of water prices');
        $request = $this->tools()->request($GLOBALS['BE_USER'], $this->site(), ['itemId' => $uid], $this->principal(), true);
        self::assertSame('awaiting_approval', $request['outcome'], (string)($request['reason'] ?? ''));

        $html = (string)$this->show($request['approvalId'])->getBody();

        self::assertStringContainsString('data-aqg-change="before-inherited"', $html);
        self::assertStringContainsString('Visitors now get the file&#039;s alternative text:', $html);
        self::assertStringContainsString('>Chart</span>', $html);
        self::assertStringContainsString('New alternative text (alt):</span> <strong data-aqg-change="value">Bar chart of water prices</strong>', $html);
        self::assertStringContainsString('data-aqg-change="after">Bar chart of water prices</pre>', $html);
    }

    private function show(string $ref): \Psr\Http\Message\ResponseInterface
    {
        $router = $this->get(Router::class);
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y/mcp/change-approval?approval=' . $ref, 'GET'))
            ->withQueryParams(['approval' => $ref])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']))
            ->withAttribute('route', $router->match($router->getRoute('web_a11y.mcpChangeApproval')->getPath()));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $container = $this->get(McpChangeApprovalController::class);
        $arguments = [];
        foreach ((new \ReflectionClass(McpChangeApprovalController::class))->getConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();
            $arguments[$name] = $name === 'workflow' ? $this->workflow() : (new \ReflectionProperty($container, $name))->getValue($container);
        }

        return (new McpChangeApprovalController(...$arguments))->showAction($request);
    }

    private function approve(string $ref): void
    {
        $approvals = $this->get(McpChangeApprovalRepository::class);
        $row = $approvals->findByRef($ref);
        self::assertTrue($approvals->decide((int)$row['uid'], 1, (string)$row['binding_hash'], true, time()));
    }

    private function alternative(): string
    {
        return (string)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->fetchOne('SELECT alternative FROM sys_file_reference WHERE uid = 20');
    }

    private function tools(): McpApprovedChangeTools
    {
        return new McpApprovedChangeTools($this->workflow(), $this->get(McpChangeApprovalRepository::class), $this->get(McpIntelligenceTools::class), $this->get(McpAuditRepository::class), $this->get(ConnectionPool::class));
    }

    private function site(): \TYPO3\CMS\Core\Site\Entity\Site
    {
        return $this->get(SiteFinder::class)->getSiteByIdentifier('test');
    }

    /** @return array<string, mixed> */
    private function principal(): array
    {
        return ['tokenId' => self::TOKEN, 'tokenSuffix' => '1e01', 'clientName' => 'Test agent', 'beUserUid' => 1,
            'scopes' => ['content:read', 'remediations:apply-approved'], 'siteIdentifiers' => ['test'], 'expiresAt' => '2026-12-31T00:00:00Z'];
    }

    private function workflow(): RemediationWorkflowService
    {
        // The real access rules (page, record, field, image remediation, workspace); only the licence answer is fixed.
        $licence = $this->createMock(AiFeatureAccessServiceInterface::class);
        $licence->method('isAvailable')->willReturn(true);
        $access = new RemediationAccessService(
            $this->get(BackendUserService::class),
            $this->get(ScopeAccessService::class),
            $this->get(BackendRecordAccessService::class),
            $this->get(EditorAiAccessService::class),
            $licence,
            $this->get(AccessControlService::class),
            $this->get(\Priebera\A11yQualityGate\Service\LocalFindingSourceAccessService::class),
        );

        return new RemediationWorkflowService(
            $this->get(RemediationItemRepository::class),
            $this->get(IssueRepository::class),
            $this->get(RemediationTargetResolver::class),
            $access,
            $this->get(ReferenceAltAiService::class),
            $this->get(EditorAiRemediationService::class),
            $this->get(EditorAiValueValidator::class),
            $this->get(ImageAltTextValidator::class),
            $this->get(ImageRemediationServiceInterface::class),
            $this->get(ImageFindingContextResolver::class),
            $this->get(ImageFindingVersionTokenService::class),
            $this->get(RuleMetadataPresentationServiceInterface::class),
            GeneralUtility::makeInstance(ConnectionPool::class),
        );
    }

    /** A suggestion as generate() leaves it, already displayed to user 1. */
    private function pendingItem(string $value): int
    {
        $issue = $this->get(IssueRepository::class)->findByUid($this->findingUid);
        $target = $this->get(RemediationTargetResolver::class)->resolve($issue);
        self::assertTrue($target->isAvailable(), 'The finding resolves to its exact image use: ' . $target->unavailableReason);
        $repository = $this->get(RemediationItemRepository::class);
        $uid = $repository->insert([
            'site_identifier' => 'test', 'page_uid' => 1, 'language_uid' => 0, 'workspace_id' => 0, 'finding_uid' => $this->findingUid,
            'finding_fingerprint' => (string)$issue['fingerprint'], 'rule_id' => 'structured.file_reference_alt', 'severity' => 1, 'target_kind' => $target->kind,
            'target_table' => $target->table, 'target_uid' => $target->uid, 'target_field' => $target->field,
            'target_descriptor' => RemediationItemData::encodeDescriptor($target->descriptor), 'base_hash' => $target->baseHash,
            'original_value' => $target->currentValue, 'proposed_value' => $value, 'edited_value' => '', 'owner' => 'editor',
            'status' => RemediationStatus::PENDING_REVIEW, 'origin' => 'editor_request', 'created_by' => 1, 'proposal_version' => 1,
        ]);
        $repository->addEvent(['item_uid' => $uid, 'be_user' => 1, 'action' => 'viewed', 'from_status' => 'pending_review', 'to_status' => 'pending_review', 'proposal_version' => 1]);

        return $uid;
    }
}
