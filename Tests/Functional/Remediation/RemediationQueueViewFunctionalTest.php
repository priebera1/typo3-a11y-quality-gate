<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\RemediationAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationChangeLogService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationItemData;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetResolver;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The read-only views of the AI fixes module against a real database: the change log is scoped exactly like the queue
 * (page access, own requests without ai.bulk), and the three-way conflict payload reads the current value without
 * writing anything and only for an item the person may see.
 */
final class RemediationQueueViewFunctionalTest extends AbstractFunctionalTestCase
{
    private int $findingUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $sites = $this->instancePath . '/typo3conf/sites/test';
        @mkdir($sites, 0777, true);
        file_put_contents($sites . '/config.yaml', "rootPageId: 1\nbase: 'https://example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n");
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'pid' => 0, 'title' => 'Editors without the queue permission', 'groupMods' => 'web,web_a11y,content',
            'tables_select' => 'pages,tt_content,sys_file_reference', 'tables_modify' => 'tt_content,sys_file_reference',
            'non_exclude_fields' => 'sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative',
            'db_mountpoints' => '1', 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'realName' => 'Ada Admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 2, 'username' => 'eva', 'realName' => 'Eva Editor', 'admin' => 0, 'usergroup' => '10', 'workspace_perms' => '1', 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Water tariffs', 'doktype' => 1, 'is_siteroot' => 1, 'perms_userid' => 1, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        // Outside Eva's web mount.
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 2, 'pid' => 0, 'title' => 'Intranet', 'doktype' => 1, 'perms_userid' => 1, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
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
    }

    #[Test]
    public function administratorsSeeEveryRecordedStepOfTheLiveWorkspaceButNoViews(): void
    {
        $this->setUpBackendUser(1);
        $this->seedLog();
        $log = $this->get(RemediationChangeLogService::class);

        $admin = $log->list(['test'], []);
        self::assertSame(4, $admin['total'], 'Every item of the live workspace; another workspace and views are left out.');
        self::assertNotContains('viewed', array_map(static fn (array $entry): string => (string)$entry['event']['action'], $admin['entries']));
        self::assertSame([1, 2], $admin['people']);
        self::assertSame(['rejected'], array_map(static fn (array $entry): string => (string)$entry['event']['action'], $log->list(['test'], ['action' => 'rejected'])['entries']));
        self::assertSame(3, $log->list(['test'], ['person' => 1])['total']);
        self::assertSame(1, $log->list(['test'], ['origin' => 'bulk'])['total']);
        self::assertSame(0, $log->list(['other-site'], [])['total']);
    }

    #[Test]
    public function editorsWithoutTheQueuePermissionSeeOnlyTheirOwnRequestsOnReadablePages(): void
    {
        $this->setUpBackendUser(2);
        ['own' => $own] = $this->seedLog();

        $editor = $this->get(RemediationChangeLogService::class)->list(['test'], []);

        $items = array_values(array_unique(array_map(static fn (array $entry): int => (int)$entry['item']['uid'], $editor['entries'])));
        self::assertSame([$own], $items, 'Not the bulk item, not the request on a page outside the web mount, not another workspace.');
        self::assertSame(2, $editor['total']);
    }

    /** @return array{own:int} */
    private function seedLog(): array
    {
        $repository = $this->get(RemediationItemRepository::class);
        $own = $repository->insert($this->itemRow(['created_by' => 2, 'origin' => 'editor_request']));
        $bulkItem = $repository->insert($this->itemRow(['created_by' => 1, 'origin' => 'bulk']));
        $otherPage = $repository->insert($this->itemRow(['created_by' => 2, 'page_uid' => 2, 'origin' => 'editor_request']));
        $otherWorkspace = $repository->insert($this->itemRow(['created_by' => 2, 'workspace_id' => 7, 'origin' => 'editor_request']));
        foreach ([$own, $bulkItem, $otherPage, $otherWorkspace] as $uid) {
            $repository->addEvent(['item_uid' => $uid, 'be_user' => 1, 'action' => 'suggested', 'from_status' => 'preparing', 'to_status' => 'pending_review', 'proposal_version' => 1, 'value_hash' => str_repeat('a', 64)]);
            $repository->addEvent(['item_uid' => $uid, 'be_user' => 2, 'action' => 'viewed', 'from_status' => 'pending_review', 'to_status' => 'pending_review', 'proposal_version' => 1]);
        }
        $repository->addEvent(['item_uid' => $own, 'be_user' => 2, 'action' => 'rejected', 'from_status' => 'pending_review', 'to_status' => 'rejected', 'detail' => 'factually_wrong']);

        return ['own' => $own];
    }

    #[Test]
    public function theChangeLogPeriodLeavesOlderStepsOut(): void
    {
        $this->setUpBackendUser(1);
        $repository = $this->get(RemediationItemRepository::class);
        $uid = $repository->insert($this->itemRow(['created_by' => 1]));
        $repository->addEvent(['item_uid' => $uid, 'be_user' => 1, 'action' => 'suggested', 'from_status' => 'preparing', 'to_status' => 'pending_review']);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_event')
            ->insert('tx_a11y_remediation_event', ['pid' => 0, 'crdate' => time() - 20 * 86400, 'item_uid' => $uid, 'be_user' => 1, 'action' => 'prepared', 'to_status' => 'preparing']);
        $log = $this->get(RemediationChangeLogService::class);

        self::assertSame(2, $log->list(['test'], ['period' => '30'])['total']);
        self::assertSame(1, $log->list(['test'], ['period' => '7'])['total']);
    }

    #[Test]
    public function aConflictShowsBaseSavedNowAndSuggestionWithoutWritingAnything(): void
    {
        $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $uid = $this->conflictItem(createdBy: 1);
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('sys_file_reference')->update('sys_file_reference', ['alternative' => 'Typed by a colleague'], ['uid' => 20]);
        $before = $this->snapshot($uid);

        $answer = $this->itemRequest($uid);

        self::assertSame(200, $answer['status']);
        self::assertSame(['base' => '', 'current' => 'Typed by a colleague', 'currentKnown' => true, 'currentDecorative' => false, 'proposed' => 'Bar chart of water prices', 'reason' => 'target_changed'], $answer['body']['item']['conflict']);
        self::assertFalse($answer['body']['item']['actions']['approve'], 'A conflict is never approvable from this view.');
        self::assertSame($before, $this->snapshot($uid), 'Reading the conflict wrote nothing: reference, item row and history are unchanged.');
    }

    #[Test]
    public function aConflictOfAnotherPersonsRequestStaysHiddenWithoutTheQueuePermission(): void
    {
        $this->setUpBackendUser(2);
        $uid = $this->conflictItem(createdBy: 1);
        $before = $this->snapshot($uid);

        $answer = $this->itemRequest($uid);

        self::assertSame(404, $answer['status']);
        self::assertSame('not_found', $answer['body']['code']);
        self::assertArrayNotHasKey('item', $answer['body']);
        self::assertSame($before, $this->snapshot($uid));
    }

    /** @return array{status:int,body:array<string,mixed>} */
    private function itemRequest(int $uid): array
    {
        $request = (new ServerRequest('https://example.test/typo3/ajax/a11y/remediation/item', 'POST'))
            ->withBody((new StreamFactory())->createStream(json_encode(['item' => $uid, 'detailed' => true], JSON_THROW_ON_ERROR)));
        $response = $this->get(RemediationAjaxController::class)->itemAction($request);

        return ['status' => $response->getStatusCode(), 'body' => json_decode((string)$response->getBody(), true, 32, JSON_THROW_ON_ERROR)];
    }

    /** @return array<string,mixed> */
    private function snapshot(int $itemUid): array
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);

        return [
            'reference' => $pool->getConnectionForTable('sys_file_reference')->fetchAssociative('SELECT alternative, tx_a11y_is_decorative, tstamp FROM sys_file_reference WHERE uid = 20'),
            'item' => $pool->getConnectionForTable('tx_a11y_remediation_item')->fetchAssociative('SELECT status, row_version, tstamp FROM tx_a11y_remediation_item WHERE uid = ?', [$itemUid]),
            'events' => (int)$pool->getConnectionForTable('tx_a11y_remediation_event')->fetchOne('SELECT COUNT(*) FROM tx_a11y_remediation_event'),
            'history' => (int)$pool->getConnectionForTable('sys_history')->fetchOne('SELECT COUNT(*) FROM sys_history'),
        ];
    }

    /** A suggestion for the image use that ran into a conflict when it was applied. */
    private function conflictItem(int $createdBy): int
    {
        $issue = $this->get(IssueRepository::class)->findByUid($this->findingUid);
        $target = $this->get(RemediationTargetResolver::class)->resolve($issue);
        self::assertTrue($target->isAvailable(), 'The finding resolves to its exact image use: ' . $target->unavailableReason);

        return $this->get(RemediationItemRepository::class)->insert($this->itemRow([
            'finding_uid' => $this->findingUid, 'finding_fingerprint' => (string)$issue['fingerprint'], 'target_kind' => $target->kind,
            'target_table' => $target->table, 'target_uid' => $target->uid, 'target_field' => $target->field,
            'target_descriptor' => RemediationItemData::encodeDescriptor($target->descriptor), 'base_hash' => $target->baseHash,
            'original_value' => $target->currentValue, 'proposed_value' => 'Bar chart of water prices',
            'status' => RemediationStatus::CONFLICT, 'status_reason' => 'target_changed', 'created_by' => $createdBy, 'origin' => 'bulk',
        ]));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function itemRow(array $overrides): array
    {
        return $overrides + [
            'site_identifier' => 'test', 'page_uid' => 1, 'language_uid' => 0, 'workspace_id' => 0, 'finding_uid' => 0,
            'rule_id' => 'structured.file_reference_alt', 'severity' => 1, 'target_kind' => 'image_reference_alt', 'owner' => 'editor',
            'status' => RemediationStatus::PENDING_REVIEW, 'origin' => 'bulk', 'created_by' => 1, 'proposal_version' => 1,
            'original_value' => '', 'proposed_value' => 'Bar chart', 'edited_value' => '',
            // The image use of the fixture: queue items are listed only while their record is readable (Sol QA-02 review).
            'target_table' => 'sys_file_reference', 'target_uid' => 20, 'target_field' => 'alternative',
            'target_descriptor' => json_encode(['parentTable' => 'tt_content', 'parentUid' => 10, 'fieldName' => 'image']),
        ];
    }
}
