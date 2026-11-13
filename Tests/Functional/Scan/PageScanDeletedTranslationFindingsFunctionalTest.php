<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * ML-02 (2026-11-12 multilingual acceptance): deleting the only element of a page in one language left its findings
 * open, because a page scan that finds no record in that language evaluates no rule and only resolves findings of
 * evaluated rules; only a full site scan (U-09) retired them. The Quality Gate scans a single page, so a translation
 * kept failing its gate on content that no longer existed (deleting a default element also deletes its translations).
 * A page scan now retires open findings of deleted records of that page, in the scanned language only.
 */
final class PageScanDeletedTranslationFindingsFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'fixture';

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Page', 'slug' => '/page', 'doktype' => 1]);
        foreach ([[3, 1], [4, 2]] as [$uid, $language]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => 1, 'title' => 'Page L' . $language, 'slug' => '/page', 'doktype' => 1,
                'sys_language_uid' => $language, 'l10n_parent' => 2,
            ]);
        }
        foreach ([
            [10, 0, 0, '<p>Clean</p>'],      // live default element
            [11, 1, 1, '<p><a href="/x"></a></p>'], // deleted German element (the only one in German)
            [12, 2, 1, '<p><a href="/y"></a></p>'], // deleted Slovak element
        ] as [$uid, $language, $deleted, $body]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => 2, 'CType' => 'text', 'header' => 'T', 'bodytext' => $body,
                'sys_language_uid' => $language, 'l18n_parent' => $language > 0 ? 10 : 0, 'deleted' => $deleted,
            ]);
        }
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);
        // Issue 4: a deleted German element of another page (5) — not this page scan's business.
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 5, 'pid' => 1, 'title' => 'Other', 'slug' => '/other', 'doktype' => 1]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 13, 'pid' => 5, 'CType' => 'text', 'header' => 'T', 'bodytext' => '<p></p>', 'sys_language_uid' => 1, 'deleted' => 1,
        ]);
        foreach ([[1, 1, 11, 2], [2, 2, 12, 2], [3, 0, 10, 2], [4, 1, 13, 5]] as [$uid, $language, $source, $page]) {
            $this->insertIssue([
                'uid' => $uid, 'site_identifier' => self::SITE, 'page_uid' => $page, 'source_lang_uid' => $language,
                'source_table' => 'tt_content', 'source_uid' => $source, 'source_field' => 'bodytext', 'source_type' => 'rte',
                'rule_id' => $uid === 3 ? 'rte.marquee_or_blink' : 'rte.empty_link', 'severity' => 1, 'fingerprint' => 'fp' . $uid,
            ]);
        }
        // `.invalid` never resolves: no rendered check result interferes.
        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, 1, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function aPageScanInOneLanguageRetiresFindingsOfThatLanguagesDeletedRecordsOnly(): void
    {
        $result = $this->get(ScanOrchestrator::class)->scanPage(self::SITE, 2, 1);

        self::assertSame(1, $result->issuesResolved);
        self::assertSame(1, (int)$this->findByUid('tx_a11y_issue', 1)['status'], 'Deleted German element: resolved.');
        self::assertSame(0, (int)$this->findByUid('tx_a11y_issue', 2)['status'], 'Slovak was not scanned: unchanged.');
        self::assertSame(0, (int)$this->findByUid('tx_a11y_issue', 3)['status'], 'Live default element: unchanged.');
        self::assertSame(0, (int)$this->findByUid('tx_a11y_issue', 4)['status'], 'Another page: unchanged.');
    }

    #[Test]
    public function anAllLanguagesPageScanRetiresEveryDeletedTranslationsFindings(): void
    {
        $this->get(ScanOrchestrator::class)->scanPage(self::SITE, 2, -1);

        self::assertSame(1, (int)$this->findByUid('tx_a11y_issue', 1)['status']);
        self::assertSame(1, (int)$this->findByUid('tx_a11y_issue', 2)['status']);
    }

    #[Test]
    public function aChangedOnlyScanLeavesFindingsAlone(): void
    {
        $this->get(ScanOrchestrator::class)->scanPage(self::SITE, 2, 1, changedOnly: true);

        self::assertSame(0, (int)$this->findByUid('tx_a11y_issue', 1)['status']);
    }
}
