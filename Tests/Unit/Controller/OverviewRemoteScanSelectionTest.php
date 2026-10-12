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
    private const ROOT = 728;

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
     * Regression (1.9.9, TYPO3 14): "Scan this page" on the site root stored a completed page scan, but the root view
     * only looked for site scans, so the result never appeared ("No frontend scan has been run yet").
     */
    #[Test]
    public function atTheSiteRootANewerScanOfTheRootPageIsShown(): void
    {
        $siteScan = $this->scan(527, 'site', 0, 'https://example.test/') + ['finished_at' => 1000];
        $rootPageScan = $this->pageScan(528, self::ROOT, 'https://example.test/') + ['finished_at' => 2000];

        $resolved = $this->resolveAtRoot($siteScan, $rootPageScan);

        self::assertSame(528, (int)($resolved['uid'] ?? 0));
    }

    #[Test]
    public function atTheSiteRootANewerSiteScanStaysShown(): void
    {
        $siteScan = $this->scan(529, 'site', 0, 'https://example.test/') + ['finished_at' => 3000];
        $rootPageScan = $this->pageScan(528, self::ROOT, 'https://example.test/') + ['finished_at' => 2000];

        $resolved = $this->resolveAtRoot($siteScan, $rootPageScan);

        self::assertSame(529, (int)($resolved['uid'] ?? 0));
    }

    #[Test]
    public function atTheSiteRootWithoutSiteScanTheRootPageScanIsShown(): void
    {
        $rootPageScan = $this->pageScan(526, self::ROOT, 'https://example.test/') + ['finished_at' => 2000];

        $resolved = $this->resolveAtRoot(null, $rootPageScan);

        self::assertSame(526, (int)($resolved['uid'] ?? 0));
    }

    #[Test]
    public function aSelectedScanOfTheRootPageIsAcceptedAtTheSiteRoot(): void
    {
        $rootPageScan = $this->pageScan(525, self::ROOT, 'https://example.test/');
        $otherPageScan = $this->pageScan(523, self::PAGE_B, 'https://example.test/page-b');

        self::assertSame(525, (int)($this->resolveAtRoot(null, null, $rootPageScan, 525)['uid'] ?? 0));
        // A page scan of another page still never shows under the root.
        self::assertNull($this->resolveAtRoot(null, null, $otherPageScan, 523));
    }

    /**
     * V-05 (2026-10 Sol verification): the notification of site scan A links to A. After site scan B finished, the
     * action must still open A, not the newest result.
     */
    #[Test]
    public function aNotifiedSiteScanStaysShownAfterANewerSiteScanFinished(): void
    {
        $scanA = $this->scan(600, 'site', self::ROOT, 'https://example.test/') + ['finished_at' => 1000, 'language_uid' => 0];
        $scanB = $this->scan(601, 'site', self::ROOT, 'https://example.test/') + ['finished_at' => 2000, 'language_uid' => 0];

        self::assertSame(600, (int)($this->resolveAtRoot($scanB, null, $scanA, 600)['uid'] ?? 0));
        self::assertSame(601, (int)($this->resolveAtRoot($scanB, null, null, 0)['uid'] ?? 0), 'without a selection: the newest');
    }

    #[Test]
    public function aSelectedScanOfAnotherLanguageIsNotShownUnderThisLanguage(): void
    {
        $germanScan = $this->scan(602, 'site', self::ROOT, 'https://example.test/de/') + ['language_uid' => 1];
        $englishScan = $this->scan(603, 'site', self::ROOT, 'https://example.test/') + ['language_uid' => 0];
        $allLanguages = $this->scan(604, 'site', self::ROOT, 'https://example.test/') + ['language_uid' => -1];

        self::assertSame(603, (int)($this->resolveAtRoot($englishScan, null, $germanScan, 602)['uid'] ?? 0));
        self::assertSame(602, (int)($this->resolveAtRoot($englishScan, null, $germanScan, 602, 1)['uid'] ?? 0));
        self::assertSame(604, (int)($this->resolveAtRoot($englishScan, null, $allLanguages, 604)['uid'] ?? 0));
    }

    #[Test]
    public function aNotifiedScanThatIsNotCompletedOrOfAnotherSiteFallsBackToTheCurrentResult(): void
    {
        $current = $this->scan(605, 'site', self::ROOT, 'https://example.test/') + ['language_uid' => 0];
        $running = ['status' => 'running'] + $this->scan(606, 'site', self::ROOT, 'https://example.test/');
        $foreign = ['site_identifier' => 'other-site'] + $this->scan(607, 'site', self::ROOT, 'https://other.test/');

        self::assertSame(605, (int)($this->resolveAtRoot($current, null, $running, 606)['uid'] ?? 0));
        self::assertSame(605, (int)($this->resolveAtRoot($current, null, $foreign, 607)['uid'] ?? 0));
    }

    /**
     * Regression (1.9.9, TYPO3 14): a frontend scan that finished at the AQG service while no browser watched it is
     * read back and stored by resolveOverviewActiveRemoteScan(). The Overview chose its displayed result before that,
     * so the page that stored the new scan still showed the previous one.
     */
    #[Test]
    public function theActiveScanIsReadBackBeforeTheDisplayedResultIsChosen(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../../Classes/Controller/OverviewController.php');
        $show = substr($source, (int)strpos($source, 'public function indexAction('));

        $activePosition = strpos($show, '$this->resolveOverviewActiveRemoteScan(');
        $displayedPosition = strpos($show, '$this->resolveOverviewRemoteScan(');
        self::assertIsInt($activePosition);
        self::assertIsInt($displayedPosition);
        self::assertLessThan($displayedPosition, $activePosition);
    }

    /**
     * Resolves in the site-root context: not a page context, the current page is the site root.
     *
     * @param array<string, mixed>|null $siteScan
     * @param array<string, mixed>|null $rootPageScan
     * @param array<string, mixed>|null $selectedScan
     * @return array<string, mixed>|null
     */
    private function resolveAtRoot(?array $siteScan, ?array $rootPageScan, ?array $selectedScan = null, int $selectedRemoteScanUid = 0, int $languageUid = 0): ?array
    {
        $repository = $this->createMock(RemoteScanRepository::class);
        $repository->method('findScanByUid')->willReturn($selectedScan);
        $repository->method('findLastCompletedPageScanByPageOrUrl')->willReturn($rootPageScan);
        $repository->method('findLastCompletedSiteScanBySite')->willReturn($siteScan);

        $subject = (new ReflectionClass(OverviewController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(OverviewController::class, 'remoteScanRepository'))->setValue($subject, $repository);

        /** @var array<string, mixed>|null $result */
        $result = (new ReflectionMethod(OverviewController::class, 'resolveOverviewRemoteScan'))->invoke(
            $subject,
            self::SITE,
            false,
            self::ROOT,
            $languageUid,
            'https://example.test/',
            $selectedRemoteScanUid,
            false,
            true,
        );

        return $result;
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
