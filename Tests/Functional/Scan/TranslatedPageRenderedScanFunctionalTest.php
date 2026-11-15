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
 * Owner MCP pilot QA 2026-11-15 (findings 74357–74360 on "page 745, language 0", the German translation record of 737):
 * end to end through the real rendered check — a content scan of a site with a German translation fetches the German
 * page over HTTP (loopback fixture server, staging option "allow private hosts") and stores its rendered findings on the
 * DEFAULT page with language 1 and the German content record. No finding is stored on the translation record, and a
 * legacy row a 2.x scan left there is resolved (kept, not deleted, not moved).
 */
final class TranslatedPageRenderedScanFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'main';

    /** @var resource|null */
    private $server;
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->startServer();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        foreach ([
            // uid, pid, title, slug, root, language, l10n_parent
            [1, 0, 'Root', '/', 1, 0, 0],
            [2, 1, 'Cancel local scan', '/p2', 0, 0, 0],
            [20, 1, '[DE] Cancel local scan', '/p20', 0, 1, 2],
        ] as [$uid, $pid, $title, $slug, $isRoot, $language, $parent]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1, 'is_siteroot' => $isRoot,
                'sys_language_uid' => $language, 'l10n_parent' => $parent, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        foreach ([[10, 0, 0], [14, 1, 10]] as [$uid, $language, $parent]) {
            $pool->getConnectionForTable('tt_content')->insert('tt_content', [
                'uid' => $uid, 'pid' => 2, 'CType' => 'text', 'header' => 'Element', 'bodytext' => '<p>Text</p>',
                'sys_language_uid' => $language, 'l18n_parent' => $parent, 'deleted' => 0, 'hidden' => 0,
            ]);
        }
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);
        // The documented staging option: the rendered check may fetch a private (here loopback) host.
        $pool->getConnectionForTable('tx_a11y_ruleset')->insert('tx_a11y_ruleset', [
            'is_default' => 1, 'is_global' => 1, 'rules_json' => '{"renderedCheck":{"enabled":true,"allowPrivateHosts":true}}',
        ]);

        $this->get(SiteWriter::class)->write(self::SITE, [
            'rootPageId' => 1,
            'base' => 'http://127.0.0.1:' . $this->port . '/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
                ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'fallbackType' => 'strict'],
            ],
        ]);
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            $status = proc_get_status($this->server);
            if ($status['running']) {
                posix_kill($status['pid'], SIGKILL);
            }
            proc_close($this->server);
        }
        parent::tearDown();
    }

    #[Test]
    public function renderedFindingsOfATranslatedPageBelongToTheDefaultPageInItsLanguage(): void
    {
        $legacy = $this->insertIssue([
            'site_identifier' => self::SITE, 'page_uid' => 20, 'source_lang_uid' => 0, 'source_table' => 'tt_content', 'source_uid' => 10,
            'source_field' => '__rendered_html', 'source_type' => 'rendered', 'rule_id' => 'rendered.empty_button', 'severity' => 1,
            'context_snippet' => '<button type="button"></button>', 'css_selector' => 'div#c10 > button:nth-of-type(1)',
        ]);

        // "All languages": the page collector returns page 2 only; its German translation is rendered as page 2, language 1.
        $result = $this->get(ScanOrchestrator::class)->scanSubtree(self::SITE, 1, 99, -1, includeRenderedPageCheck: true);

        $coverage = $result->renderedCoverage();
        self::assertSame(0, $coverage['skipped'], 'every page and language was rendered: ' . json_encode($coverage));
        $rendered = $this->connection('tx_a11y_issue')->select(
            ['uid', 'page_uid', 'source_lang_uid'],
            'tx_a11y_issue',
            ['source_type' => 'rendered', 'status' => 0, 'deleted' => 0],
            [],
            ['uid' => 'ASC'],
        )->fetchAllAssociative();
        $places = [];
        foreach ($rendered as $row) {
            $places[(int)$row['page_uid'] . ':' . (int)$row['source_lang_uid']] = true;
        }
        ksort($places);
        // (Without AQG's record markers the fixture page maps to the page record; the record mapping is covered by
        // RenderedTranslationRecordsFunctionalTest. Here: the page and language each rendered finding is stored on.)
        self::assertSame(['1:0', '2:0', '2:1'], array_keys($places), 'root (English only), page 2 in English and German; never page 20');
        self::assertSame(0, (int)$this->connection('tx_a11y_issue')->count('uid', 'tx_a11y_issue', ['page_uid' => 20, 'status' => 0]), 'nothing open on the translation record');

        $legacyRow = $this->findByUid('tx_a11y_issue', $legacy);
        self::assertSame(1, (int)$legacyRow['status'], 'the 2.x row on the translation record is resolved');
        self::assertSame([20, 0, 0], [(int)$legacyRow['page_uid'], (int)$legacyRow['source_lang_uid'], (int)$legacyRow['deleted']], 'kept as stored: not moved, not deleted');
    }

    private function startServer(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $this->port = (int)parse_url('tcp://' . stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);
        $dir = $this->instancePath . '/translated-page-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir));
        $this->server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, __DIR__ . '/../Fixtures/Http/translated-page-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $dir . '/stdout', 'a'], 2 => ['file', $dir . '/stderr', 'a']],
            $pipes,
        );
        for ($i = 0; $i < 100; $i++) {
            $probe = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.1);
            if (is_resource($probe)) {
                fclose($probe);
                return;
            }
            usleep(50_000);
        }
        self::fail('Fixture server did not start.');
    }

    private function connection(string $table): \TYPO3\CMS\Core\Database\Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
    }
}
