<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class RemoteScanFreePreviewRepositoryTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function freePreviewMarkerSurvivesResultPersistenceUpsert(): void
    {
        $repository = new RemoteScanRepository(GeneralUtility::makeInstance(ConnectionPool::class));
        $repository->markSubmitted(
            siteIdentifier: 'main',
            jobId: 'free-job-1',
            sourceType: RemoteScanSourceType::SinglePage,
            startUrl: 'https://example.test/about',
            sitemapUrl: null,
            status: 'queued',
            scanScope: 'page',
            pageUid: 42,
            isFreePreview: true,
        );

        $repository->upsertScan(
            siteIdentifier: 'main',
            jobId: 'free-job-1',
            sourceType: RemoteScanSourceType::SinglePage,
            startUrl: 'https://example.test/about',
            sitemapUrl: null,
            status: 'completed',
            pagesScanned: 1,
            pagesFailed: 0,
            issuesTotal: 2,
            issuesNew: 2,
            issuesResolved: 0,
            startedAt: time() - 10,
            finishedAt: time(),
            pagesTotal: 1,
            scanScope: 'page',
            pageUid: 42,
            persistedAt: time(),
        );

        $row = $repository->findScanByJobId('free-job-1');
        self::assertIsArray($row);
        self::assertSame(1, (int)$row['is_free_preview']);
        self::assertSame('completed', $row['status']);
    }

    #[Test]
    public function latestFreePreviewResultIsScopedToItsSelectedPage(): void
    {
        $repository = new RemoteScanRepository(GeneralUtility::makeInstance(ConnectionPool::class));

        $repository->markSubmitted(
            siteIdentifier: 'main',
            jobId: 'pro-job-1',
            sourceType: RemoteScanSourceType::Crawl,
            startUrl: 'https://example.test/',
            sitemapUrl: null,
            status: 'queued',
            isFreePreview: false,
        );
        $repository->upsertScan(
            siteIdentifier: 'main',
            jobId: 'pro-job-1',
            sourceType: RemoteScanSourceType::Crawl,
            startUrl: 'https://example.test/',
            sitemapUrl: null,
            status: 'completed',
            pagesScanned: 120,
            pagesFailed: 0,
            issuesTotal: 30,
            issuesNew: 30,
            issuesResolved: 0,
            startedAt: time() - 120,
            finishedAt: time() - 100,
            pagesTotal: 120,
            persistedAt: time() - 100,
        );

        // The Free result is newer, but it belongs only to its selected page.
        $repository->markSubmitted(
            siteIdentifier: 'main',
            jobId: 'free-job-2',
            sourceType: RemoteScanSourceType::SinglePage,
            startUrl: 'https://example.test/about',
            sitemapUrl: null,
            status: 'queued',
            scanScope: 'page',
            pageUid: 42,
            isFreePreview: true,
        );
        $repository->upsertScan(
            siteIdentifier: 'main',
            jobId: 'free-job-2',
            sourceType: RemoteScanSourceType::SinglePage,
            startUrl: 'https://example.test/about',
            sitemapUrl: null,
            status: 'completed',
            pagesScanned: 1,
            pagesFailed: 0,
            issuesTotal: 2,
            issuesNew: 2,
            issuesResolved: 0,
            startedAt: time() - 10,
            finishedAt: time(),
            pagesTotal: 1,
            scanScope: 'page',
            pageUid: 42,
            persistedAt: time(),
        );

        $paidViewerScan = $repository->findLastCompletedSiteScanBySite('main', -1, false);
        self::assertIsArray($paidViewerScan);
        self::assertSame('pro-job-1', $paidViewerScan['job_id']);

        self::assertNull($repository->findLastCompletedSiteScanBySite('main', -1, true));

        $freeViewerScan = $repository->findLastCompletedPageScanByPageOrUrl(
            'main',
            42,
            -1,
            'https://example.test/about',
            true,
        );
        self::assertIsArray($freeViewerScan);
        self::assertSame('free-job-2', $freeViewerScan['job_id']);
        self::assertSame(42, (int)$freeViewerScan['page_uid']);

        $unfilteredScan = $repository->findLastCompletedSiteScanBySite('main');
        self::assertIsArray($unfilteredScan);
        self::assertSame('pro-job-1', $unfilteredScan['job_id']);
    }
}
