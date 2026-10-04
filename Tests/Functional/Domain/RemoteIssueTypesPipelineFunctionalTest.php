<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * "Issue types" in the frontend scan table: the crawler reports each page's `issuesCount` as the number of rules
 * with findings (axe groups every element of a rule into one violation), AQG stores it as `issues_count`, and the
 * Overview lists it per page. Occurrences (the elements) are a different unit and never fill this column.
 */
final class RemoteIssueTypesPipelineFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theCrawlersRuleCountReachesTheIssueTypesColumn(): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => 5,
            'site_identifier' => 'main',
            'job_id' => '55555555-5555-4555-8555-555555555555',
            'source_type' => 'sitemap',
            'scan_scope' => 'site',
            'start_url' => 'https://example.test/',
            'status' => 'completed',
            'finished_at' => 1789200000,
            'crdate' => 1789200000,
            'tstamp' => 1789200000,
        ]);

        $repository = $this->get(RemoteScanRepository::class);
        // Results as /crawl/results returns them: two rules on /about (five elements), one on /contact.
        $repository->saveScanPages(5, RemoteScanSourceType::Sitemap, [
            ['pageId' => 'p-1', 'url' => 'https://example.test/contact', 'title' => 'Contact', 'httpStatus' => 200, 'issuesCount' => 1],
            ['pageId' => 'p-2', 'url' => 'https://example.test/about', 'title' => 'About', 'httpStatus' => 200, 'issuesCount' => 2],
            ['pageId' => 'p-3', 'url' => 'https://example.test/clean', 'title' => 'Clean', 'httpStatus' => 200, 'issuesCount' => 0],
        ]);

        $pages = $repository->findPagesForScanPaginated(5, 20, 0);
        self::assertSame(['About', 'Contact', 'Clean'], array_column($pages, 'title'), 'Most issue types first.');
        self::assertSame([2, 1, 0], array_map('intval', array_column($pages, 'issues_count')));

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/')],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
        ));
        $view->assignMultiple(['partial' => 'Overview/RemotePanel', 'arguments' => [
            'remoteScan' => ['uid' => 5, 'job_id' => '55555555-5555-4555-8555-555555555555', 'issues_total' => 7, 'finished_at' => 1789200000],
            'remotePages' => array_map(static fn (array $page): array => $page + ['detailUrl' => '/detail/' . $page['uid']], $pages),
            'totalRemotePages' => 3,
            'remotePagination' => ['totalPages' => 1],
            'remoteFailedPages' => [],
            'freePreview' => ['isFree' => false],
        ]]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $view->render() . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $cells = [];
        foreach ((new \DOMXPath($document))->query('//section[@id="a11y-remote-top-pages"]//table/tbody/tr/td[2]') as $cell) {
            $cells[] = trim($cell->textContent);
        }

        self::assertSame(['2', '1', '0'], $cells, 'Every listed page shows its number of issue types; none is blank.');
    }
}
