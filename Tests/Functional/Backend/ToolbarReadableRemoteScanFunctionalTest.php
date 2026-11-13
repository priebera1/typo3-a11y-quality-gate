<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Functional\Security\RemoteResultFixtureTrait;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * F11-04 (independent F11 review): the toolbar's Frontend card took the single newest paid page scan and then refused it
 * when the user could not read its page, so an editor saw "Not scanned yet" although an older scan of a page they may
 * read existed. The card now chooses the newest scan the user may read, from bounded windows of the same site and
 * language, and never names an unreadable one.
 *
 * Fixture (RemoteResultFixtureTrait): page 2 "/public" readable by editors, page 3 "/restricted-page" not; no site scan.
 */
final class ToolbarReadableRemoteScanFunctionalTest extends AbstractFunctionalTestCase
{
    use RemoteResultFixtureTrait;

    private const JOB_RESTRICTED = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRemoteResultFixture();
        $pool = $this->get(ConnectionPool::class);
        // No eligible site scan: the card falls back to page scans.
        $pool->getConnectionForTable('tx_a11y_remote_scan')->delete('tx_a11y_remote_scan', ['uid' => 1]);
        // Scan 2 (page 2, finished 1002) is the older readable one; scan 3 of the restricted page is newer.
        $this->insertPageScan($pool, 3, 3, self::JOB_RESTRICTED, 5000);
    }

    #[Test]
    public function anEditorGetsTheNewestPageScanTheyMayReadInsteadOfNothing(): void
    {
        self::assertSame(2, (int)($this->resolve(self::EDITOR)['uid'] ?? 0), 'older readable scan of page 2, not "Not scanned yet"');
    }

    #[Test]
    public function anAdministratorStillGetsTheNewestScan(): void
    {
        self::assertSame(3, (int)($this->resolve(self::ADMIN)['uid'] ?? 0));
    }

    #[Test]
    public function aNewerUnreadableScanLeavesNoTraceInTheEditorsCard(): void
    {
        $scan = $this->resolve(self::EDITOR);

        self::assertSame(self::JOB_PAGE, $scan['job_id'] ?? null);
        self::assertSame(1002, (int)$scan['finished_at'], 'the date is the readable scan\'s own');
        self::assertStringNotContainsString(self::JOB_RESTRICTED, (string)json_encode($scan));
        self::assertStringNotContainsString('restricted-page', (string)json_encode($scan));
    }

    #[Test]
    public function aRunningScanOfAnUnreadablePageDoesNotHideTheReadableResult(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')
            ->update('tx_a11y_remote_scan', ['status' => 'running', 'finished_at' => 0, 'persisted_at' => 0, 'started_at' => 6000], ['uid' => 3]);

        self::assertSame(2, (int)($this->resolve(self::EDITOR)['uid'] ?? 0));
        self::assertSame(3, (int)($this->resolve(self::ADMIN)['uid'] ?? 0), 'the administrator sees the running scan first');
    }

    #[Test]
    public function theLanguageBoundaryHolds(): void
    {
        $pool = $this->get(ConnectionPool::class);
        // A newer German scan of the readable page is no candidate for the default-language toolbar, for anyone.
        $this->insertPageScan($pool, 4, 2, '44444444-4444-4444-8444-444444444444', 7000, 1);

        self::assertSame(2, (int)($this->resolve(self::EDITOR)['uid'] ?? 0));
        self::assertSame(3, (int)($this->resolve(self::ADMIN)['uid'] ?? 0));
    }

    #[Test]
    public function aNewerScanOfAPageInALanguageTheUserMayNotUseIsSkipped(): void
    {
        $pool = $this->get(ConnectionPool::class);
        // A newer default-language scan of the German translation record's uid (pre-I-01 legacy row): its content is
        // German, which the default-language-only user may not read; the older readable scan is shown instead.
        $this->insertPageScan($pool, 4, 20, '44444444-4444-4444-8444-444444444444', 7000);

        self::assertSame(2, (int)($this->resolve(self::DEFAULT_LANGUAGE_ONLY)['uid'] ?? 0));
        self::assertSame(4, (int)($this->resolve(self::EDITOR)['uid'] ?? 0), 'an editor who may use German reads it');
    }

    #[Test]
    public function aFreePreviewOrAnotherSitesScanIsNeverACandidate(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $this->insertPageScan($pool, 5, 2, '55555555-5555-4555-8555-555555555555', 8000);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['is_free_preview' => 1], ['uid' => 5]);
        $this->insertPageScan($pool, 6, 2, '66666666-6666-4666-8666-666666666666', 9000);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['site_identifier' => 'client-b'], ['uid' => 6]);

        self::assertSame(2, (int)($this->resolve(self::EDITOR)['uid'] ?? 0));
    }

    #[Test]
    public function theCandidateWindowIsBounded(): void
    {
        $pool = $this->get(ConnectionPool::class);
        for ($i = 0; $i < 20; $i++) {
            $this->insertPageScan($pool, 100 + $i, 3, sprintf('77777777-7777-4777-8777-%012d', $i), 10000 + $i);
        }

        self::assertSame([], $this->resolve(self::EDITOR), 'beyond the newest 20 page scans nothing is loaded');
        // The window holds the 20 newest page scans: 101–119 and scan 3 are unreadable, scan 2 is the 22nd.
        $pool->getConnectionForTable('tx_a11y_remote_scan')->delete('tx_a11y_remote_scan', ['uid' => 119]);
        self::assertSame([], $this->resolve(self::EDITOR));
        $pool->getConnectionForTable('tx_a11y_remote_scan')->delete('tx_a11y_remote_scan', ['uid' => 118]);
        self::assertSame(2, (int)($this->resolve(self::EDITOR)['uid'] ?? 0), 'within the window the readable scan is found');
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(int $beUserUid): array
    {
        $previous = $GLOBALS['BE_USER'] ?? null;
        $this->setUpBackendUser($beUserUid);
        try {
            $toolbarItem = $this->get(A11yScanToolbarItem::class);
            $toolbarItem->setRequest(
                (new ServerRequest('https://a.test/typo3/module/web/layout'))
                    ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            );

            return (new \ReflectionMethod(A11yScanToolbarItem::class, 'resolveRemoteStatus'))->invoke($toolbarItem, 'client-a');
        } finally {
            $GLOBALS['BE_USER'] = $previous;
        }
    }

    private function insertPageScan(ConnectionPool $pool, int $uid, int $pageUid, string $jobId, int $finishedAt, int $languageUid = 0): void
    {
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => $uid, 'site_identifier' => 'client-a', 'job_id' => $jobId, 'source_type' => 'single_page',
            'scan_scope' => 'page', 'page_uid' => $pageUid, 'language_uid' => $languageUid,
            'start_url' => 'https://a.test/' . ($pageUid === 3 ? 'restricted-page' : 'public'),
            'status' => 'completed', 'finished_at' => $finishedAt, 'persisted_at' => $finishedAt + 1, 'crdate' => $finishedAt,
            'tstamp' => time(), 'pages_scanned' => 1, 'pages_total' => 1, 'issues_total' => 3,
        ]);
    }
}
