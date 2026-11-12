<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Security;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Service\RemoteResultAuthorizationService;
use Priebera\A11yQualityGate\Service\RemoteResultProvenanceService;
use Priebera\A11yQualityGate\Service\RemoteScreenshotAccessPolicy;
use Priebera\A11yQualityGate\Service\ScanResultSummaryService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Sol post-remediation review R01, R02, R03 and R05 in the backend module, with the reproductions inverted: stored
 * frontend results reach a backend user — remote CSV exports, the scan notification, the toolbar count, page
 * screenshots, the scan read predicate — only as far as that user's page, language, table and record rights reach.
 * Real users with backend sessions, groups, permissions and rows (RemoteResultFixtureTrait).
 */
final class RemoteResultAuthorizationFunctionalTest extends AbstractFunctionalTestCase
{
    use RemoteResultFixtureTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRemoteResultFixture();
    }

    #[Test]
    public function theProvenanceIsResolvedExactlyWhenResultsAreStored(): void
    {
        $this->stamp();
        $rows = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan_page')
            ->select(['uid', 'owner_state', 'owner_page_uid', 'owner_language_uid', 'owner_translation_uid'], 'tx_a11y_remote_scan_page')
            ->fetchAllAssociative();
        $owners = array_column($rows, null, 'uid');
        self::assertSame([1, 1, 0, 0], array_map('intval', [$owners[50]['owner_state'], $owners[50]['owner_page_uid'], $owners[50]['owner_language_uid'], $owners[50]['owner_translation_uid']]));
        self::assertSame([1, 3], array_map('intval', [$owners[51]['owner_state'], $owners[51]['owner_page_uid']]));
        self::assertSame([1, 2, 1, 20], array_map('intval', [$owners[54]['owner_state'], $owners[54]['owner_page_uid'], $owners[54]['owner_language_uid'], $owners[54]['owner_translation_uid']]));
        self::assertSame([1, 3], array_map('intval', [$owners[57]['owner_state'], $owners[57]['owner_page_uid']]), '?id=3 is page 3');
    }

    #[Test]
    public function aSiteBaseWithoutHostOwnsEveryHostButStillOnlyExactSlugs(): void
    {
        $this->get(SiteWriter::class)->write('client-a', [
            'rootPageId' => 1,
            'base' => '/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
            ],
        ]);
        $this->get(SiteFinder::class)->getAllSites(false);
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['url' => 'https://other.test/public'], ['uid' => 52]);
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['url' => 'https://other.test/public/child/extra'], ['uid' => 57]);
        $this->stamp();
        $owners = array_column($pool->getConnectionForTable('tx_a11y_remote_scan_page')->select(['uid', 'owner_state', 'owner_page_uid'], 'tx_a11y_remote_scan_page')->fetchAllAssociative(), null, 'uid');
        self::assertSame(2, (int)$owners[52]['owner_page_uid']);
        self::assertSame(RemoteResultProvenanceService::STATE_UNRESOLVED, (int)$owners[57]['owner_state'], 'a route-enhancer-like path is never attributed to a parent page');
    }

    #[Test]
    public function theRemoteSiteCsvContainsOnlyWhatTheEditorMayRead(): void
    {
        // Sol R05 reproduction inverted: an editor who may edit the root but not read page 3.
        $this->stamp();
        $out = $this->as(self::EDITOR, fn (): array => [
            'rootEditable' => $this->get(ScopeAccessService::class)->canEditPage(1),
            'restrictedReadable' => $this->get(ScopeAccessService::class)->canReadPage(3),
            'csv' => $this->get(RemoteExportBuilder::class)->buildOverviewCsv('client-a', 1),
            'restrictedPageCsv' => $this->get(RemoteExportBuilder::class)->buildPageCsv(51),
            'publicPageCsv' => $this->get(RemoteExportBuilder::class)->buildPageCsv(52),
        ]);
        self::assertTrue($out['rootEditable']);
        self::assertFalse($out['restrictedReadable']);
        foreach (self::NEVER_FOR_EDITOR as $marker) {
            self::assertStringNotContainsString($marker, $out['csv'], $marker);
            self::assertStringNotContainsString($marker, $out['publicPageCsv'], $marker);
        }
        self::assertStringContainsString('PUBLIC_CONTENT', $out['csv']);
        self::assertSame('', $out['restrictedPageCsv'], 'a refused result page answers like a missing one');

        $admin = $this->as(self::ADMIN, fn (): string => $this->get(RemoteExportBuilder::class)->buildOverviewCsv('client-a', 1));
        self::assertStringContainsString('SYNTHETIC_RESTRICTED_MARKER', $admin);
        self::assertStringContainsString('UNMAPPED_TEMPLATE_MARKER', $admin);
    }

    #[Test]
    public function theScanNotificationCountsOnlyWhatTheEditorMayRead(): void
    {
        $this->stamp();
        $scan = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => 1])->fetchAssociative();
        // A real backend session: the notification builds module URLs (route tokens).
        $this->setUpBackendUser(self::EDITOR);
        $editor = $this->get(ScanResultSummaryService::class)->frontendResult($scan);
        self::assertSame(4, $editor['occurrencesTotal']);
        self::assertSame(4, $editor['issueTypesTotal']);
        self::assertFalse($editor['comparison']['available']);
        self::assertSame(3, $editor['pagesExpected'], 'result pages the editor may read: root, public, German');

        $this->setUpBackendUser(self::ADMIN);
        $admin = $this->get(ScanResultSummaryService::class)->frontendResult($scan);
        self::assertSame(15, $admin['occurrencesTotal']);
    }

    #[Test]
    public function theToolbarFrontendCountIsTheEditorsVisibleTotal(): void
    {
        $this->stamp();
        $scan = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => 1])->fetchAssociative();
        $counted = $this->as(self::EDITOR, fn (): array => (new \ReflectionMethod(A11yScanToolbarItem::class, 'withVisibleOccurrences'))->invoke($this->get(A11yScanToolbarItem::class), $scan));
        self::assertSame(4, $counted['issues_total']);
        self::assertSame(0, $counted['issues_new']);
    }

    #[Test]
    public function pageScreenshotsAreForAdministratorsOnly(): void
    {
        $this->stamp();
        self::assertFalse($this->as(self::EDITOR, fn (): bool => $this->get(RemoteScreenshotAccessPolicy::class)->canRead(52)));
        self::assertTrue($this->as(self::ADMIN, fn (): bool => $this->get(RemoteScreenshotAccessPolicy::class)->canRead(52)));
    }

    #[Test]
    public function theSharedPolicyWithholdsSlugReassignedTranslatedUnknownLegacyAndUnreadableRecordResults(): void
    {
        $visibleRules = function (int $beUserUid, int $scanUid): array {
            return $this->as($beUserUid, function () use ($scanUid): array {
                $scan = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => $scanUid])->fetchAssociative();

                return array_column($this->get(RemoteResultAuthorizationService::class)->authorize($scan)->findings, 'ruleId');
            });
        };
        // Legacy rows (no provenance): nothing for an editor, everything for an administrator.
        self::assertSame([], $visibleRules(self::EDITOR, 1));
        self::assertCount(9, $visibleRules(self::ADMIN, 1));

        $this->stamp();
        $pool = $this->get(ConnectionPool::class);
        self::assertEqualsCanonicalizing(['root-rule', 'mixed-rule', 'document-title', 'german-rule'], $visibleRules(self::EDITOR, 1));
        // Slug moved after the scan: the stored owner (page 3) decides.
        $pool->getConnectionForTable('pages')->update('pages', ['slug' => '/restricted-moved'], ['uid' => 3]);
        $pool->getConnectionForTable('pages')->update('pages', ['slug' => '/restricted-page'], ['uid' => 2]);
        self::assertNotContains('restricted-only-rule', $visibleRules(self::EDITOR, 1));
        // Translation record with its own denied permissions.
        $pool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 20]);
        self::assertNotContains('german-rule', $visibleRules(self::EDITOR, 1));
        // Language not allowed for the user.
        self::assertNotContains('german-rule', $visibleRules(self::DEFAULT_LANGUAGE_ONLY, 1));
        // Table not readable: only the page-level finding stays.
        $pool->getConnectionForTable('be_groups')->update('be_groups', ['tables_select' => 'pages', 'tables_modify' => 'pages'], ['uid' => 10]);
        self::assertSame(['document-title'], $visibleRules(self::EDITOR, 1));
    }

    #[Test]
    public function aPageScanOfADeletedOrMovedPageIsNotReadableAndNeverFallsBackToTheSiteRoot(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $readable = fn (int $beUserUid): bool => $this->as($beUserUid, function (): bool {
            $scan = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => 2])->fetchAssociative();

            return $this->get(ScopeAccessService::class)->canReadRemoteScan($scan);
        });
        self::assertTrue($readable(self::EDITOR));
        $pool->getConnectionForTable('pages')->update('pages', ['deleted' => 1], ['uid' => 2]);
        self::assertFalse($readable(self::EDITOR), 'deleted page: no site-root fallback');
        self::assertTrue($readable(self::ADMIN));
        $pool->getConnectionForTable('pages')->update('pages', ['deleted' => 0, 'pid' => 3], ['uid' => 2]);
        self::assertTrue($readable(self::EDITOR), 'moved within the site: its own permissions decide');
        $pool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 0], ['uid' => 2]);
        self::assertFalse($readable(self::EDITOR));
    }

    // --- independent final review follow-ups -------------------------------------------------------------------------

    #[Test]
    public function theScanStatusPollReportsNoUnreadableScanAndNoCrawlerCountersToEditors(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $row = fn (int $uid): array => $pool->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => $uid])->fetchAssociative();
        $visible = fn (int $beUserUid, array $scan): ?array => $this->as($beUserUid, fn (): ?array => (new \ReflectionMethod(\Priebera\A11yQualityGate\Controller\ScanAjaxController::class, 'visibleRemoteStatus'))->invoke($this->get(\Priebera\A11yQualityGate\Controller\ScanAjaxController::class), $scan));

        $site = $visible(self::EDITOR, $row(1));
        self::assertSame([0, 0, 0, true], [$site['issues_total'], $site['pages_total'], $site['pages_scanned'], $site['countsWithheld']]);
        self::assertSame(14, $visible(self::ADMIN, $row(1))['issues_total']);
        $pool->getConnectionForTable('pages')->update('pages', ['deleted' => 1], ['uid' => 2]);
        self::assertNull($visible(self::EDITOR, $row(2)), 'page scan of a deleted page: not reported');
    }

    #[Test]
    public function theScanSummaryCarriesNoCrawlerWideFieldsForEditors(): void
    {
        $this->stamp();
        $scan = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => 1])->fetchAssociative();
        $payload = ['success' => true, 'saved' => true, 'jobId' => self::JOB_SITE, 'topPages' => [['url' => 'https://a.test/restricted-page']], 'failedPages' => [['url' => 'https://a.test/x']], 'priorityFixes' => [['ruleId' => 'restricted-only-rule']], 'issuesTotal' => 14, 'pagesScanned' => 7, 'issuesNew' => 3];
        $this->setUpBackendUser(self::EDITOR);
        $response = (new \ReflectionMethod(\Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController::class, 'summaryResponse'))->invoke($this->get(\Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController::class), $payload, $scan);
        $body = json_decode((string)$response->getBody(), true);
        foreach (['topPages', 'failedPages', 'priorityFixes'] as $field) {
            self::assertArrayNotHasKey($field, $body);
        }
        self::assertStringNotContainsString('restricted-page', (string)$response->getBody());
        self::assertSame(4, $body['issuesTotal']);
        self::assertNull($body['issuesNew']);
        self::assertTrue($body['countsWithheld']);
    }

    #[Test]
    public function verifyFixOnlyResolvesFindingsTheEditorMaySee(): void
    {
        $this->stamp();
        $resolve = fn (int $beUserUid, int $finding): ?array => $this->as($beUserUid, fn (): ?array => $this->get(\Priebera\A11yQualityGate\Service\FixVerificationService::class)->resolveFinding($finding));
        self::assertNull($resolve(self::EDITOR, 81), 'storage-only finding cannot be probed');
        self::assertNull($resolve(self::EDITOR, 98), 'finding of a refused page cannot be probed');
        self::assertSame(1, $resolve(self::EDITOR, 80)['occurrences'] ?? null, 'only visible elements are counted');
        self::assertSame(3, $resolve(self::ADMIN, 80)['occurrences'] ?? null);
    }

    #[Test]
    public function thePageModuleCountsOnlyAResultPageOwnedByThisPageAndOnlyVisibleFindings(): void
    {
        $this->stamp();
        $count = fn (int $beUserUid, int $pageUid, int $resultPageUid): int => $this->as($beUserUid, function () use ($pageUid, $resultPageUid): int {
            $service = $this->get(\Priebera\A11yQualityGate\Service\PageModuleIndicatorService::class);
            (new \ReflectionProperty($service, 'currentPageUidForCount'))->setValue($service, $pageUid);
            $row = $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan_page')->select(['*'], 'tx_a11y_remote_scan_page', ['uid' => $resultPageUid])->fetchAssociative();

            return (new \ReflectionMethod($service, 'visibleRemoteIssueCount'))->invoke($service, $row);
        });
        self::assertSame(2, $count(self::EDITOR, 2, 52), 'mixed-rule and document-title');
        // Slug moved: the earlier owner's results stay with page 3.
        $this->get(ConnectionPool::class)->getConnectionForTable('pages')->update('pages', ['slug' => '/restricted-page'], ['uid' => 2]);
        self::assertSame(0, $count(self::EDITOR, 2, 51));
    }

    #[Test]
    public function onlyALowercaseIdParameterNamesThePageAndLanguageRootsCheckTheirTranslation(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['url' => 'https://a.test/public?ID=3'], ['uid' => 57]);
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->update('tx_a11y_remote_scan_page', ['url' => 'https://a.test/de/'], ['uid' => 50]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 21, 'pid' => 0, 'title' => 'Client A (de)', 'doktype' => 1, 'slug' => '/', 'sys_language_uid' => 1, 'l10n_parent' => 1,
            'perms_everybody' => 0, 'deleted' => 0,
        ]);
        $this->stamp();
        $owners = array_column($pool->getConnectionForTable('tx_a11y_remote_scan_page')->select(['uid', 'owner_page_uid', 'owner_language_uid', 'owner_translation_uid'], 'tx_a11y_remote_scan_page')->fetchAllAssociative(), null, 'uid');
        self::assertSame(2, (int)$owners[57]['owner_page_uid'], '?ID= is not TYPO3\'s page id: the path decides');
        self::assertSame([1, 1, 0], [(int)$owners[50]['owner_page_uid'], (int)$owners[50]['owner_language_uid'], (int)$owners[50]['owner_translation_uid']]);

        $rules = $this->as(self::EDITOR, fn (): array => array_column($this->get(RemoteResultAuthorizationService::class)->authorize(
            $pool->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => 1])->fetchAssociative()
        )->findings, 'ruleId'));
        self::assertNotContains('root-rule', $rules, 'the German root translation record is refused');
    }

    #[Test]
    public function aSiteScanInALanguageTheUserMayNotUseIsNotReadable(): void
    {
        $pool = $this->get(ConnectionPool::class);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['language_uid' => 1], ['uid' => 1]);
        $scan = $pool->getConnectionForTable('tx_a11y_remote_scan')->select(['*'], 'tx_a11y_remote_scan', ['uid' => 1])->fetchAssociative();
        self::assertFalse($this->as(self::DEFAULT_LANGUAGE_ONLY, fn (): bool => $this->get(ScopeAccessService::class)->canReadRemoteScan($scan)));
        self::assertTrue($this->as(self::EDITOR, fn (): bool => $this->get(ScopeAccessService::class)->canReadRemoteScan($scan)));
    }

    private function as(int $beUserUid, \Closure $callback): mixed
    {
        $previous = $GLOBALS['BE_USER'] ?? null;
        $this->setUpBackendUser($beUserUid);
        try {
            return $callback();
        } finally {
            $GLOBALS['BE_USER'] = $previous;
        }
    }
}
