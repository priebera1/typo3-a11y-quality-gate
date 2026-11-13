<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ScanAjaxController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * A content site/subtree scan started by a backend user processes only the pages that user could scan one by one
 * (edit access to the page). A page they may not edit is neither read nor resolved, and the open totals in the
 * answer leave it out. A forged rootPid outside their edit access is refused. Administrators scan the whole tree.
 */
final class SubtreeScanPermissionFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'fixture';
    private const OTHER_SITE_ROOT = 100;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        // [uid, pid, title, slug, siteroot, owner, perms_user, perms_everybody]
        foreach ([
            [1, 0, 'Fixture', '/', 1, 0, 0, 31],
            [2, 1, 'Team page', '/team', 0, 0, 0, 31],
            [3, 1, 'Board only', '/board', 0, 99, 31, 0],
            [4, 1, 'Read only', '/read-only', 0, 99, 31, 1],
            [self::OTHER_SITE_ROOT, 0, 'Other client', '/', 1, 0, 0, 31],
        ] as [$uid, $pid, $title, $slug, $root, $owner, $permsUser, $permsEverybody]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $root,
                'perms_userid' => $owner, 'perms_groupid' => 0, 'perms_user' => $permsUser, 'perms_group' => 0,
                'perms_everybody' => $permsEverybody,
            ]);
        }
        $content = $pool->getConnectionForTable('tt_content');
        foreach ([[10, 2], [11, 3], [12, 4]] as [$uid, $pid]) {
            $content->insert('tt_content', ['uid' => $uid, 'pid' => $pid, 'CType' => 'text', 'bodytext' => '<p><a href="/contact"></a></p>']);
        }
        $pool->getConnectionForTable(Tables::FIELD_CONFIG)->insert(Tables::FIELD_CONFIG, [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'title' => 'Editors', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1',
            'tables_modify' => 'pages,tt_content', 'tables_select' => 'pages,tt_content',
        ]);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => 2, 'username' => 'editor', 'admin' => 0, 'usergroup' => '10', 'disable' => 0, 'deleted' => 0]);

        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->createNewBasicSite(self::SITE, 1, 'https://fixture.invalid/');
        $siteWriter->createNewBasicSite('other', self::OTHER_SITE_ROOT, 'https://other.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorsSiteScanSkipsPagesTheyMayNotEditAndKeepsTheirFindings(): void
    {
        $staleOnBoardPage = $this->insertIssue([
            'site_identifier' => self::SITE, 'page_uid' => 3, 'source_uid' => 11, 'source_type' => 'rte',
            'rule_id' => 'rte.img_alt_missing', 'context_snippet' => '<img src="board.jpg">',
        ]);
        $this->setUpBackendUser(2);

        $response = $this->get(ScanAjaxController::class)->scanSiteAction($this->request(['rootPid' => '1']));
        $payload = json_decode((string)$response->getBody(), true);

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertTrue($payload['success']);
        self::assertSame([2], $this->openFindingPages(), 'only the editable page was scanned');
        self::assertSame(0, (int)$this->findByUid(Tables::ISSUE, $staleOnBoardPage)['status'], 'a skipped page keeps its findings');
        self::assertSame(
            count($this->openScanFindings()),
            (int)($payload['openIssues']['total'] ?? -1),
            'the totals in the answer leave out page 3, which the editor may not show (it holds an open finding)'
        );
    }

    #[Test]
    public function aForgedRootOutsideTheEditorsEditAccessIsRefused(): void
    {
        $this->setUpBackendUser(2);
        $controller = $this->get(ScanAjaxController::class);

        foreach ([3, 4, self::OTHER_SITE_ROOT] as $rootPid) {
            $response = $controller->scanSiteAction($this->request(['rootPid' => (string)$rootPid]));
            self::assertSame(403, $response->getStatusCode(), 'rootPid ' . $rootPid);
        }
        self::assertSame([], $this->openFindingPages());
        self::assertFalse($this->get(ScanStatusService::class)->isRunning());
    }

    #[Test]
    public function anAdministratorScansTheWholeTree(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->get(ScanAjaxController::class)->scanSiteAction($this->request(['rootPid' => '1']));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame([2, 3, 4], $this->openFindingPages());
    }

    private function request(array $body): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/ajax/a11y/scan/site', 'POST'))
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    /** @return list<int> pages with an open finding stored by the scan (the seeded stale finding excluded) */
    private function openFindingPages(): array
    {
        $pages = array_map(static fn (array $row): int => (int)$row['page_uid'], $this->openScanFindings());
        $pages = array_values(array_unique($pages));
        sort($pages);

        return $pages;
    }

    /** @return list<array<string, mixed>> */
    private function openScanFindings(): array
    {
        return $this->getConnectionPool()->getConnectionForTable(Tables::ISSUE)
            ->fetchAllAssociative(
                'SELECT page_uid, rule_id FROM tx_a11y_issue WHERE deleted = 0 AND status = 0 AND rule_id <> ?',
                ['rte.img_alt_missing']
            );
    }
}
