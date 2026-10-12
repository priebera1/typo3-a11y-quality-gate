<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Rendered\RenderedPageTypeGuard;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A Content scan of a hidden page checks its records (TYPO3 reads them whatever their visibility), while the rendered
 * check, which requests the page as an anonymous visitor, cannot open it. That page is recorded as "rendered HTML not
 * checked: hidden page", and a rendered finding stored by an earlier scan is not resolved by the missing check.
 */
final class HiddenPageContentScanFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'fixture';
    private const ROOT = 1;
    private const HIDDEN_PAGE = 2;
    private const CONTENT = 10;

    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::ROOT, 'pid' => 0, 'title' => 'Fixture', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::HIDDEN_PAGE, 'pid' => self::ROOT, 'title' => 'Hidden copy', 'slug' => '/hidden-copy', 'doktype' => 1, 'deleted' => 0, 'hidden' => 1,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::CONTENT, 'pid' => self::HIDDEN_PAGE, 'CType' => 'text', 'header' => 'Text',
            'bodytext' => '<p><a href="/contact"></a></p>', 'sys_language_uid' => 0, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);

        // `.invalid` never resolves: the rendered fetch fails like the 404 a hidden page gives a visitor.
        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, self::ROOT, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function theRecordsOfAHiddenPageAreCheckedAndItsMissingRenderedCheckResolvesNothing(): void
    {
        $storedRenderedFinding = $this->insertIssue([
            'site_identifier' => self::SITE,
            'page_uid' => self::HIDDEN_PAGE,
            'source_table' => 'pages',
            'source_uid' => self::HIDDEN_PAGE,
            'source_field' => '__rendered_html',
            'source_type' => 'rendered',
            'rule_id' => 'rendered.main_landmark_missing',
            'context_snippet' => '<body>',
        ]);

        $result = $this->get(ScanOrchestrator::class)->scanPage(self::SITE, self::HIDDEN_PAGE, 0);

        self::assertSame(1, $result->issuesNew, 'The empty link in the hidden page\'s text is found.');
        self::assertSame(0, $result->issuesResolved, 'The rendered check did not run, so no rendered finding is resolved.');
        self::assertSame(0, (int)$this->findByUid('tx_a11y_issue', $storedRenderedFinding)['status']);

        $coverage = $result->renderedCoverage();
        self::assertSame(0, $coverage['checked']);
        self::assertSame(1, $coverage['skipped']);
        self::assertSame(1, $coverage['notPublic']);
        self::assertSame(RenderedPageTypeGuard::RESTRICTION_HIDDEN, $coverage['skippedPages'][0]['reason']);
    }

    #[Test]
    public function visitorRestrictionsAreRecognised(): void
    {
        $guard = $this->get(RenderedPageTypeGuard::class);
        $pages = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');

        self::assertSame('', $guard->visitorRestriction(self::ROOT));
        self::assertSame(RenderedPageTypeGuard::RESTRICTION_HIDDEN, $guard->visitorRestriction(self::HIDDEN_PAGE));

        $pages->update('pages', ['hidden' => 0, 'endtime' => time() - 60], ['uid' => self::HIDDEN_PAGE]);
        self::assertSame(RenderedPageTypeGuard::RESTRICTION_SCHEDULED, $guard->visitorRestriction(self::HIDDEN_PAGE));

        $pages->update('pages', ['endtime' => 0, 'fe_group' => '-1'], ['uid' => self::HIDDEN_PAGE]);
        self::assertSame('', $guard->visitorRestriction(self::HIDDEN_PAGE), 'Hidden at login only: visitors see it.');

        $pages->update('pages', ['fe_group' => '3'], ['uid' => self::HIDDEN_PAGE]);
        self::assertSame(RenderedPageTypeGuard::RESTRICTION_RESTRICTED, $guard->visitorRestriction(self::HIDDEN_PAGE));

        // A hidden translation is not shown in its language, the default language page still is.
        $pages->update('pages', ['fe_group' => ''], ['uid' => self::HIDDEN_PAGE]);
        $pages->insert('pages', [
            'uid' => 3, 'pid' => self::ROOT, 'title' => 'Versteckt', 'slug' => '/versteckt', 'doktype' => 1, 'deleted' => 0,
            'hidden' => 1, 'sys_language_uid' => 1, 'l10n_parent' => self::HIDDEN_PAGE,
        ]);
        self::assertSame('', $guard->visitorRestriction(self::HIDDEN_PAGE, 0));
        self::assertSame(RenderedPageTypeGuard::RESTRICTION_HIDDEN, $guard->visitorRestriction(self::HIDDEN_PAGE, 1));
    }
}
