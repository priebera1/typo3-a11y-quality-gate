<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\OverviewController;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * `remoteScanUid` is a request parameter, so an explicitly selected scan must be re-validated
 * against the page the Overview currently claims to show.
 *
 * Regression guard: selection used to be accepted on site + status + scope + free/paid alone, so
 * ?id=<page B>&remoteScanUid=<page A's scan> rendered page A's findings under page B.
 */
final class OverviewRemoteScanSelectionTest extends TestCase
{
    private const SITE = 'aqg';
    private const PAGE_A = 733;
    private const PAGE_B = 729;

    #[Test]
    public function pageScopedScanOfAnotherPageIsNotRenderedInTheCurrentPageContext(): void
    {
        $scanOfPageA = $this->pageScan(619, self::PAGE_A, 'https://example.test/page-a');
        $fallback = $this->pageScan(700, self::PAGE_B, 'https://example.test/page-b');

        $resolved = $this->resolve(
            selectedScan: $scanOfPageA,
            fallbackPageScan: $fallback,
            currentPageUid: self::PAGE_B,
            currentPageUrl: 'https://example.test/page-b',
            selectedRemoteScanUid: 619,
        );

        self::assertNotNull($resolved);
        self::assertSame(700, (int)$resolved['uid'], 'Page B must keep its own result, not page A\'s.');
        self::assertSame(self::PAGE_B, (int)$resolved['page_uid']);
    }

    #[Test]
    public function pageScopedScanOfTheCurrentPageIsStillSelectable(): void
    {
        $scanOfPageA = $this->pageScan(619, self::PAGE_A, 'https://example.test/page-a');

        $resolved = $this->resolve(
            selectedScan: $scanOfPageA,
            fallbackPageScan: null,
            currentPageUid: self::PAGE_A,
            currentPageUrl: 'https://example.test/page-a',
            selectedRemoteScanUid: 619,
        );

        self::assertNotNull($resolved);
        self::assertSame(619, (int)$resolved['uid']);
    }

    #[Test]
    public function siteScopedScanRemainsSelectableInAPageContext(): void
    {
        $siteScan = $this->scan(637, 'site', 0, 'https://example.test/');

        $resolved = $this->resolve(
            selectedScan: $siteScan,
            fallbackPageScan: null,
            currentPageUid: self::PAGE_B,
            currentPageUrl: 'https://example.test/page-b',
            selectedRemoteScanUid: 637,
        );

        self::assertNotNull($resolved);
        self::assertSame(637, (int)$resolved['uid']);
    }

    #[Test]
    public function legacyPageScanWithoutPageUidIsBoundByItsScannedUrl(): void
    {
        $legacyMatching = $this->scan(500, 'page', 0, 'https://example.test/page-b/');
        $legacyForeign = $this->scan(501, 'page', 0, 'https://example.test/page-a');

        $matched = $this->resolve(
            selectedScan: $legacyMatching,
            fallbackPageScan: null,
            currentPageUid: self::PAGE_B,
            currentPageUrl: 'https://example.test/page-b',
            selectedRemoteScanUid: 500,
        );
        self::assertNotNull($matched);
        self::assertSame(500, (int)$matched['uid']);

        $rejected = $this->resolve(
            selectedScan: $legacyForeign,
            fallbackPageScan: null,
            currentPageUid: self::PAGE_B,
            currentPageUrl: 'https://example.test/page-b',
            selectedRemoteScanUid: 501,
        );
        self::assertNull($rejected, 'A legacy page scan of another URL must not be shown here.');
    }

    #[Test]
    public function legacyUrlMatchingIsCaseSensitiveInThePath(): void
    {
        // Scheme and host are case-insensitive, the path is not: /Foo and /foo are different pages.
        // Normalising the whole URL to lower case would let an explicit remoteScanUid pointing at
        // /Foo render while /foo is selected.
        $scanOfUpperCasePath = $this->scan(700, 'page', 0, 'https://example.test/Foo');

        $rejected = $this->resolve(
            selectedScan: $scanOfUpperCasePath,
            fallbackPageScan: null,
            currentPageUid: self::PAGE_B,
            currentPageUrl: 'https://example.test/foo',
            selectedRemoteScanUid: 700,
        );
        self::assertNull(
            $rejected,
            'A scan of /Foo must not be rendered while /foo is selected: paths are case-sensitive.'
        );

        // The case-insensitive components must still normalise, or legitimate matches break.
        $sameResourceDifferentCasing = $this->scan(701, 'page', 0, 'HTTPS://EXAMPLE.TEST/foo/');

        $matched = $this->resolve(
            selectedScan: $sameResourceDifferentCasing,
            fallbackPageScan: null,
            currentPageUid: self::PAGE_B,
            currentPageUrl: 'https://example.test/foo',
            selectedRemoteScanUid: 701,
        );
        self::assertNotNull($matched, 'Scheme and host are case-insensitive and must still match.');
        self::assertSame(701, (int)$matched['uid']);
    }

    #[Test]
    public function paidScanIsStillUnreachableFromAFreeContext(): void
    {
        $paidScan = $this->pageScan(619, self::PAGE_A, 'https://example.test/page-a');
        $paidScan['is_free_preview'] = 0;

        $resolved = $this->resolve(
            selectedScan: $paidScan,
            fallbackPageScan: null,
            currentPageUid: self::PAGE_A,
            currentPageUrl: 'https://example.test/page-a',
            selectedRemoteScanUid: 619,
            isFreePreview: true,
        );

        self::assertNull($resolved);
    }

    #[Test]
    public function scanOfAnotherSiteIsStillRejected(): void
    {
        $foreignScan = $this->pageScan(619, self::PAGE_A, 'https://other.test/page-a');
        $foreignScan['site_identifier'] = 'other-site';

        $resolved = $this->resolve(
            selectedScan: $foreignScan,
            fallbackPageScan: null,
            currentPageUid: self::PAGE_A,
            currentPageUrl: 'https://example.test/page-a',
            selectedRemoteScanUid: 619,
        );

        self::assertNull($resolved);
    }

    /**
     * @param array<string, mixed>|null $selectedScan
     * @param array<string, mixed>|null $fallbackPageScan
     * @return array<string, mixed>|null
     */
    private function resolve(
        ?array $selectedScan,
        ?array $fallbackPageScan,
        int $currentPageUid,
        string $currentPageUrl,
        int $selectedRemoteScanUid,
        bool $isFreePreview = false,
    ): ?array {
        $repository = $this->createMock(RemoteScanRepository::class);
        $repository->method('findScanByUid')->willReturn($selectedScan);
        $repository->method('findLastCompletedPageScanByPageOrUrl')->willReturn($fallbackPageScan);
        $repository->method('findLastCompletedSiteScanBySite')->willReturn(null);

        $subject = (new ReflectionClass(OverviewController::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(OverviewController::class, $subject);

        (new ReflectionProperty(OverviewController::class, 'remoteScanRepository'))
            ->setValue($subject, $repository);

        $method = new ReflectionMethod(OverviewController::class, 'resolveOverviewRemoteScan');

        /** @var array<string, mixed>|null $result */
        $result = $method->invoke(
            $subject,
            self::SITE,
            true,
            $currentPageUid,
            0,
            $currentPageUrl,
            $selectedRemoteScanUid,
            $isFreePreview,
        );

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function pageScan(int $uid, int $pageUid, string $startUrl): array
    {
        return $this->scan($uid, 'page', $pageUid, $startUrl);
    }

    /**
     * @return array<string, mixed>
     */
    private function scan(int $uid, string $scope, int $pageUid, string $startUrl): array
    {
        return [
            'uid' => $uid,
            'site_identifier' => self::SITE,
            'status' => 'completed',
            'scan_scope' => $scope,
            'page_uid' => $pageUid,
            'start_url' => $startUrl,
            'is_free_preview' => 0,
            'issues_total' => 24,
        ];
    }
}
