<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService;
use Priebera\A11yQualityGate\Service\ScannerAccessTokenService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * "Is a Scanner Token configured for this site?" has one answer: the token frontend scans of the site send.
 *
 * Regression guard: Settings generates the token for the selected site, but the Overview only looked at the default
 * ruleset, so "Hidden pages will not be scanned — No Scanner Token is configured for this site" stayed after saving.
 */
final class ScannerTokenStateFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 'client'], [100, 'other']] as [$uid, $title]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => 0, 'title' => $title, 'doktype' => 1, 'is_siteroot' => 1, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->createNewBasicSite('client', 1, 'https://client.test/');
        $siteWriter->createNewBasicSite('other', 100, 'https://other.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    #[Test]
    public function withoutAnyTokenNoSiteIsConfigured(): void
    {
        self::assertFalse($this->settings()->hasScannerTokenForSite('client'));
        self::assertSame('', $this->settings()->buildForSite('client')['scannerPreviewToken']);
    }

    #[Test]
    public function aTokenGeneratedForTheSiteCountsAndIsTheOneScansSend(): void
    {
        $token = $this->tokens()->generateAndSaveTokenForSiteOrDefault('client');

        self::assertTrue($this->settings()->hasScannerTokenForSite('client'));
        self::assertSame($token, $this->settings()->buildForSite('client')['scannerPreviewToken']);
        self::assertFalse($this->settings()->hasScannerTokenForSite('other'), 'Another site has no token of its own and no default.');
    }

    #[Test]
    public function theDefaultTokenCountsForSitesWithoutTheirOwn(): void
    {
        $token = $this->tokens()->generateAndSaveTokenForSiteOrDefault('');

        self::assertTrue($this->settings()->hasScannerTokenForSite('other'));
        self::assertSame($token, $this->settings()->buildForSite('other')['scannerPreviewToken']);
    }

    #[Test]
    public function aRegeneratedTokenReplacesThePreviousOne(): void
    {
        $first = $this->tokens()->generateAndSaveTokenForSiteOrDefault('client');
        $second = $this->tokens()->generateAndSaveTokenForSiteOrDefault('client');

        self::assertNotSame($first, $second);
        self::assertSame($second, $this->settings()->buildForSite('client')['scannerPreviewToken']);
        self::assertTrue($this->settings()->hasScannerTokenForSite('client'));
    }

    #[Test]
    public function aSoftDeletedSiteRulesetDoesNotHideANewToken(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_ruleset')->insert('tx_a11y_ruleset', [
            'pid' => 0, 'title' => 'old', 'site_identifier' => 'client', 'scanner_token' => '', 'deleted' => 1,
            'excluded_patterns' => '[]', 'cookie_accept_selectors' => '[]', 'crawl_priority_urls' => '[]',
        ]);

        $token = $this->tokens()->generateAndSaveTokenForSiteOrDefault('client');

        self::assertTrue($this->settings()->hasScannerTokenForSite('client'));
        self::assertSame($token, $this->settings()->buildForSite('client')['scannerPreviewToken']);
    }

    private function tokens(): ScannerAccessTokenService
    {
        return $this->get(ScannerAccessTokenService::class);
    }

    private function settings(): RemoteScanAccessSettingsService
    {
        return $this->get(RemoteScanAccessSettingsService::class);
    }
}
