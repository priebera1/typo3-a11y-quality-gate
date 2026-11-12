<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\FixVerificationRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Service\FixVerificationService;
use Priebera\A11yQualityGate\Service\RemotePageScanTargetResolver;
use Priebera\A11yQualityGate\Service\RemoteScanPairingService;
use Priebera\A11yQualityGate\Service\ScanResultSummaryService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Atlas D9: a paid single-page scan of a page visitors cannot open is a preview scan. Its results carry that label and
 * stay apart from the page's public history: no pairing, comparison, Verify fix or evidence across the two kinds, and
 * no site-level coverage (latest site result, affected pages, stored site history) from a preview scan.
 */
final class PagePreviewSeparationFunctionalTest extends AbstractFunctionalTestCase
{
    private const PUBLIC_OLD_JOB = '11111111-1111-4111-8111-111111111111';
    private const PREVIEW_OLD_JOB = '22222222-2222-4222-8222-222222222222';
    private const PREVIEW_JOB = '33333333-3333-4333-8333-333333333333';
    private const PUBLIC_JOB = '44444444-4444-4444-8444-444444444444';

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pages = $pool->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'is_siteroot' => 1, 'perms_everybody' => 31]);
        $pages->insert('pages', ['uid' => 10, 'pid' => 1, 'title' => 'Visible', 'doktype' => 1, 'perms_everybody' => 31]);
        $pages->insert('pages', ['uid' => 11, 'pid' => 1, 'title' => 'Draft', 'doktype' => 1, 'hidden' => 1, 'perms_everybody' => 31]);
        $pages->insert('pages', ['uid' => 12, 'pid' => 1, 'title' => 'Members', 'doktype' => 1, 'fe_group' => '3', 'perms_everybody' => 31]);
        $pages->insert('pages', ['uid' => 13, 'pid' => 1, 'title' => 'Later', 'doktype' => 1, 'starttime' => time() + 86400, 'perms_everybody' => 31]);
        // A public page whose German translation is still hidden.
        $pages->insert('pages', ['uid' => 14, 'pid' => 1, 'title' => 'Public EN', 'doktype' => 1, 'perms_everybody' => 31]);
        $pages->insert('pages', ['uid' => 15, 'pid' => 1, 'title' => 'Hidden DE', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 14, 'hidden' => 1, 'perms_everybody' => 31]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.org/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function aPageVisitorsCannotOpenIsAPreviewTargetAndAVisiblePageIsNot(): void
    {
        $resolver = $this->get(RemotePageScanTargetResolver::class);

        self::assertFalse($resolver->isPreviewTarget(10, 0), 'A visible page is scanned as a public page.');
        self::assertTrue($resolver->isPreviewTarget(11, 0), 'A hidden page is a preview scan.');
        self::assertTrue($resolver->isPreviewTarget(12, 0), 'A page limited to frontend user groups is a preview scan.');
        self::assertTrue($resolver->isPreviewTarget(13, 0), 'A page before its start time is a preview scan.');
        self::assertFalse($resolver->isPreviewTarget(14, 0));
        self::assertTrue($resolver->isPreviewTarget(14, 1), 'A hidden translation is a preview scan in that language.');
        self::assertTrue($resolver->isPublicNow(10, 0));
        self::assertFalse($resolver->isPublicNow(11, 0));
    }

    #[Test]
    public function theFlagIsStoredAtSubmitAndKeptBySyncsLegacyRowsArePublic(): void
    {
        $repository = $this->get(RemoteScanRepository::class);
        $uid = $repository->markSubmitted(
            siteIdentifier: 'main',
            jobId: self::PREVIEW_JOB,
            sourceType: RemoteScanSourceType::SinglePage,
            startUrl: 'https://example.org/draft',
            sitemapUrl: null,
            status: 'queued',
            scanScope: 'page',
            pageUid: 11,
            languageUid: 0,
            isPagePreview: true,
        );
        // A status sync / persist upserts the row without the flag.
        $repository->upsertScan('main', self::PREVIEW_JOB, RemoteScanSourceType::SinglePage, 'https://example.org/draft', null, 'completed', 1, 0, 2, 0, 0, 100, 200, 1, 'page', 11, 200, 200, '', 0);

        self::assertSame(1, (int)$repository->findScanByUid($uid)['is_page_preview']);

        $legacy = $repository->markSubmitted('main', self::PUBLIC_JOB, RemoteScanSourceType::SinglePage, 'https://example.org/draft', null, 'queued', 'page', 11, 0);
        self::assertSame(0, (int)$repository->findScanByUid($legacy)['is_page_preview'], 'Without a decision a scan is public.');
    }

    #[Test]
    public function previewAndPublicScansAreNeverPairedOrCompared(): void
    {
        $this->setUpBackendUser(1);
        $this->pageScans();
        $repository = $this->get(RemoteScanRepository::class);
        $pairing = $this->get(RemoteScanPairingService::class);

        $preview = $repository->findScanByJobId(self::PREVIEW_JOB);
        $public = $repository->findScanByJobId(self::PUBLIC_JOB);

        self::assertFalse($pairing->isCompatible($preview, $public));
        self::assertFalse($pairing->isCompatible($public, $preview));
        self::assertNull($pairing->resolveComparePair('main', self::PREVIEW_JOB, self::PUBLIC_JOB));
        self::assertNotNull($pairing->resolveComparePair('main', self::PREVIEW_OLD_JOB, self::PREVIEW_JOB));

        // "Previous compatible scan" skips the newer scan of the other kind.
        self::assertSame(self::PREVIEW_OLD_JOB, $pairing->findPreviousCompatible($preview)['job_id'] ?? null);
        self::assertSame(self::PUBLIC_OLD_JOB, $pairing->findPreviousCompatible($public)['job_id'] ?? null);

        // The scan notification compares with the previous preview scan only, and says it is a preview scan.
        $summary = $this->get(ScanResultSummaryService::class)->frontendResult($preview);
        self::assertTrue($summary['pagePreview']);
        self::assertSame(['available' => true, 'new' => 1, 'resolved' => 0], $summary['comparison']);
        self::assertFalse($this->get(ScanResultSummaryService::class)->frontendResult($public)['pagePreview']);
    }

    #[Test]
    public function siteLevelViewsAndTheStoredHistoryCountPublicScansOnly(): void
    {
        $this->pageScans();
        $repository = $this->get(RemoteScanRepository::class);

        // Latest site result / toolbar fallback: the newer preview scan is not the site's latest page result.
        self::assertSame(self::PUBLIC_JOB, $repository->findLastCompletedPageScanBySite('main', 0, false)['job_id'] ?? null);
        self::assertSame(self::PUBLIC_JOB, $repository->findLastCompletedScanBySite('main', 0)['job_id'] ?? null);

        // Affected pages of the site: one row for the URL, from the public scan.
        $rows = $repository->findCompletedPageScanPagesForSitePaginated('main', 10, 0, 0);
        self::assertCount(1, $rows);
        self::assertSame(4, (int)$rows[0]['remote_scan_uid']);
        self::assertSame(1, $repository->countCompletedPageScanPagesForSite('main', 0));

        // Stored history of the site never lists preview scans.
        $history = $repository->findCompletedHistoryScansBySiteAndSourceTypes('main', ['single_page'], 10, 0);
        self::assertSame([self::PUBLIC_JOB, self::PUBLIC_OLD_JOB], array_column($history, 'job_id'));
        self::assertSame(2, $repository->countCompletedHistoryScansBySiteAndSourceTypes('main', ['single_page'], 0));

        // The page's own history tells the two kinds apart.
        self::assertSame(
            [self::PREVIEW_OLD_JOB => true, self::PREVIEW_JOB => true],
            $repository->findPagePreviewJobIds('main', [self::PUBLIC_OLD_JOB, self::PREVIEW_OLD_JOB, self::PREVIEW_JOB, self::PUBLIC_JOB, 'unknown'])
        );
        self::assertSame([], $repository->findPagePreviewJobIds('other-site', [self::PREVIEW_JOB]));

        // The page itself still finds its newest result, whichever kind it is (the detail view labels it).
        self::assertSame(self::PUBLIC_JOB, $repository->findLastCompletedPageScanByPage('main', 11, 0)['job_id'] ?? null);
    }

    #[Test]
    public function verifyFixOfAPreviewFindingAfterPublishingIsNotVerified(): void
    {
        $this->setUpBackendUser(1);
        $this->pageScans();
        $verifications = $this->get(FixVerificationRepository::class);
        $service = $this->get(FixVerificationService::class);

        // Found in the preview scan; the verification scan ran after the page was published (a public scan) and no
        // longer reports the rule — still no "Resolved".
        $published = $verifications->insert([
            'site_identifier' => 'main', 'rule_id' => 'label', 'url' => 'https://example.org/draft', 'language_uid' => 0,
            'baseline_scan' => 3, 'verification_job_id' => self::PUBLIC_JOB, 'outcome' => 'pending',
        ]);
        $result = $service->evaluate($verifications->findByUid($published));
        self::assertSame('not_verified', $result['outcome']);
        self::assertSame('preview_now_public', $result['outcome_reason']);
        self::assertStringContainsString('Preview and public scans are not compared', $service->presentOutcome($result, static fn (int $uid): string => '')['message']);

        $hiddenAgain = $verifications->insert([
            'site_identifier' => 'main', 'rule_id' => 'image-alt', 'url' => 'https://example.org/draft', 'language_uid' => 0,
            'baseline_scan' => 1, 'verification_job_id' => self::PREVIEW_JOB, 'outcome' => 'pending',
        ]);
        self::assertSame('preview_not_public', $service->evaluate($verifications->findByUid($hiddenAgain))['outcome_reason']);

        // Preview against preview is verified as usual.
        $sameKind = $verifications->insert([
            'site_identifier' => 'main', 'rule_id' => 'image-alt', 'url' => 'https://example.org/draft', 'language_uid' => 0,
            'baseline_scan' => 2, 'verification_job_id' => self::PREVIEW_JOB, 'outcome' => 'pending',
        ]);
        self::assertSame('still_present', $service->evaluate($verifications->findByUid($sameKind))['outcome']);
    }

    #[Test]
    public function aPageExportOfAPreviewScanSaysSoAndAPublicOneIsUnchanged(): void
    {
        $this->setUpBackendUser(1);
        $this->pageScans();
        $repository = $this->get(RemoteScanRepository::class);
        $builder = $this->get(RemoteExportBuilder::class);

        $previewCsv = $builder->buildPageCsv((int)$repository->findAllPagesForScan(3)[0]['uid']);
        $publicCsv = $builder->buildPageCsv((int)$repository->findAllPagesForScan(4)[0]['uid']);

        $previewLines = array_values(array_filter(explode("\n", $previewCsv)));
        self::assertStringEndsWith(';"Scan kind"', $previewLines[0]);
        self::assertStringEndsWith(';"Preview scan · page not public"', $previewLines[1]);
        self::assertStringNotContainsString('Scan kind', $publicCsv);
        self::assertStringNotContainsString('Preview scan', $publicCsv);
    }

    #[Test]
    public function thePageModuleRowsReadWhetherAPageResultIsAPreviewScan(): void
    {
        $this->pageScans();
        $repository = $this->get(RemoteScanRepository::class);

        // The newest page result of page 11 is the public scan; the URL lookup carries the flag of its scan too.
        self::assertSame(0, (int)$repository->findLatestPageForCompletedPageScan('main', 11, 0, 'https://example.org/draft', false)['remote_scan_is_page_preview']);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')
            ->update('tx_a11y_remote_scan', ['is_page_preview' => 1], ['uid' => 4]);
        self::assertSame(1, (int)$repository->findLatestPageForCompletedPageScan('main', 11, 0, 'https://example.org/draft', false)['remote_scan_is_page_preview']);
        self::assertSame(1, (int)$repository->findLatestPageByUrl('https://example.org/draft', 'main', false)['remote_scan_is_page_preview']);
    }

    /**
     * Page 11 (/draft), oldest first: public (1000), preview (2000), preview (3000), public after publishing (4000).
     */
    private function pageScans(): void
    {
        $this->pageScan(1, self::PUBLIC_OLD_JOB, 1000, false, ['image-alt' => 1]);
        $this->pageScan(2, self::PREVIEW_OLD_JOB, 2000, true, ['image-alt' => 2]);
        $this->pageScan(3, self::PREVIEW_JOB, 3000, true, ['image-alt' => 2, 'label' => 1]);
        $this->pageScan(4, self::PUBLIC_JOB, 4000, false, ['image-alt' => 1]);
    }

    /**
     * @param array<string, int> $rules
     */
    private function pageScan(int $uid, string $jobId, int $finishedAt, bool $isPagePreview, array $rules): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => $uid, 'site_identifier' => 'main', 'job_id' => $jobId, 'source_type' => 'single_page', 'scan_scope' => 'page',
            'page_uid' => 11, 'language_uid' => 0, 'start_url' => 'https://example.org/draft', 'status' => 'completed',
            'finished_at' => $finishedAt, 'persisted_at' => $finishedAt, 'pages_scanned' => 1, 'pages_total' => 1,
            'is_page_preview' => $isPagePreview ? 1 : 0,
        ]);
        $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', [
            'remote_scan' => $uid, 'source_type' => 'single_page', 'url' => 'https://example.org/draft', 'is_failed' => 0,
            'http_status' => 200, 'issues_count' => count($rules),
        ]);
        $pageUid = (int)$pool->getConnectionForTable('tx_a11y_remote_scan_page')->lastInsertId();
        foreach ($rules as $ruleId => $nodes) {
            $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', [
                'remote_scan' => $uid, 'remote_scan_page' => $pageUid, 'rule_id' => $ruleId, 'impact' => 'critical',
                'nodes_count' => $nodes, 'status' => 'open', 'fingerprint' => sha1('https://example.org/draft|' . $ruleId),
            ]);
        }
    }
}
