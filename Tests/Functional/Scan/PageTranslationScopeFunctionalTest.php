<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Scan\PageCollector;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * D-02 (2026-11-05 company acceptance): a subtree scan returned page translation rows as pages of their own. The rendered
 * check then fetched "page <translation uid>, language 0" and stored the German findings on the translation row in
 * language 0 — in the English Overview and report, attributed to the default-language record.
 */
final class PageTranslationScopeFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pages = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages');
        $pages->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
        $pages->insert('pages', ['uid' => 2, 'pid' => 1, 'title' => 'Products', 'slug' => '/products', 'doktype' => 1]);
        $pages->insert('pages', ['uid' => 3, 'pid' => 2, 'title' => 'Cargo Bikes', 'slug' => '/products/cargo-bikes', 'doktype' => 1]);
        // German translations: same pid as their default page, sys_language_uid 1.
        $pages->insert('pages', ['uid' => 32, 'pid' => 1, 'title' => 'Produkte', 'slug' => '/produkte', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 2]);
        $pages->insert('pages', ['uid' => 34, 'pid' => 2, 'title' => 'Lastenräder', 'slug' => '/produkte/lastenraeder', 'doktype' => 1, 'sys_language_uid' => 1, 'l10n_parent' => 3, 'hidden' => 1]);
        $pages->insert('pages', ['uid' => 35, 'pid' => 2, 'title' => 'Gone', 'slug' => '/gone', 'doktype' => 1, 'sys_language_uid' => 2, 'l10n_parent' => 3, 'deleted' => 1]);
    }

    #[Test]
    public function aSubtreeContainsDefaultLanguagePagesOnly(): void
    {
        self::assertSame([1, 2, 3], $this->get(PageCollector::class)->collectSubtree(1));
    }

    #[Test]
    public function translationsAreLanguagesOfTheirDefaultPage(): void
    {
        $collector = $this->get(PageCollector::class);

        self::assertSame([32 => 1], $collector->translationsOf(2));
        self::assertSame([34 => 1], $collector->translationsOf(3), 'A hidden translation is still a language to report on; a deleted one is not.');
        self::assertSame([], $collector->translationsOf(1));
    }

    #[Test]
    public function aLanguageScanChecksOnlyPagesThatExistInThatLanguage(): void
    {
        // N-18 (final customer acceptance): a German subtree scan counted and fetched every English page ("Content
        // checks ran on 18 pages … could not be checked on 17") although 10 pages exist in German.
        $collector = $this->get(PageCollector::class);

        self::assertSame([2, 3], $collector->withTranslationIn([1, 2, 3], 1), 'The hidden translation of Cargo Bikes is still a German page.');
        self::assertSame([], $collector->withTranslationIn([1, 2, 3], 2), 'A deleted translation is no page in that language.');
        self::assertSame([1, 2, 3], $collector->withTranslationIn([1, 2, 3], 0));
    }

    #[Test]
    public function renderedFindingsLeftOnTranslationRowsAreResolvedNotDeleted(): void
    {
        $issues = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue');
        foreach ([
            [101, 34, 'rendered', 'northwind'],
            [102, 34, 'rte', 'northwind'],
            [103, 3, 'rendered', 'northwind'],
            [104, 34, 'rendered', 'careers'],
        ] as [$uid, $page, $type, $site]) {
            $issues->insert('tx_a11y_issue', [
                'uid' => $uid, 'site_identifier' => $site, 'page_uid' => $page, 'source_type' => $type, 'source_table' => 'tt_content',
                'source_uid' => 5, 'source_field' => '__rendered_html', 'rule_id' => 'rendered.empty_link', 'severity' => 1,
                'fingerprint' => 'fp' . $uid, 'status' => 0, 'message' => 'Rendered link has no accessible name.', 'hint' => '', 'context_snippet' => '<a id="c34"></a>', 'context_path' => '', 'css_selector' => '', 'frontend_url' => '', 'ignored_reason' => '', 'ignored_by_name' => '', 'ignored_by_username' => '', 'resolved_by_name' => '', 'resolved_by_username' => '',
            ]);
        }

        $resolved = $this->get(IssueRepository::class)->resolveRenderedOnTranslationRows([34], 'northwind', 9);

        self::assertSame(1, $resolved);
        $rows = $issues->select(['uid', 'status', 'deleted'], 'tx_a11y_issue', [], [], ['uid' => 'ASC'])->fetchAllAssociative();
        self::assertSame(
            [[101, 1, 0], [102, 0, 0], [103, 0, 0], [104, 0, 0]],
            array_map(static fn (array $r): array => [(int)$r['uid'], (int)$r['status'], (int)$r['deleted']], $rows),
            'Only the rendered row of the translation in this site is resolved; nothing is deleted.'
        );
    }
}
