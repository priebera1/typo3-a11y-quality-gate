<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Controller\ImageRemediationAjaxController;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingContextResolverInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingVersionTokenServiceInterface;
use Priebera\A11yQualityGate\Remediation\DataHandlerImageReferenceWriter;
use Priebera\A11yQualityGate\Remediation\FileReferenceSchemaService;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationTransactionManager;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ImageRemediationEndpointAuthorizationFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $this->insertEditorGroup($pool, 10, 'Allowed image editors', true);
        $this->insertEditorGroup($pool, 11, 'Restricted image editors', false);
        $this->insertEditorGroup($pool, 12, 'Capability editor without file-reference modify', true, 'tt_content');

        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => 1,
            'username' => 'admin',
            'admin' => 1,
            'disable' => 0,
            'deleted' => 0,
        ]);
        $this->insertEditorUser($pool, 2, 'allowed-image-editor', 10);
        $this->insertEditorUser($pool, 3, 'restricted-image-editor', 11);
        $this->insertEditorUser($pool, 4, 'record-restricted-image-editor', 12);

        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1,
            'pid' => 0,
            'title' => 'Image remediation fixture',
            'doktype' => 1,
            'is_siteroot' => 1,
            'perms_userid' => 0,
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
            'header' => 'Image fixture',
            'sys_language_uid' => 0,
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
            'alternative' => 'Initial alternative',
            'tx_a11y_is_decorative' => 0,
            'sys_language_uid' => 0,
            't3ver_wsid' => 0,
            'tstamp' => 200,
            'deleted' => 0,
            'hidden' => 0,
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
            'hint' => '',
            'context_snippet' => '',
            'context_path' => 'Page:1 > tt_content:10 > assets > ref:20',
            'fingerprint' => 'endpoint-authorization-fixture',
            'status' => 0,
            'resolved_by' => 0,
            'resolved_by_name' => '',
            'resolved_by_username' => '',
            'resolved_at' => 0,
            'crdate' => 100,
            'tstamp' => 100,
            'deleted' => 0,
        ]);

        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.test/');
    }

    #[Test]
    #[DataProvider('operationProvider')]
    public function administratorCanUseAllWriteEndpoints(string $action, string $operation): void
    {
        $this->setUpBackendUser(1);
        $this->prepareOperationState($operation);

        $response = $this->controller()->{$action}($this->validRequest($operation));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame(100, $payload['findingId']);
        $this->assertSuccessfulState($operation, 1, 'admin');
    }

    #[Test]
    #[DataProvider('operationProvider')]
    public function allowedEditorCanUseAllWriteEndpoints(string $action, string $operation): void
    {
        $backendUser = $this->setUpBackendUser(2);
        self::assertSame(2, (int)($GLOBALS['BE_USER']->user['uid'] ?? 0));
        self::assertSame('1', (string)($backendUser->getTSConfig()['options.']['a11y_quality_gate.']['allowImageRemediation'] ?? ''));
        self::assertTrue($this->get(AccessControlServiceInterface::class)->canRemediateImages($backendUser));
        $this->prepareOperationState($operation);

        $response = $this->controller()->{$action}($this->validRequest($operation));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($payload['success']);
        self::assertSame(100, $payload['findingId']);
        $this->assertSuccessfulState($operation, 2, 'allowed-image-editor');
    }

    #[Test]
    #[DataProvider('operationProvider')]
    public function restrictedEditorReceivesForbiddenWithoutDatabaseMutation(string $action, string $operation): void
    {
        $backendUser = $this->setUpBackendUser(3);
        self::assertSame(3, (int)($GLOBALS['BE_USER']->user['uid'] ?? 0));
        self::assertNull($backendUser->getTSConfig()['options.']['a11y_quality_gate.']['allowImageRemediation'] ?? null);
        self::assertFalse($this->get(AccessControlServiceInterface::class)->canRemediateImages($backendUser));
        $this->assertPlaywrightEquivalentRecordPermissions();
        $this->prepareOperationState($operation);
        $before = $this->mutableState();

        $response = $this->controller()->{$action}($this->validRequest($operation));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('permission_denied', $payload['code']);
        self::assertSame($before, $this->mutableState());
    }

    #[Test]
    #[DataProvider('operationProvider')]
    public function capabilityDoesNotBypassRecordPermission(string $action, string $operation): void
    {
        $this->setUpBackendUser(4);
        $this->prepareOperationState($operation);
        $before = $this->mutableState();

        $response = $this->controller()->{$action}($this->validRequest($operation));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('permission_denied', $payload['code']);
        self::assertSame($before, $this->mutableState());
    }

    #[Test]
    #[DataProvider('operationProvider')]
    public function staleVersionRemainsConflict(string $action, string $operation): void
    {
        $this->setUpBackendUser(2);
        $this->prepareOperationState($operation);
        $token = $this->createVersionToken();
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_a11y_issue')
            ->update('tx_a11y_issue', ['tstamp' => 101], ['uid' => 100]);
        $before = $this->mutableState();

        $response = $this->controller()->{$action}($this->request($operation, $token));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(409, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('stale_finding', $payload['code']);
        self::assertSame($before, $this->mutableState());
    }

    #[Test]
    #[DataProvider('operationProvider')]
    public function invalidRequestRemainsUnprocessable(string $action, string $operation): void
    {
        $this->setUpBackendUser(2);
        $before = $this->mutableState();

        $response = $this->controller()->{$action}($this->request($operation, '', 0));
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(422, $response->getStatusCode());
        self::assertFalse($payload['success']);
        self::assertSame('invalid_input', $payload['code']);
        self::assertSame($before, $this->mutableState());
    }

    #[Test]
    public function findingUpdateFailureRollsBackFileReferenceMutation(): void
    {
        $this->setUpBackendUser(2);
        $this->prepareOperationState('markDecorative');
        $before = $this->mutableState();
        $context = $this->get(ImageFindingContextResolverInterface::class)->resolve(100);
        $resolver = $this->createMock(ImageFindingContextResolverInterface::class);
        $resolver->method('resolve')->with(100)->willReturn($context);
        $token = $this->createMock(ImageFindingVersionTokenServiceInterface::class);
        $issues = $this->createMock(IssueRemediationRepositoryInterface::class);
        $issues->method('markResolvedAfterRemediation')->willThrowException(
            new \RuntimeException('forced finding update failure'),
        );
        $service = new ImageRemediationService(
            $resolver,
            $this->get(ImageRemediationPermissionService::class),
            new ImageAltTextValidator($this->get(FileReferenceSchemaService::class)),
            $token,
            $issues,
            $this->get(BackendUserService::class),
            $this->get(DataHandlerImageReferenceWriter::class),
            $this->get(ImageRemediationTransactionManager::class),
        );

        try {
            $service->markDecorative(100, 'valid-token');
            self::fail('Expected the finding update failure.');
        } catch (\RuntimeException $exception) {
            self::assertSame('forced finding update failure', $exception->getMessage());
        }

        self::assertSame($before, $this->mutableState());
    }

    public static function operationProvider(): iterable
    {
        yield 'mark decorative' => ['markDecorativeAction', 'markDecorative'];
        yield 'mark informative' => ['markInformativeAction', 'markInformative'];
        yield 'apply alt' => ['applyAltAction', 'applyAlt'];
    }

    private function insertEditorGroup(
        ConnectionPool $pool,
        int $uid,
        string $title,
        bool $allowImageRemediation,
        string $tablesModify = 'tt_content,sys_file_reference',
    ): void {
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => $uid,
            'pid' => 0,
            'title' => $title,
            'groupMods' => 'web,web_a11y',
            'tables_select' => 'pages,tt_content,sys_file_reference',
            'tables_modify' => $tablesModify,
            'non_exclude_fields' => 'sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative',
            'db_mountpoints' => '1',
            'TSconfig' => $allowImageRemediation
                ? 'options.a11y_quality_gate.allowImageRemediation = 1'
                : '',
            'deleted' => 0,
            'hidden' => 0,
        ]);
    }

    private function insertEditorUser(ConnectionPool $pool, int $uid, string $username, int $groupUid): void
    {
        $pool->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => $uid,
            'username' => $username,
            'admin' => 0,
            'usergroup' => (string)$groupUid,
            'workspace_perms' => '1',
            'disable' => 0,
            'deleted' => 0,
        ]);
    }

    private function controller(): ImageRemediationAjaxController
    {
        return $this->get(ImageRemediationAjaxController::class);
    }

    private function validRequest(string $operation): ServerRequest
    {
        return $this->request($operation, $this->createVersionToken());
    }

    private function createVersionToken(): string
    {
        $context = $this->get(ImageFindingContextResolverInterface::class)->resolve(100);

        return $this->get(ImageFindingVersionTokenServiceInterface::class)->create($context);
    }

    private function request(string $operation, string $expectedVersion, int $findingId = 100): ServerRequest
    {
        $body = [
            'findingId' => $findingId,
            'expectedVersion' => $expectedVersion,
        ];
        if ($operation === 'applyAlt') {
            $body['altText'] = 'Reviewed alternative';
        }

        return (new ServerRequest('https://example.test/', 'POST'))->withParsedBody($body);
    }

    private function assertPlaywrightEquivalentRecordPermissions(): void
    {
        self::assertFalse($GLOBALS['BE_USER']->isAdmin());
        self::assertTrue($GLOBALS['BE_USER']->check('tables_modify', 'tt_content'));
        self::assertTrue($GLOBALS['BE_USER']->check('tables_modify', 'sys_file_reference'));
        self::assertTrue($GLOBALS['BE_USER']->check('non_exclude_fields', 'sys_file_reference:alternative'));
        self::assertTrue($GLOBALS['BE_USER']->check('non_exclude_fields', 'sys_file_reference:tx_a11y_is_decorative'));
    }

    private function prepareOperationState(string $operation): void
    {
        $values = match ($operation) {
            'markDecorative' => ['alternative' => 'Initial alternative', 'tx_a11y_is_decorative' => 0],
            'markInformative' => ['alternative' => '', 'tx_a11y_is_decorative' => 1],
            'applyAlt' => ['alternative' => '', 'tx_a11y_is_decorative' => 1],
        };
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', $values, ['uid' => 20]);
    }

    private function mutableState(): array
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $reference = $pool->getConnectionForTable('sys_file_reference')->fetchAssociative(
            'SELECT alternative, tx_a11y_is_decorative, tstamp FROM sys_file_reference WHERE uid = 20',
        );
        $issue = $pool->getConnectionForTable('tx_a11y_issue')->fetchAssociative(
            'SELECT status, resolved_by, resolved_by_name, resolved_by_username, resolved_at, tstamp FROM tx_a11y_issue WHERE uid = 100',
        );

        return [
            'alternative' => (string)$reference['alternative'],
            'decorative' => (int)$reference['tx_a11y_is_decorative'],
            'referenceTstamp' => (int)$reference['tstamp'],
            'status' => (int)$issue['status'],
            'resolvedBy' => (int)$issue['resolved_by'],
            'resolvedByName' => (string)$issue['resolved_by_name'],
            'resolvedByUsername' => (string)$issue['resolved_by_username'],
            'resolvedAt' => (int)$issue['resolved_at'],
            'issueTstamp' => (int)$issue['tstamp'],
        ];
    }

    private function assertSuccessfulState(string $operation, int $userUid, string $username): void
    {
        $state = $this->mutableState();
        $expected = match ($operation) {
            'markDecorative' => ['', 1, IssueStatus::Resolved->value],
            'markInformative' => ['', 0, IssueStatus::Open->value],
            'applyAlt' => ['Reviewed alternative', 0, IssueStatus::Resolved->value],
        };

        self::assertSame($expected[0], $state['alternative']);
        self::assertSame($expected[1], $state['decorative']);
        self::assertSame($expected[2], $state['status']);

        if ($expected[2] === IssueStatus::Resolved->value) {
            self::assertSame($userUid, $state['resolvedBy']);
            self::assertSame($username, $state['resolvedByUsername']);
            self::assertGreaterThan(0, $state['resolvedAt']);
        } else {
            self::assertSame(0, $state['resolvedBy']);
            self::assertSame(0, $state['resolvedAt']);
        }
    }
}
