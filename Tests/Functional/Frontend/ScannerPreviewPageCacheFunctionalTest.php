<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Frontend;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\ScannerAccessTokenService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;

/**
 * Fable final audit E-2: a request with a valid Scanner Token renders hidden content without a backend user, so TYPO3
 * never treats it as a preview and keeps the page cache on. Its identifier differs from the public page's only by
 * AQG's `[aqgDebugMarkers()]` condition, so the scanner's render — hidden content included — was stored in the shared
 * page cache, and every later scan was served that stored render instead of the current content. Runs through the real
 * TYPO3 13/14 frontend stack and page cache (database backend).
 */
final class ScannerPreviewPageCacheFunctionalTest extends AbstractFunctionalTestCase
{
    /**
     * The testing framework sets the page cache to NullBackend; this test needs the real one TYPO3 ships with (the
     * database backend, its tables created with the test schema).
     */
    protected array $configurationToUseInTestInstance = [
        'SYS' => ['caching' => ['cacheConfigurations' => ['pages' => [
            'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        ]]]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 0, 'Client', '/', 1], [2, 1, 'Page', '/page', 0]] as [$uid, $pid, $title, $slug, $isRoot]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $isRoot,
                'deleted' => 0, 'hidden' => 0,
            ]);
        }
        foreach ([[11, 'Public element', 1, 0], [12, 'Hidden draft element', 2, 1]] as [$uid, $header, $sorting, $hidden]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => 2, 'CType' => 'header', 'header' => $header, 'colPos' => 0, 'sorting' => $sorting,
                'sys_language_uid' => 0, 'deleted' => 0, 'hidden' => $hidden,
            ]);
        }

        $this->get(SiteWriter::class)->createNewBasicSite('client', 1, 'https://client.test/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpFrontendRootPage(1, ['EXT:a11y_quality_gate/Tests/Fixtures/Frontend/MarkerPage.typoscript']);
    }

    #[Test]
    public function aScannerRenderWithHiddenContentIsNeverWrittenToThePageCache(): void
    {
        $token = $this->get(ScannerAccessTokenService::class)->generateAndSaveTokenForSiteOrDefault('client');
        self::assertStringNotContainsString('Hidden draft element', $this->render(null));
        $publicEntries = $this->cachedPages();
        self::assertGreaterThan(0, $publicEntries, 'precondition: the page cache is in use');

        self::assertStringContainsString('Hidden draft element', $this->render($token), 'precondition: the scanner sees hidden content');
        self::assertSame($publicEntries, $this->cachedPages(), 'the render with hidden content was stored in the page cache');
        self::assertStringNotContainsString('Hidden draft element', $this->render(null));
    }

    #[Test]
    public function everyScanSeesTheCurrentContentNeverACachedRender(): void
    {
        $token = $this->get(ScannerAccessTokenService::class)->generateAndSaveTokenForSiteOrDefault('client');
        self::assertStringContainsString('Hidden draft element', $this->render($token));

        // An editor fixes the element; TYPO3's cache is not cleared (no DataHandler) — as between two scheduled scans.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')
            ->update('tt_content', ['header' => 'Corrected draft element'], ['uid' => 12]);

        $rescanned = $this->render($token);
        self::assertStringContainsString('Corrected draft element', $rescanned, 'the second scan was served the first scan\'s cached render');
    }

    private function cachedPages(): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('cache_pages')->count('*', 'cache_pages', []);
    }

    private function render(?string $token): string
    {
        $request = new InternalRequest('https://client.test/page');
        if ($token !== null) {
            $request = $request->withHeader('X-AQG-Scanner-Token', $token);
        }

        return (string)$this->executeFrontendSubRequest($request)->getBody();
    }
}
