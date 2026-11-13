<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\IssueApiController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Ignore from CKEditor / plain HTML (a11y_ignore) follows the same rule as Ignore in Page Detail: the editRecord
 * capability (User TSconfig options.a11y_quality_gate.editRecord, default 1) and edit access to the content record.
 * A read-only user, a user with editRecord = 0 and a fingerprint of another record change nothing.
 */
final class RteIgnorePermissionFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'fixture';

    private int $issueUid = 0;
    private int $otherIssueUid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        foreach ([[1, 0, 1, 31], [2, 1, 0, 31], [3, 1, 0, 0]] as [$uid, $pid, $root, $everybody]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => 'Page ' . $uid, 'slug' => '/p' . $uid, 'doktype' => 1, 'is_siteroot' => $root,
                'perms_userid' => 99, 'perms_user' => 31, 'perms_everybody' => $everybody,
            ]);
        }
        $content = $pool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 10, 'pid' => 2, 'CType' => 'text', 'bodytext' => '<p><img src="a.jpg"></p>']);
        $content->insert('tt_content', ['uid' => 11, 'pid' => 3, 'CType' => 'text', 'bodytext' => '<p><img src="b.jpg"></p>']);

        $groups = $pool->getConnectionForTable('be_groups');
        $groups->insert('be_groups', ['uid' => 10, 'title' => 'Editors', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1', 'tables_modify' => 'tt_content', 'tables_select' => 'tt_content', 'non_exclude_fields' => 'tt_content:bodytext']);
        $groups->insert('be_groups', ['uid' => 11, 'title' => 'Editors without ignore', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1', 'tables_modify' => 'tt_content', 'tables_select' => 'tt_content', 'TSconfig' => 'options.a11y_quality_gate.editRecord = 0']);
        $groups->insert('be_groups', ['uid' => 12, 'title' => 'Readers', 'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => '1', 'tables_select' => 'tt_content']);
        $users = $pool->getConnectionForTable('be_users');
        foreach ([[2, 'editor', '10'], [3, 'no-ignore', '11'], [4, 'reader', '12']] as [$uid, $name, $group]) {
            $users->insert('be_users', ['uid' => $uid, 'username' => $name, 'admin' => 0, 'usergroup' => $group, 'disable' => 0, 'deleted' => 0]);
        }

        $this->issueUid = $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 2, 'source_uid' => 10, 'fingerprint' => 'fp-10']);
        $this->otherIssueUid = $this->insertIssue(['site_identifier' => self::SITE, 'page_uid' => 3, 'source_uid' => 11, 'fingerprint' => 'fp-11']);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, 1, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function anEditorIgnoresAFindingOfTheirRecord(): void
    {
        $this->setUpBackendUser(2);

        $response = $this->get(IssueApiController::class)->ignoreAction($this->request('fp-10', 10));

        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertNotSame(0, $this->issueStatus($this->issueUid));
    }

    #[Test]
    public function editRecordZeroIsEnforcedForTheEditorEndpoint(): void
    {
        $this->setUpBackendUser(3);

        $response = $this->get(IssueApiController::class)->ignoreAction($this->request('fp-10', 10));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->issueStatus($this->issueUid));
    }

    #[Test]
    public function aReadOnlyUserChangesNothing(): void
    {
        $this->setUpBackendUser(4);

        $response = $this->get(IssueApiController::class)->ignoreAction($this->request('fp-10', 10));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->issueStatus($this->issueUid));
    }

    #[Test]
    public function aForgedFingerprintOrRecordIsRefused(): void
    {
        $this->setUpBackendUser(2);
        $controller = $this->get(IssueApiController::class);

        // Another record's finding, claimed for the editor's own record, and that record directly (page 3 is not editable).
        self::assertSame(403, $controller->ignoreAction($this->request('fp-11', 10))->getStatusCode());
        self::assertSame(403, $controller->ignoreAction($this->request('fp-11', 11))->getStatusCode());
        self::assertSame(0, $this->issueStatus($this->otherIssueUid));
    }

    private function request(string $fingerprint, int $recordUid): ServerRequest
    {
        $body = json_encode(['fingerprint' => $fingerprint, 'recordUid' => $recordUid, 'fieldName' => 'bodytext', 'siteIdentifier' => self::SITE], JSON_THROW_ON_ERROR);
        $request = (new ServerRequest('https://typo3-testing.local/typo3/ajax/a11y/ignore', 'POST'))
            ->withBody((new StreamFactory())->createStream($body))
            ->withHeader('Content-Type', 'application/json')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function issueStatus(int $issueUid): int
    {
        return (int)$this->findByUid(Tables::ISSUE, $issueUid)['status'];
    }
}
