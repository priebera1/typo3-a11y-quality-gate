<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Deletes agent access audit entries older than the retention: 90 days (owner decision 2026-10-29) or the configured
 * mcpAuditRetentionDays. An invalid value deletes nothing and fails. The connector prunes when it starts and every hour;
 * this command serves installations that run no connector for a while, operators and system cron.
 */
final class McpAuditPruneCommand extends Command
{
    public function __construct(
        private readonly McpAuditRepository $auditRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->auditRepository->retentionMisconfigured()) {
            $output->writeln('<error>mcpAuditRetentionDays must be a whole number of days between 1 and 3650 (empty means the default of 90). Nothing was deleted.</error>');

            return Command::FAILURE;
        }
        $deleted = (int)$this->auditRepository->prune();
        $output->writeln(sprintf('Deleted %d agent access audit entries older than %d days.', $deleted, (int)$this->auditRepository->retentionDays()));

        return Command::SUCCESS;
    }
}
