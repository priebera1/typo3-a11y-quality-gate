<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Service\ScannerAccessTokenService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * A frontend request carrying a valid Scanner Token gets AQG's record markers around every content element — the
 * markers the crawler maps frontend findings to TYPO3 records with. Runs through the real middleware stack and the
 * `[aqgDebugMarkers()]` TypoScript condition, so the same test proves TYPO3 13 and 14 behave alike.
 *
 * Regression guard: a default token that "Regenerate" had also copied into other sites' rulesets was rejected for a
 * site without its own token. Scans of that site sent the token, received no markers, and every finding showed
 * "No mapped TYPO3 record".
 */
final class ScannerTokenMarkerFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 0, 'Client', '/', 1], [2, 1, 'Page', '/page', 0], [100, 0, 'Other', '/', 1]] as [$uid, $pid, $title, $slug, $isRoot]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $isRoot,
                'deleted' => 0, 'hidden' => 0,
            ]);
        }
        foreach ([[11, 'First element', 1], [12, 'Second element', 2]] as [$uid, $header, $sorting]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => 2, 'CType' => 'header', 'header' => $header, 'colPos' => 0, 'sorting' => $sorting,
                'sys_language_uid' => 0, 'deleted' => 0, 'hidden' => 0,
            ]);
        }

        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->createNewBasicSite('client', 1, 'https://client.test/');
        $siteWriter->createNewBasicSite('other', 100, 'https://other.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpFrontendRootPage(1, ['EXT:a11y_quality_gate/Tests/Fixtures/Frontend/MarkerPage.typoscript']);
    }

    #[Test]
    public function aValidSiteTokenMarksEveryContentElement(): void
    {
        $token = $this->get(ScannerAccessTokenService::class)->generateAndSaveTokenForSiteOrDefault('client');

        self::assertSame(['11', '12'], $this->markedContentUids($this->render($token)));
    }

    #[Test]
    public function withoutATokenThePageHasNoMarkers(): void
    {
        $this->get(ScannerAccessTokenService::class)->generateAndSaveTokenForSiteOrDefault('client');

        $html = $this->render(null);

        self::assertSame([], $this->markedContentUids($html));
        self::assertStringNotContainsString('data-aqg-debug', $html);
    }

    #[Test]
    public function aWrongTokenGetsNoMarkers(): void
    {
        $this->get(ScannerAccessTokenService::class)->generateAndSaveTokenForSiteOrDefault('client');

        self::assertSame([], $this->markedContentUids($this->render(str_repeat('0', 64))));
    }

    #[Test]
    public function theDefaultTokenWorksForASiteWithoutItsOwnAlthoughAnotherSiteHoldsACopy(): void
    {
        $token = $this->get(ScannerAccessTokenService::class)->generateAndSaveTokenForSiteOrDefault('');
        // What "Regenerate" with the default selection does for every configured site at that moment.
        $this->get(RulesetRepository::class)->saveScannerTokenForSiteOrDefault('other', $token);

        self::assertSame(['11', '12'], $this->markedContentUids($this->render($token)));
    }

    #[Test]
    public function anotherSitesOwnTokenDoesNotUnlockThisSite(): void
    {
        $otherToken = $this->get(ScannerAccessTokenService::class)->generateAndSaveTokenForSiteOrDefault('other');

        self::assertSame([], $this->markedContentUids($this->render($otherToken)));
    }

    private function render(?string $token): string
    {
        $request = new InternalRequest('https://client.test/page');
        if ($token !== null) {
            $request = $request->withHeader('X-AQG-Scanner-Token', $token);
        }

        return (string)$this->executeFrontendSubRequest($request)->getBody();
    }

    /**
     * @return list<string>
     */
    private function markedContentUids(string $html): array
    {
        preg_match_all('/<div class="aqg-marker"[^>]*\bdata-aqg-content-uid="(\d+)"/', $html, $matches);

        return $matches[1];
    }
}
