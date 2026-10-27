<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Service\LocalHistoryRetentionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Deletes stored frontend scans older than the period chosen in Settings › Scan history (LocalHistoryRetentionService).
 *
 * A dry run unless `--apply` is given: it prints what would be deleted and changes nothing. With the default period
 * ("keep") nothing is ever selected. Run deliberately by an operator — not a Scheduler task (Services.yaml), so a
 * shortened period never deletes history without someone looking at what it selects. Never deletes the newest scan of a
 * comparison group, a running scan, or scans a pending Verify fix or monitoring still needs. Exits with an error when
 * the selection changed between the dry run it prints and the deletion (nothing is deleted then). The AQG service's own
 * scan history and its screenshots are not affected.
 *
 * Registered in Configuration/Services.yaml only; see the note there before adding #[AsCommand].
 */
final class HistoryPruneCommand extends Command
{
    public function __construct(
        private readonly LocalHistoryRetentionService $localHistoryRetentionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('apply', null, InputOption::VALUE_NONE, 'Delete the selected scans. Without it, only report what would be deleted.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $setting = $this->localHistoryRetentionService->getSetting();
        $preview = $this->localHistoryRetentionService->preview($setting);

        if ($preview['cutoff'] === null) {
            $io->success('Scan history is kept (Settings › Scan history: keep). Nothing to delete.');
            return Command::SUCCESS;
        }

        $io->writeln(sprintf(
            'Period: %s · scans that finished before %s',
            $setting,
            LocalHistoryRetentionService::formatUtc((int)$preview['cutoff'])
        ));
        $io->writeln(sprintf(
            'Selected for deletion: %d scans, %d stored pages, %d occurrences%s',
            $preview['scans'],
            $preview['pages'],
            $preview['occurrences'],
            $preview['scans'] > 0
                ? sprintf(' (finished from %s to %s)', LocalHistoryRetentionService::formatUtc($preview['oldest']), LocalHistoryRetentionService::formatUtc($preview['newest']))
                : ''
        ));
        foreach ($preview['bySite'] as $row) {
            $io->writeln(sprintf('  - site %s, language %s: %d scans', $row['site'], $row['language'] < 0 ? 'all' : (string)$row['language'], $row['scans']));
        }
        $kept = array_sum($preview['protected']);
        if ($kept > 0) {
            $io->writeln(sprintf(
                'Kept although older: %d (newest of a comparison: %d, running: %d, pending Verify fix: %d, monitoring: %d)',
                $kept,
                $preview['protected'][LocalHistoryRetentionService::PROTECTED_BASELINE],
                $preview['protected'][LocalHistoryRetentionService::PROTECTED_RUNNING],
                $preview['protected'][LocalHistoryRetentionService::PROTECTED_VERIFICATION],
                $preview['protected'][LocalHistoryRetentionService::PROTECTED_MONITORING]
            ));
        }

        if (!(bool)$input->getOption('apply')) {
            $io->note('Dry run: nothing was deleted. Run again with --apply to delete these scans.');
            return Command::SUCCESS;
        }

        if ($preview['scans'] === 0) {
            $io->success('Nothing to delete.');
            return Command::SUCCESS;
        }

        $deleted = $this->localHistoryRetentionService->prune($setting, (int)$preview['cutoff'], $preview['reviewToken']);
        if ($deleted === null) {
            // The selection changed between the dry run above and the deletion (a scan finished, a Verify fix or
            // monitoring run started to refer to one): nothing was deleted.
            $io->error('Nothing was deleted: the selection changed while the command ran. Run it again to review the current selection.');
            return Command::FAILURE;
        }
        if ($deleted < $preview['scans']) {
            $io->warning(sprintf('%d of %d scans deleted; the others became protected while the command ran and were kept.', $deleted, $preview['scans']));
            return Command::SUCCESS;
        }
        $io->success(sprintf('%d scans deleted.', $deleted));

        return Command::SUCCESS;
    }
}
