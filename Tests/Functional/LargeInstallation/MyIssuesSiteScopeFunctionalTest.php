<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\LargeInstallation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Assignment\AssignmentService;
use Priebera\A11yQualityGate\Contract\LocalFindingSourceAccessInterface;
use Priebera\A11yQualityGate\Service\LocalFindingSourceAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * HIGH-1 (large-installation stress test): My issues built the source-access filter of every site of the installation
 * for every user — an editor of one site out of 80 ran 32,000 page-access queries per request (6–7 s), administrators
 * and managers ran out of memory at 256 MB. The list now only touches the sites the user can read, streams the
 * findings and keeps the same tasks, counts and order.
 *
 * Site A root 1 → 2 Products, 3 Team. Site B root 20 → 21 Shop. Folder 40 → site C root 30 → 31 Contact.
 * Users: 1 admin · 2 editor of A (mount 1) · 3 editor inside B (mount 21) · 4 folder editor (mount 40, above site C's
 * root). All non-admins may assign.
 */
final class MyIssuesSiteScopeFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        foreach ([
            [1, 0, 'Site A', 1], [2, 1, 'Products', 0], [3, 1, 'Team', 0],
            [20, 0, 'Site B', 1], [21, 20, 'Shop', 0],
            [40, 0, 'Folder', 0], [30, 40, 'Site C', 1], [31, 30, 'Contact', 0],
        ] as [$uid, $pid, $title, $root]) {
            $pages->insert('pages', ['uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => '/' . $uid, 'doktype' => 1, 'is_siteroot' => $root,
                'perms_userid' => 1, 'perms_groupid' => 1, 'perms_user' => 31, 'perms_group' => 31, 'perms_everybody' => 1]);
        }
        $content = $pool->getConnectionForTable('tt_content');
        foreach ([[10, 2], [11, 3], [12, 21], [13, 31]] as [$uid, $pid]) {
            $content->insert('tt_content', ['uid' => $uid, 'pid' => $pid, 'CType' => 'text', 'header' => 'Element ' . $uid, 'sys_language_uid' => 0]);
        }
        $groups = $pool->getConnectionForTable('be_groups');
        foreach ([[1, '1'], [2, '21'], [3, '40']] as [$uid, $mount]) {
            $groups->insert('be_groups', ['uid' => $uid, 'title' => 'g' . $uid, 'groupMods' => 'web_a11y,web_layout', 'db_mountpoints' => $mount,
                'tables_select' => 'pages,tt_content', 'tables_modify' => 'pages,tt_content', 'allowed_languages' => '0',
                'TSconfig' => 'options.a11y_quality_gate.assign = 1']);
        }
        $users = $pool->getConnectionForTable('be_users');
        foreach ([[1, 'admin', 1, ''], [2, 'anna', 0, '1'], [3, 'ben', 0, '2'], [4, 'cleo', 0, '3']] as [$uid, $name, $admin, $usergroup]) {
            $users->insert('be_users', ['uid' => $uid, 'username' => $name, 'realName' => ucfirst($name), 'admin' => $admin, 'usergroup' => $usergroup, 'disable' => 0, 'deleted' => 0]);
        }
        $writer = $this->get(SiteWriter::class);
        $writer->createNewBasicSite('site-a', 1, 'https://a.invalid/');
        $writer->createNewBasicSite('site-b', 20, 'https://b.invalid/');
        $writer->createNewBasicSite('site-c', 30, 'https://c.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);

        $finding = fn (string $site, int $page, int $source, string $rule, int $severity): int => $this->insertIssue([
            'site_identifier' => $site, 'page_uid' => $page, 'severity' => $severity, 'rule_id' => $rule,
            'source_table' => 'tt_content', 'source_uid' => $source, 'message' => $rule . ' on ' . $page,
        ]);
        $finding('site-a', 2, 10, 'rte.empty_link', 1);
        $finding('site-a', 2, 10, 'rte.empty_heading', 2);
        $finding('site-a', 3, 11, 'rte.empty_link', 2);
        $finding('site-b', 21, 12, 'rte.empty_link', 1);
        $finding('site-c', 31, 13, 'rte.empty_link', 1);
    }

    #[Test]
    public function aSingleSiteEditorOnlyPaysForItsOwnSite(): void
    {
        $this->setUpBackendUser(2);
        $counting = $this->countingSourceAccess();
        $list = $this->serviceWith($counting)->listTasks(AssignmentService::FILTER_ALL, 'any');

        self::assertSame(['site-a'], array_values(array_unique($counting->sites)), 'no access filter is built for sites the editor cannot read');
        // Order unchanged: severity, open occurrences, page title, task key.
        self::assertSame([['site-a', 2, 'rte.empty_link'], ['site-a', 2, 'rte.empty_heading'], ['site-a', 3, 'rte.empty_link']], $this->tasks($list));
        self::assertSame(3, $list['total']);
        self::assertSame(3, $list['unassignedOpen']);
    }

    #[Test]
    public function aMountAboveASiteRootStillReadsThatSite(): void
    {
        $this->setUpBackendUser(4);
        $counting = $this->countingSourceAccess();
        $list = $this->serviceWith($counting)->listTasks(AssignmentService::FILTER_ALL, 'any');

        self::assertSame(['site-c'], array_values(array_unique($counting->sites)));
        self::assertSame([['site-c', 31, 'rte.empty_link']], $this->tasks($list));
    }

    #[Test]
    public function aMountInsideASiteReadsOnlyThatSite(): void
    {
        $this->setUpBackendUser(3);
        $counting = $this->countingSourceAccess();
        $list = $this->serviceWith($counting)->listTasks(AssignmentService::FILTER_ALL, 'any');

        self::assertSame(['site-b'], array_values(array_unique($counting->sites)));
        self::assertSame([['site-b', 21, 'rte.empty_link']], $this->tasks($list));
    }

    #[Test]
    public function administratorsStillSeeEverySiteInTheSameOrderAndPages(): void
    {
        $this->setUpBackendUser(1);
        $service = $this->get(AssignmentService::class);
        $all = $service->listTasks(AssignmentService::FILTER_ALL, 'any', perPage: 10);
        $first = $service->listTasks(AssignmentService::FILTER_ALL, 'any', page: 1, perPage: 2);
        $second = $service->listTasks(AssignmentService::FILTER_ALL, 'any', page: 2, perPage: 2);
        $third = $service->listTasks(AssignmentService::FILTER_ALL, 'any', page: 3, perPage: 2);

        self::assertSame(5, $all['total']);
        self::assertSame(3, $first['pages']);
        self::assertSame($this->tasks($all), array_merge($this->tasks($first), $this->tasks($second), $this->tasks($third)), 'paging neither skips nor repeats a task');
        // The page window is loaded in full: the stored message reaches the task.
        self::assertNotSame('', (string)($all['tasks'][0]['title'] ?? ''));
    }

    #[Test]
    public function searchStillMatchesThePageTitleOfAStreamedTask(): void
    {
        $this->setUpBackendUser(1);
        $list = $this->get(AssignmentService::class)->listTasks(AssignmentService::FILTER_ALL, 'any', query: 'team');

        self::assertSame([['site-a', 3, 'rte.empty_link']], $this->tasks($list));
    }

    /** @return list<array{0:string,1:int,2:string}> */
    private function tasks(array $list): array
    {
        return array_map(static fn (array $t): array => [$t['siteIdentifier'], $t['pageUid'], $t['issueKey']], $list['tasks']);
    }

    private function countingSourceAccess(): object
    {
        $inner = $this->get(LocalFindingSourceAccessService::class);

        return new class ($inner) implements LocalFindingSourceAccessInterface {
            /** @var list<string> */
            public array $sites = [];

            public function __construct(private readonly LocalFindingSourceAccessInterface $inner)
            {
            }

            public function constraint(QueryBuilder $qb, string $issueAlias, string $siteIdentifier): string
            {
                $this->sites[] = $siteIdentifier;

                return $this->inner->constraint($qb, $issueAlias, $siteIdentifier);
            }

            public function canReadFinding(array $issue): bool
            {
                return $this->inner->canReadFinding($issue);
            }

            public function filter(array $issues): array
            {
                return $this->inner->filter($issues);
            }

            public function canReadRecord(string $table, int $uid): bool
            {
                return $this->inner->canReadRecord($table, $uid);
            }
        };
    }

    /** The container's AssignmentService with its source-access dependency replaced by the counting decorator. */
    private function serviceWith(LocalFindingSourceAccessInterface $sourceAccess): AssignmentService
    {
        $constructor = (new \ReflectionClass(AssignmentService::class))->getConstructor();
        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $type = (string)$parameter->getType();
            $type = ltrim($type, '?');
            if ($type === LocalFindingSourceAccessInterface::class) {
                $arguments[] = $sourceAccess;
                continue;
            }
            $arguments[] = $this->get($type);
        }

        return new AssignmentService(...$arguments);
    }
}
