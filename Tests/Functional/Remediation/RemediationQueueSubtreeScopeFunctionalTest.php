<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\RemediationItemRepository;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationChangeLogService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationQueueService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * N-09 (final customer acceptance): an editor whose web mount is a subtree of the site (not the site root) saw an empty
 * AI fixes queue, also for the suggestions they had requested themselves, because the queue only listed sites whose root
 * the user may read. A site is listable as soon as the user may read any page of it; every item stays filtered by page,
 * record, workspace and (without ai.bulk) by its creator.
 */
final class RemediationQueueSubtreeScopeFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['test' => 1, 'other' => 5] as $identifier => $root) {
            $dir = $this->instancePath . '/typo3conf/sites/' . $identifier;
            @mkdir($dir, 0777, true);
            file_put_contents($dir . '/config.yaml', "rootPageId: $root\nbase: 'https://$identifier.example.test/'\nlanguages:\n  - languageId: 0\n    title: English\n    locale: en_US.UTF-8\n    base: /\n");
        }
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $group = static fn (int $uid, string $mounts): array => [
            'uid' => $uid, 'pid' => 0, 'title' => 'Group ' . $uid, 'groupMods' => 'web,web_a11y,content',
            'tables_select' => 'pages,tt_content', 'tables_modify' => 'tt_content', 'db_mountpoints' => $mounts, 'deleted' => 0, 'hidden' => 0,
        ];
        $pool->getConnectionForTable('be_groups')->insert('be_groups', $group(10, '3'));
        $pool->getConnectionForTable('be_groups')->insert('be_groups', $group(11, '7'));
        $user = static fn (int $uid, string $name, string $groups): array => ['uid' => $uid, 'username' => $name, 'realName' => $name, 'admin' => 0, 'usergroup' => $groups, 'workspace_perms' => '1', 'disable' => 0, 'deleted' => 0];
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('be_users')->insert('be_users', $user(2, 'mona', '10'));
        $pool->getConnectionForTable('be_users')->insert('be_users', $user(3, 'bernd', '10'));
        $pool->getConnectionForTable('be_users')->insert('be_users', $user(4, 'karin', '11'));
        $page = static fn (int $uid, int $pid, string $title, int $root = 0): array => ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'doktype' => 1, 'is_siteroot' => $root, 'perms_userid' => 1, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0];
        $pool->getConnectionForTable('pages')->insert('pages', $page(1, 0, 'Corporate', 1));
        $pool->getConnectionForTable('pages')->insert('pages', $page(2, 1, 'Private customers'));
        $pool->getConnectionForTable('pages')->insert('pages', $page(3, 1, 'Business customers'));
        $pool->getConnectionForTable('pages')->insert('pages', $page(4, 3, 'Charging stations'));
        $pool->getConnectionForTable('pages')->insert('pages', $page(5, 0, 'Magazine', 1));
        $pool->getConnectionForTable('pages')->insert('pages', $page(7, 5, 'Stories'));
        foreach ([40 => 4, 41 => 2, 42 => 7] as $uid => $pid) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => $uid, 'pid' => $pid, 'CType' => 'text', 'header' => 'Block ' . $uid, 'bodytext' => '<h3></h3>', 'deleted' => 0, 'hidden' => 0]);
        }
    }

    #[Test]
    public function aSiteIsListableForAnEditorWhoseMountIsASubtreeOfIt(): void
    {
        $this->setUpBackendUser(2);
        $scope = $this->get(ScopeAccessService::class);

        self::assertFalse($scope->canReadSiteIdentifier('test'), 'Site-level results still need the root (unchanged).');
        self::assertTrue($scope->canReadWithinSiteIdentifier('test'), 'A mount inside the site makes the site listable.');
        self::assertFalse($scope->canReadWithinSiteIdentifier('other'), 'A site without any mounted page stays out.');
        self::assertFalse($scope->canReadWithinSiteIdentifier('invented'), 'An unknown site is never listable.');
        self::assertTrue($this->get(RemediationAccessService::class)->canListSiteIdentifier('test'));
    }

    #[Test]
    public function theSubtreeEditorSeesOwnPendingSuggestionButNotAColleaguesOrOneOutsideTheMount(): void
    {
        $repository = $this->get(RemediationItemRepository::class);
        $own = $repository->insert($this->itemRow(['created_by' => 2, 'page_uid' => 4, 'target_uid' => 40]));
        $colleague = $repository->insert($this->itemRow(['created_by' => 3, 'page_uid' => 4, 'target_uid' => 40]));
        $outsideMount = $repository->insert($this->itemRow(['created_by' => 2, 'page_uid' => 2, 'target_uid' => 41]));
        $otherSite = $repository->insert($this->itemRow(['site_identifier' => 'other', 'created_by' => 4, 'page_uid' => 7, 'target_uid' => 42]));
        foreach ([$own, $colleague, $outsideMount, $otherSite] as $uid) {
            $repository->addEvent(['item_uid' => $uid, 'be_user' => 1, 'action' => 'suggested', 'from_status' => 'preparing', 'to_status' => 'pending_review', 'proposal_version' => 1, 'value_hash' => str_repeat('a', 64)]);
        }
        $this->setUpBackendUser(2);

        $sites = array_values(array_filter(['test', 'other'], fn (string $s): bool => $this->get(RemediationAccessService::class)->canListSiteIdentifier($s)));
        $list = $this->get(RemediationQueueService::class)->list($sites, ['tab' => 'open']);

        self::assertSame(['test'], $sites);
        self::assertSame([$own], array_map(static fn (array $item): int => (int)$item['uid'], $list['items']));
        self::assertSame(1, $list['counts']['open']);
        self::assertSame(1, $list['counts']['mine']);
        self::assertSame([$own], array_values(array_unique(array_map(static fn (array $e): int => (int)$e['item']['uid'], $this->get(RemediationChangeLogService::class)->list($sites, [])['entries']))));
        self::assertNotContains($colleague, array_map(static fn (array $item): int => (int)$item['uid'], $list['items']));
        self::assertNotContains($outsideMount, array_map(static fn (array $item): int => (int)$item['uid'], $list['items']));
        self::assertNotContains($otherSite, array_map(static fn (array $item): int => (int)$item['uid'], $list['items']));
    }

    #[Test]
    public function aMagazineEditorSeesNothingOfTheCorporateSiteAndTheAdministratorSeesEverySite(): void
    {
        $repository = $this->get(RemediationItemRepository::class);
        $repository->insert($this->itemRow(['created_by' => 2, 'page_uid' => 4, 'target_uid' => 40]));
        $this->setUpBackendUser(4);
        self::assertFalse($this->get(RemediationAccessService::class)->canListSiteIdentifier('test'));

        $this->setUpBackendUser(1);
        self::assertTrue($this->get(RemediationAccessService::class)->canListSiteIdentifier('test'));
        self::assertTrue($this->get(RemediationAccessService::class)->canListSiteIdentifier('other'));
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function itemRow(array $overrides): array
    {
        $now = time();
        $row = $overrides + [
            'site_identifier' => 'test', 'language_uid' => 0, 'workspace_id' => 0, 'finding_uid' => 0,
            'rule_id' => 'rte.empty_heading', 'severity' => 1, 'target_kind' => 'rich_text', 'owner' => 'editor',
            'status' => RemediationStatus::PENDING_REVIEW, 'origin' => 'editor_request', 'proposal_version' => 1,
            'original_value' => '', 'proposed_value' => 'Heading', 'edited_value' => '',
            'target_table' => 'tt_content', 'target_field' => 'bodytext', 'target_descriptor' => '{}', 'crdate' => $now, 'tstamp' => $now,
        ];
        $row['target_uid'] ??= 40;

        return $row;
    }
}
