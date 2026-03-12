<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Base class for all EXT:a11y_quality_gate functional tests.
 *
 * Bootstraps a real TYPO3 test database with all extension tables.
 * Each test runs in isolation — the testing framework resets DB state.
 */
abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'priebera/typo3-a11y-quality-gate',
    ];

    protected array $coreExtensionsToLoad = [
        'backend',
        'scheduler',
    ];

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $overrides */
    protected function insertIssue(array $overrides = []): int
    {
        $conn     = $this->getConn('tx_a11y_issue');
        $defaults = [
            'site_identifier'     => 'test',
            'page_uid'            => 1,
            'source_lang_uid'     => 0,
            'source_table'        => 'tt_content',
            'source_uid'          => 1,
            'source_field'        => 'bodytext',
            'rule_id'             => 'rte.img_alt_missing',
            'severity'            => 1,
            'message'             => 'Image is missing the alt attribute.',
            'hint'                => 'Add alt text.',
            'context_snippet'     => '<img src="test.jpg">',
            'context_path'        => 'Page:1 > tt_content:1 > bodytext',
            'fingerprint'         => sha1(uniqid('fp', true)),
            'status'              => 0,
            'first_seen_scan_uid' => 1,
            'last_seen_scan_uid'  => 1,
            'crdate'              => time(),
            'tstamp'              => time(),
        ];
        $conn->insert('tx_a11y_issue', array_merge($defaults, $overrides));
        return (int)$conn->lastInsertId();
    }

    /** @param array<string, mixed> $overrides */
    protected function insertScan(array $overrides = []): int
    {
        $conn     = $this->getConn('tx_a11y_scan');
        $defaults = [
            'site_identifier' => 'test',
            'root_pid'        => 1,
            'language_uid'    => -1,
            'scope'           => 'subtree',
            'status'          => 2,
            'started_at'      => time() - 10,
            'finished_at'     => time(),
            'pages_scanned'   => 1,
            'records_scanned' => 1,
            'issues_new'      => 0,
            'issues_resolved' => 0,
            'issues_ignored'  => 0,
            'crdate'          => time(),
            'tstamp'          => time(),
        ];
        $conn->insert('tx_a11y_scan', array_merge($defaults, $overrides));
        return (int)$conn->lastInsertId();
    }

    /** @param array<string, mixed> $overrides */
    protected function insertSourceState(array $overrides = []): int
    {
        $conn     = $this->getConn('tx_a11y_source_state');
        $defaults = [
            'site_identifier' => 'test',
            'page_uid'        => 1,
            'source_lang_uid' => 0,
            'source_table'    => 'tt_content',
            'source_uid'      => 1,
            'source_field'    => 'bodytext',
            'content_hash'    => sha1('test content'),
            'last_scan_uid'   => 1,
            'crdate'          => time(),
            'tstamp'          => time(),
        ];
        $conn->insert('tx_a11y_source_state', array_merge($defaults, $overrides));
        return (int)$conn->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    protected function findByUid(string $table, int $uid): ?array
    {
        $row = $this->getConn($table)->select(['*'], $table, ['uid' => $uid])->fetchAssociative();
        return $row ?: null;
    }

    /** @param array<string, mixed> $where */
    protected function countRows(string $table, array $where = []): int
    {
        return (int)$this->getConn($table)->count('uid', $table, $where);
    }

    private function getConn(string $table): \TYPO3\CMS\Core\Database\Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table);
    }
}
