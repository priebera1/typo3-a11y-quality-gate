<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetResolver;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * An AI Fixes item holds its finding for everyone until it is decided, so creating one needs the right to change the
 * record field it fixes. A user who may only read the page gets "no_edit_access" and leaves nothing behind that could
 * block an editor; the editor then prepares the item as usual. Real be_groups, page permissions, table and exclude
 * field rights and the image remediation capability; only the licence and the AI readiness answers are fixed.
 */
final class RemediationPreparePermissionFunctionalTest extends AbstractFunctionalTestCase
{
    private const READER = 2;
    private const EDITOR = 3;
    private const EDITOR_WITHOUT_IMAGES = 4;

    private int $findingUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $sites = $this->instancePath . '/typo3conf/sites/test';
        @mkdir($sites, 0777, true);
        file_put_contents($sites . '/config.yaml', "rootPageId: 1\nbase: 'https://example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n");
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Water tariffs', 'doktype' => 1, 'is_siteroot' => 1, 'perms_userid' => 1, 'perms_user' => 31, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
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

        $editorRights = [
            'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'tt_content,sys_file_reference', 'tables_modify' => 'tt_content,sys_file_reference',
            'non_exclude_fields' => 'tt_content:image,sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative',
            'explicit_allowdeny' => 'tt_content:CType:image',
        ];
        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 10, 'title' => 'Readers', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1', 'tables_select' => 'tt_content,sys_file_reference']);
        $groups->insert('be_groups', ['uid' => 11, 'title' => 'Editors', 'TSconfig' => 'options.a11y_quality_gate.allowImageRemediation = 1'] + $editorRights);
        $groups->insert('be_groups', ['uid' => 12, 'title' => 'Editors, image fixes switched off', 'TSconfig' => 'options.a11y_quality_gate.allowImageRemediation = 0'] + $editorRights);
        $users = $pool->getConnectionForTable('be_users');
        foreach ([[self::READER, 'reader', '10'], [self::EDITOR, 'editor', '11'], [self::EDITOR_WITHOUT_IMAGES, 'no-image-fixes', '12']] as [$uid, $name, $group]) {
            $users->insert('be_users', ['uid' => $uid, 'username' => $name, 'admin' => 0, 'usergroup' => $group, 'disable' => 0, 'deleted' => 0]);
        }
    }

    #[Test]
    public function aReadOnlyUserCreatesNoItemAndDoesNotBlockTheEditor(): void
    {
        $this->setUpBackendUser(self::READER);
        $result = $this->workflow()->prepare([$this->findingUid], RemediationAccessService::ORIGIN_EDITOR_REQUEST);

        self::assertSame(['itemUid' => 0, 'outcome' => 'skipped', 'reason' => 'no_edit_access'], array_diff_key($result['items'][0], ['findingUid' => 0]));
        self::assertSame(0, $this->itemCount());

        $this->setUpBackendUser(self::EDITOR);
        $result = $this->workflow()->prepare([$this->findingUid], RemediationAccessService::ORIGIN_EDITOR_REQUEST);
        self::assertSame('queued', $result['items'][0]['outcome'], json_encode($result));
        self::assertGreaterThan(0, $result['items'][0]['itemUid']);
        self::assertSame(1, $this->itemCount());
    }

    #[Test]
    public function anExplicitImageRemediationDenialAlsoStopsPreparation(): void
    {
        $this->setUpBackendUser(self::EDITOR_WITHOUT_IMAGES);

        $result = $this->workflow()->prepare([$this->findingUid], RemediationAccessService::ORIGIN_EDITOR_REQUEST);

        self::assertSame('no_edit_access', $result['items'][0]['reason']);
        self::assertSame(0, $this->itemCount());
    }

    private function itemCount(): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_item')
            ->fetchOne('SELECT COUNT(*) FROM tx_a11y_remediation_item');
    }

    private function workflow(): RemediationWorkflowService
    {
        $licence = $this->createMock(AiFeatureAccessServiceInterface::class);
        $licence->method('isAvailable')->willReturn(true);
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->willReturn(new AiProviderConfiguration('openai', 'sk-test', 'gpt-test', 'site'));
        $editorAi = new EditorAiAccessService(
            $licence,
            $resolver,
            $this->get(AiSiteSettingsService::class),
            $this->createMock(AiConfigurationRepositoryInterface::class),
            $this->get(BackendUserService::class),
        );
        $access = new RemediationAccessService(
            $this->get(BackendUserService::class),
            $this->get(ScopeAccessService::class),
            $this->get(BackendRecordAccessService::class),
            $editorAi,
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
}
