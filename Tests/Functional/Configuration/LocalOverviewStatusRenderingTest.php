<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * The Content tab's status rendered with the real Fluid engine.
 *
 * Regression guard: at a site root that only had page scans, the tab said "No scan on record" six times and showed
 * "—" in every tile while the table listed 3 pages with 29 open issues; on a page with its own page scan, the status
 * described that one page next to site-wide totals without saying so.
 */
final class LocalOverviewStatusRenderingTest extends AbstractFunctionalTestCase
{
    private const TOTALS = ['critical' => 11, 'warning' => 14, 'info' => 3, 'needs_review' => 1, 'total' => 29];
    private const NONE = ['critical' => 0, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 0];
    private const PAGE_SCAN = [
        'uid' => 73,
        'scope' => 'page',
        'root_pid' => 463,
        'finished_at' => 1790000000,
        'finished_at_formatted' => '03.10.2026 12:53',
        'pages_scanned' => 1,
        'issues_new' => 25,
        'issues_resolved' => 0,
    ];

    #[Test]
    public function theStatusCountsOpenIssuesEvenWithoutADescribedScan(): void
    {
        $xpath = $this->render('Overview/LocalActions', $this->actionArguments(null, '', self::TOTALS));

        self::assertSame('29 open issues on 3 pages', $this->text($xpath, '//*[@id="aqg-local-status-title"]'));
        self::assertSame(4, $xpath->query('//*[contains(@class, "aqg-status__counts")]/li')->length);
        self::assertNotNull($xpath->query('//*[@data-aqg-local-results-without-scan]')->item(0), 'Says the issues come from earlier page scans.');
        self::assertStringNotContainsString('No content scan yet', $this->bodyText($xpath));
        self::assertStringNotContainsString('No scan on record', $this->bodyText($xpath));
    }

    #[Test]
    public function theStatusSaysNoScanOnlyWhenThereIsNothingAtAll(): void
    {
        $xpath = $this->render('Overview/LocalActions', $this->actionArguments(null, '', self::NONE));

        self::assertSame('No content scan yet', $this->text($xpath, '//*[@id="aqg-local-status-title"]'));
        self::assertSame(0, $xpath->query('//*[contains(@class, "aqg-status__counts")]')->length);
    }

    #[Test]
    public function theStatusNamesThePageScanItDescribes(): void
    {
        $xpath = $this->render('Overview/LocalActions', $this->actionArguments(self::PAGE_SCAN, 'Page scan: Test Page 1', self::TOTALS));

        $meta = $this->text($xpath, '//*[contains(@class, "aqg-status__meta")]');
        self::assertStringContainsString('Page scan: Test Page 1', $meta);
        self::assertStringContainsString('03.10.2026 12:53', $meta);
        self::assertStringNotContainsString('No content scan yet', $this->bodyText($xpath));
    }

    #[Test]
    public function aCleanSiteWithAScanSaysSo(): void
    {
        $xpath = $this->render('Overview/LocalActions', $this->actionArguments(self::PAGE_SCAN, 'Page scan: Test Page 1', self::NONE));

        self::assertSame('No open accessibility issues found.', $this->text($xpath, '//*[@id="aqg-local-status-title"]'));
    }

    #[Test]
    public function tableRowsOfferAScanOnlyWhereItIsAllowedAndInTheSelectedLanguage(): void
    {
        $row = ['pageTitle' => 'Page', 'detailUrl' => '/typo3/x', 'isFolder' => false, 'critical' => 1, 'warning' => 0, 'info' => 0, 'needs_review' => 0, 'total' => 1];
        $xpath = $this->render('Overview/LocalTable', [
            'pageStats' => [
                ['pageUid' => 463, 'canScan' => true] + $row,
                ['pageUid' => 472, 'canScan' => false] + $row,
            ],
            'totalLocalPages' => 2,
            'localPagination' => ['totalPages' => 1],
            'localPaginationItems' => [],
            'localQuery' => '',
            'lastScan' => self::PAGE_SCAN,
            'currentLanguageUid' => 1,
        ]);

        $buttons = $xpath->query('//button[@data-action="a11y-rescan"]');
        self::assertSame(1, $buttons->length, 'A user who may not scan page 472 gets no button that fails with 403.');
        self::assertSame('463', $buttons->item(0)?->getAttribute('data-page-uid'));
        self::assertSame('1', $buttons->item(0)?->getAttribute('data-language-uid'));
    }

    /**
     * @param array<string, mixed>|null $lastScan
     * @param array<string, int> $totals
     * @return array<string, mixed>
     */
    private function actionArguments(?array $lastScan, string $scopeLabel, array $totals): array
    {
        return [
            'lastScan' => $lastScan,
            'lastScanScopeLabel' => $scopeLabel,
            'hasLocalResults' => $totals['total'] > 0,
            'totalCounts' => $totals,
            'localNewCounts' => self::NONE,
            'totalLocalPages' => $totals['total'] > 0 ? 3 : 0,
            'localQuery' => '',
            'canScanAll' => true,
            'canScanNow' => true,
            'siteRootPid' => 461,
            'currentPageUid' => 461,
            'currentLanguageUid' => 0,
            'isPageContext' => false,
            'currentPageHasIssues' => false,
            'currentPageDetailUrl' => '',
            'scanStatus' => ['running' => false],
            'isRelevantLocalScanRunning' => false,
            'gettingStarted' => null,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function render(string $partial, array $arguments, string $language = 'default'): \DOMXPath
    {
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->user = ['uid' => 1, 'lang' => $language === 'default' ? '' : $language];
        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);

        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [
                GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/'),
            ],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/RenderPartial.html',
        ));
        $view->assignMultiple(['partial' => $partial, 'arguments' => $arguments]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"><body>' . $view->render() . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new \DOMXPath($document);
    }

    private function bodyText(\DOMXPath $xpath): string
    {
        return $this->text($xpath, '//body');
    }

    private function text(\DOMXPath $xpath, string $query): string
    {
        $node = $xpath->query($query)->item(0);
        self::assertNotNull($node, 'Not rendered: ' . $query);

        return trim((string)preg_replace('/\s+/', ' ', $node->textContent));
    }

    /**
     * @return list<string>
     */
    private function texts(\DOMXPath $xpath, string $query): array
    {
        $texts = [];
        foreach ($xpath->query($query) as $node) {
            $texts[] = trim((string)preg_replace('/\s+/', ' ', $node->textContent));
        }

        return $texts;
    }
}
