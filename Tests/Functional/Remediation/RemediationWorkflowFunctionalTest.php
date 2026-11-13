<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
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
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The remediation workflow against a real database and TYPO3's DataHandler: compare-and-set transitions, the
 * allow-listed image write with its base check, and verification by AQG's own structured image rule.
 */
final class RemediationWorkflowFunctionalTest extends AbstractFunctionalTestCase
{
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
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function twoTransitionsFromTheSameRowVersionNeverBothSucceed(): void
    {
        $repository = $this->get(RemediationItemRepository::class);
        $uid = $repository->insert(['site_identifier' => 'test', 'page_uid' => 1, 'finding_uid' => 1, 'status' => RemediationStatus::PENDING_REVIEW, 'origin' => 'bulk']);

        self::assertTrue($repository->transition($uid, 0, [RemediationStatus::PENDING_REVIEW], RemediationStatus::APPLYING, ['applied_by' => 1]));
        self::assertFalse($repository->transition($uid, 0, [RemediationStatus::PENDING_REVIEW], RemediationStatus::APPLYING, ['applied_by' => 2]));
        $row = $repository->findByUid($uid);
        self::assertSame(RemediationStatus::APPLYING, $row['status']);
        self::assertSame(1, (int)$row['row_version']);
        self::assertSame(1, (int)$row['applied_by']);
    }

    #[Test]
    public function anOwnerAssignmentIsOneRowPerPageAndRule(): void
    {
        $repository = $this->get(RemediationItemRepository::class);
        $repository->saveOwnerAssignment('test', 1, 'rendered.button_name', 'developer', 1);
        $repository->saveOwnerAssignment('test', 1, 'rendered.button_name', 'mixed', 1);

        self::assertSame('mixed', $repository->findOwnerAssignment('test', 1, 'rendered.button_name'));
        self::assertSame(1, (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_owner')->fetchOne('SELECT COUNT(*) FROM tx_a11y_remediation_owner'));
    }

    #[Test]
    public function anApprovedImageTextIsStoredThroughDataHandlerAndVerifiedByTheStructuredRule(): void
    {
        $service = $this->workflow();
        $uid = $this->pendingItem('Bar chart of water prices 2024 to 2026');

        $item = $service->approve($uid, 0, false);

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        self::assertSame('Bar chart of water prices 2024 to 2026', (string)$pool->getConnectionForTable('sys_file_reference')->fetchOne('SELECT alternative FROM sys_file_reference WHERE uid = 20'));
        self::assertSame(RemediationStatus::VERIFIED, $item['status'], 'AQG\'s structured check of the stored reference no longer reports the finding.');
        self::assertGreaterThan(0, (int)$pool->getConnectionForTable('sys_history')->fetchOne("SELECT COUNT(*) FROM sys_history WHERE tablename = 'sys_file_reference' AND recuid = 20"), 'The write is undoable in the record history.');
        $actions = array_column($this->get(RemediationItemRepository::class)->findEvents($uid), 'action');
        self::assertSame(['viewed', 'approved', 'saved', 'verified'], $actions);
    }

    #[Test]
    public function aReferenceChangedAfterTheSuggestionIsAConflictAndStaysUntouched(): void
    {
        $service = $this->workflow();
        $uid = $this->pendingItem('Bar chart');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->update('sys_file_reference', ['alternative' => 'Typed by a colleague'], ['uid' => 20]);

        $item = $service->approve($uid, 0, false);

        self::assertSame(RemediationStatus::CONFLICT, $item['status']);
        self::assertSame('Typed by a colleague', (string)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')->fetchOne('SELECT alternative FROM sys_file_reference WHERE uid = 20'));
    }

    /**
     * U-09 (2026-11-05 company acceptance): a bulk-prepared suggestion stayed "pending review" in My queue after its finding
     * was fixed by a later edit, and only a user with bulk rights could clear it. Opening the queue closes pre-apply items
     * whose finding is no longer open, for everyone; items of open findings and applied items stay as they are.
     */
    #[Test]
    public function itemsOfClosedFindingsAreClosedWithoutTheirOwner(): void
    {
        $stale = $this->pendingItem('Chart of tariffs');
        $repository = $this->get(RemediationItemRepository::class);
        $open = $repository->insert(['site_identifier' => 'test', 'page_uid' => 1, 'finding_uid' => $this->insertIssue([
            'site_identifier' => 'test', 'page_uid' => 1, 'source_table' => 'tt_content', 'source_uid' => 10, 'source_field' => 'bodytext',
            'source_type' => 'rte', 'rule_id' => 'rte.non_descriptive_link', 'context_path' => 'a', 'context_snippet' => 'b',
        ]), 'status' => RemediationStatus::PENDING_REVIEW, 'origin' => 'bulk']);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->update('tx_a11y_issue', ['status' => 1], ['uid' => $this->findingUid]);

        self::assertSame(1, $this->workflow()->closeItemsOfClosedFindings());
        self::assertSame(RemediationStatus::NOT_APPLICABLE, $repository->findByUid($stale)['status']);
        self::assertSame('finding_closed', $repository->findByUid($stale)['status_reason']);
        self::assertSame(RemediationStatus::PENDING_REVIEW, $repository->findByUid($open)['status']);
        self::assertSame(0, $this->workflow()->closeItemsOfClosedFindings(), 'Idempotent.');
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
