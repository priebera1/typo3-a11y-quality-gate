<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Command;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Command\RemoteDuplicateRepairCommand;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * NEW-01 repair (independent review P2-3): of two copies of one page the copy holding the most findings is kept (an
 * interrupted earlier store may have left a partial copy with the lower uid); a scan that is being stored right now is
 * never touched; the backup is owner-readable only and restores every removed row.
 */
final class RemoteDuplicateRepairCommandFunctionalTest extends AbstractFunctionalTestCase
{
    private string $backup = '';

    protected function tearDown(): void
    {
        if ($this->backup !== '' && is_file($this->backup)) {
            unlink($this->backup);
        }
        parent::tearDown();
    }

    #[Test]
    public function theCompleteCopyIsKeptAndABackupRestoresTheRemovedRows(): void
    {
        $scan = $this->scan(claimUntil: 0);
        $partial = $this->page($scan, 'https://site.example/a');
        $complete = $this->page($scan, 'https://site.example/a');
        $this->issue($scan, $partial, 'fp-1');
        foreach (['fp-1', 'fp-2', 'fp-3'] as $fingerprint) {
            $this->issue($scan, $complete, $fingerprint);
        }
        $this->backup = sys_get_temp_dir() . '/aqg-repair-' . bin2hex(random_bytes(6)) . '.json';

        $tester = new CommandTester($this->get(RemoteDuplicateRepairCommand::class));
        self::assertSame(0, $tester->execute(['--apply' => true, '--backup' => $this->backup]));

        self::assertSame([$complete], $this->pageUids($scan), 'The copy with all three findings stays.');
        self::assertSame(3, $this->rows('tx_a11y_remote_issue', ['remote_scan' => $scan]));
        self::assertSame('0600', substr(sprintf('%o', fileperms($this->backup)), -4), 'URLs and snippets: owner only.');

        $restore = new CommandTester($this->get(RemoteDuplicateRepairCommand::class));
        self::assertSame(0, $restore->execute(['--restore' => $this->backup]));
        self::assertSame([$partial, $complete], $this->pageUids($scan));
        self::assertSame(4, $this->rows('tx_a11y_remote_issue', ['remote_scan' => $scan]));
    }

    #[Test]
    public function aScanBeingStoredRightNowIsNotTouched(): void
    {
        $scan = $this->scan(claimUntil: time() + 200);
        $first = $this->page($scan, 'https://site.example/a');
        $second = $this->page($scan, 'https://site.example/a');
        $this->backup = sys_get_temp_dir() . '/aqg-repair-' . bin2hex(random_bytes(6)) . '.json';

        $tester = new CommandTester($this->get(RemoteDuplicateRepairCommand::class));
        self::assertSame(0, $tester->execute(['--apply' => true, '--backup' => $this->backup]));

        self::assertSame([$first, $second], $this->pageUids($scan));
        self::assertFileDoesNotExist($this->backup, 'Nothing to remove: no backup is written.');
    }

    private function scan(int $claimUntil): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan');
        $connection->insert('tx_a11y_remote_scan', ['site_identifier' => 'main', 'job_id' => 'job-' . bin2hex(random_bytes(4)), 'status' => 'completed',
            'issues_total' => 3, 'persisted_at' => 1790000000, 'persist_claim_until' => $claimUntil]);

        return (int)$connection->lastInsertId();
    }

    private function page(int $scan, string $url): int
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan_page');
        $connection->insert('tx_a11y_remote_scan_page', ['remote_scan' => $scan, 'url' => $url]);

        return (int)$connection->lastInsertId();
    }

    private function issue(int $scan, int $page, string $fingerprint): void
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_issue');
        $connection->insert('tx_a11y_remote_issue', ['remote_scan' => $scan, 'remote_scan_page' => $page, 'fingerprint' => $fingerprint, 'rule_id' => 'image-alt', 'nodes_count' => 1]);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_issue_node')
            ->insert('tx_a11y_remote_issue_node', ['remote_issue' => (int)$connection->lastInsertId(), 'html_snippet' => '<img>']);
    }

    /** @return list<int> */
    private function pageUids(int $scan): array
    {
        return array_map('intval', GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan_page')
            ->select(['uid'], 'tx_a11y_remote_scan_page', ['remote_scan' => $scan], [], ['uid' => 'ASC'])->fetchFirstColumn());
    }

    /** @param array<string, int> $where */
    private function rows(string $table, array $where): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->count('*', $table, $where);
    }
}
