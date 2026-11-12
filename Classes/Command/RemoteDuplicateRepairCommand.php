<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Database\Tables;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Repairs frontend scans that were stored more than once (resilience audit NEW-01, fixed for new scans in
 * RemoteScanPersistenceService) — audited, reversible, never silent.
 *
 * A stored scan is a duplicate where it holds the same page URL twice, the same finding (fingerprint) twice on one
 * page, or findings whose page row no longer belongs to the scan (a concurrent store replaced it). Such copies came
 * from concurrent stores of one completed crawler job and carry no information of their own.
 *
 * - Dry run (default): reports every affected scan with its page and finding counts before and after; changes nothing.
 * - `--apply --backup=<file>`: writes every row it will remove (pages, findings, nodes, with their uids) to the JSON
 *   file (mode 0600) first, then removes them in one transaction per scan. Of the copies of one URL it keeps the one
 *   holding the most findings (the lowest uid when equal). Scans being stored right now (live persistence claim) are
 *   skipped. Stored counters are the crawler's totals and stay unchanged.
 * - `--restore=<file>`: re-inserts the rows of such a backup (rollback).
 *
 * Registered in Configuration/Services.yaml only; never a Scheduler task.
 */
final class RemoteDuplicateRepairCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Remove the duplicate rows (needs --backup). Without it, only report.');
        $this->addOption('backup', null, InputOption::VALUE_REQUIRED, 'JSON file the removed rows are written to before anything is removed.');
        $this->addOption('restore', null, InputOption::VALUE_REQUIRED, 'Re-insert the rows of a backup written by --apply (rollback).');
        $this->addOption('scan', null, InputOption::VALUE_REQUIRED, 'Only this stored scan (tx_a11y_remote_scan uid).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $restore = (string)($input->getOption('restore') ?? '');
        if ($restore !== '') {
            return $this->restore($io, $restore);
        }
        $apply = (bool)$input->getOption('apply');
        $backup = (string)($input->getOption('backup') ?? '');
        if ($apply && $backup === '') {
            $io->error('--apply needs --backup=<file>: the removed rows are written there first, for a rollback with --restore.');

            return Command::INVALID;
        }
        if ($apply && file_exists($backup)) {
            $io->error('The backup file exists already; choose a new file.');

            return Command::INVALID;
        }

        $plan = $this->plan((int)($input->getOption('scan') ?? 0));
        if ($plan === []) {
            $io->success('No stored frontend scan holds duplicate pages or findings.');

            return Command::SUCCESS;
        }
        $rows = [];
        foreach ($plan as $scan) {
            $rows[] = [$scan['uid'], $scan['jobId'], $scan['pagesBefore'] . ' → ' . $scan['pagesAfter'], $scan['issuesBefore'] . ' → ' . $scan['issuesAfter'], $scan['occurrencesAfter'] . ' / ' . $scan['issuesTotal']];
        }
        $io->table(['Scan', 'Job', 'Pages', 'Findings', 'Occurrences / crawler total'], $rows);
        if (!$apply) {
            $io->note('Dry run: nothing was changed. Re-run with --apply --backup=<file> to remove the duplicates.');

            return Command::SUCCESS;
        }

        $removed = ['createdAt' => date('c'), 'pages' => [], 'issues' => [], 'nodes' => []];
        foreach ($plan as $scan) {
            $removed['pages'] = array_merge($removed['pages'], $this->fetchByUids(Tables::REMOTE_SCAN_PAGE, $scan['removePages']));
            $removed['issues'] = array_merge($removed['issues'], $this->fetchByUids(Tables::REMOTE_ISSUE, $scan['removeIssues']));
            $removed['nodes'] = array_merge($removed['nodes'], $this->fetchByColumn(Tables::REMOTE_ISSUE_NODE, 'remote_issue', $scan['removeIssues']));
        }
        // The backup holds page URLs and HTML snippets: readable by its owner only.
        $previousUmask = umask(0077);
        $written = @file_put_contents($backup, json_encode($removed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        umask($previousUmask);
        if ($written === false) {
            $io->error('The backup could not be written; nothing was removed.');

            return Command::FAILURE;
        }
        @chmod($backup, 0600);
        $connection = $this->connectionPool->getConnectionForTable(Tables::REMOTE_SCAN);
        foreach ($plan as $scan) {
            $connection->transactional(function () use ($scan): void {
                $this->deleteByColumn(Tables::REMOTE_ISSUE_NODE, 'remote_issue', $scan['removeIssues']);
                $this->deleteByColumn(Tables::REMOTE_ISSUE, 'uid', $scan['removeIssues']);
                $this->deleteByColumn(Tables::REMOTE_SCAN_PAGE, 'uid', $scan['removePages']);
            });
        }
        $io->success(sprintf('Removed %d duplicate pages, %d findings and %d nodes from %d scans. Backup: %s', count($removed['pages']), count($removed['issues']), count($removed['nodes']), count($plan), $backup));

        return Command::SUCCESS;
    }

    /**
     * @return list<array{uid:int,jobId:string,issuesTotal:int,pagesBefore:int,pagesAfter:int,issuesBefore:int,issuesAfter:int,occurrencesAfter:int,removePages:list<int>,removeIssues:list<int>}>
     */
    private function plan(int $onlyScan): array
    {
        $plan = [];
        $scans = $this->connectionPool->getConnectionForTable(Tables::REMOTE_SCAN)
            ->select(['uid', 'job_id', 'issues_total', 'persist_claim_until'], Tables::REMOTE_SCAN, $onlyScan > 0 ? ['uid' => $onlyScan] : [])
            ->fetchAllAssociative();
        foreach ($scans as $scan) {
            $scanUid = (int)$scan['uid'];
            // A scan that a request is storing right now (live persistence claim; since 3.0 every store holds one) is
            // left alone: its rows are still being written and are not duplicates.
            if ((int)$scan['persist_claim_until'] >= time()) {
                continue;
            }
            $pages = $this->connectionPool->getConnectionForTable(Tables::REMOTE_SCAN_PAGE)
                ->select(['uid', 'url'], Tables::REMOTE_SCAN_PAGE, ['remote_scan' => $scanUid], [], ['uid' => 'ASC'])->fetchAllAssociative();
            $issues = $this->connectionPool->getConnectionForTable(Tables::REMOTE_ISSUE)
                ->select(['uid', 'remote_scan_page', 'fingerprint', 'nodes_count'], Tables::REMOTE_ISSUE, ['remote_scan' => $scanUid], [], ['uid' => 'ASC'])->fetchAllAssociative();
            $findingsPerPage = [];
            foreach ($issues as $issue) {
                $findingsPerPage[(int)$issue['remote_scan_page']] = ($findingsPerPage[(int)$issue['remote_scan_page']] ?? 0) + 1;
            }
            // Of the copies of one URL, the one holding the most findings is kept (an interrupted earlier store may have
            // left a copy with only part of them); equal copies keep the lowest uid.
            $keptPageByUrl = [];
            foreach ($pages as $page) {
                $url = (string)$page['url'];
                $uid = (int)$page['uid'];
                if (!isset($keptPageByUrl[$url]) || ($findingsPerPage[$uid] ?? 0) > ($findingsPerPage[$keptPageByUrl[$url]] ?? 0)) {
                    $keptPageByUrl[$url] = $uid;
                }
            }
            $keptPages = array_flip(array_values($keptPageByUrl));
            $removePages = [];
            foreach ($pages as $page) {
                if (!isset($keptPages[(int)$page['uid']])) {
                    $removePages[] = (int)$page['uid'];
                }
            }
            $keptIssue = [];
            $removeIssues = [];
            $occurrences = 0;
            foreach ($issues as $issue) {
                $pageUid = (int)$issue['remote_scan_page'];
                $identity = $pageUid . '|' . (string)$issue['fingerprint'];
                // A finding of a removed duplicate page, a second copy on the same page, or an orphan whose page row a
                // concurrent store had already replaced (it points at no page of this scan) is a copy, not evidence.
                if (!isset($keptPages[$pageUid]) || ((string)$issue['fingerprint'] !== '' && isset($keptIssue[$identity]))) {
                    $removeIssues[] = (int)$issue['uid'];
                    continue;
                }
                $keptIssue[$identity] = true;
                $occurrences += (int)$issue['nodes_count'];
            }
            if ($removePages === [] && $removeIssues === []) {
                continue;
            }
            $plan[] = [
                'uid' => $scanUid, 'jobId' => (string)$scan['job_id'], 'issuesTotal' => (int)$scan['issues_total'],
                'pagesBefore' => count($pages), 'pagesAfter' => count($pages) - count($removePages),
                'issuesBefore' => count($issues), 'issuesAfter' => count($issues) - count($removeIssues),
                'occurrencesAfter' => $occurrences, 'removePages' => $removePages, 'removeIssues' => $removeIssues,
            ];
        }

        return $plan;
    }

    private function restore(SymfonyStyle $io, string $file): int
    {
        $data = json_decode((string)@file_get_contents($file), true);
        if (!is_array($data) || !isset($data['pages'], $data['issues'], $data['nodes'])) {
            $io->error('Not a backup written by this command.');

            return Command::INVALID;
        }
        $connection = $this->connectionPool->getConnectionForTable(Tables::REMOTE_SCAN);
        $connection->transactional(function () use ($data): void {
            foreach ([Tables::REMOTE_SCAN_PAGE => $data['pages'], Tables::REMOTE_ISSUE => $data['issues'], Tables::REMOTE_ISSUE_NODE => $data['nodes']] as $table => $rows) {
                foreach ($rows as $row) {
                    $this->connectionPool->getConnectionForTable($table)->insert($table, $row);
                }
            }
        });
        $io->success(sprintf('Restored %d pages, %d findings and %d nodes.', count($data['pages']), count($data['issues']), count($data['nodes'])));

        return Command::SUCCESS;
    }

    /** @param list<int> $uids @return list<array<string, mixed>> */
    private function fetchByUids(string $table, array $uids): array
    {
        return $this->fetchByColumn($table, 'uid', $uids);
    }

    /** @param list<int> $values @return list<array<string, mixed>> */
    private function fetchByColumn(string $table, string $column, array $values): array
    {
        $rows = [];
        foreach (array_chunk($values, 500) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->getRestrictions()->removeAll();
            $rows = array_merge($rows, $qb->select('*')->from($table)->where($qb->expr()->in($column, $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))->executeQuery()->fetchAllAssociative());
        }

        return $rows;
    }

    /** @param list<int> $values */
    private function deleteByColumn(string $table, string $column, array $values): void
    {
        foreach (array_chunk($values, 500) as $chunk) {
            $qb = $this->connectionPool->getQueryBuilderForTable($table);
            $qb->delete($table)->where($qb->expr()->in($column, $qb->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))->executeStatement();
        }
    }
}
