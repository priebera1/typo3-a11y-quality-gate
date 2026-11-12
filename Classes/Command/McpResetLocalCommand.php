<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpHostKey;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Log\LogManager;

/**
 * Forgets this installation's agent access connection in this database only (clone safety N2). Makes no Gateway call:
 * on a copy of an installation the stored credential is the original's, and a disconnect sent with it would end the
 * original's connection and every agent token. The original keeps working; the copy can be paired on its own afterwards
 * (as a new installation, after a11y:installation:identity --new-installation).
 *
 * --new-host-key also drops this host's MCP key file (Sol AQG-MCP-07): a full copy of the installation — database,
 * configuration and var/ — carries the original's key, and only a new key makes the copy's later pairing its own. A key
 * from AQG_MCP_CONNECTOR_KEY can only be replaced by the operator; the command says so.
 */
final class McpResetLocalCommand extends Command
{
    public function __construct(
        private readonly McpConnectionStore $connectionStore,
        private readonly McpHostKey $hostKey,
        private readonly LogManager $logManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Forget the stored connection (without it, only shows what would be forgotten).')
            ->addOption('new-host-key', null, InputOption::VALUE_NONE, 'Also drop this host\'s MCP connector key file, so the next pairing uses a new key (for a full copy of an installation).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $connection = $this->connectionStore->get();
        $newHostKey = (bool)$input->getOption('new-host-key');
        if (in_array($connection['status'], ['none', 'disconnected'], true)) {
            if ($newHostKey && (bool)$input->getOption('confirm')) {
                $this->renewHostKey($io);
            }
            $io->success('No agent access connection is stored here; nothing to reset.');
            return Command::SUCCESS;
        }
        $io->text([
            sprintf('Stored connection: %s%s.', $connection['status'], $connection['unusableReason'] !== '' ? ' (unusable on this host: ' . $connection['unusableReason'] . ')' : ''),
            'Resetting forgets it in this database only. The AQG MCP Gateway is not contacted: if this is the original',
            'installation, its agents stop reaching it until it is paired again, and the old connection stays listed in',
            'the AQG account until it is disconnected there.',
        ]);
        if (!(bool)$input->getOption('confirm')) {
            $io->note('Dry run. Run again with --confirm to reset.');
            return Command::SUCCESS;
        }
        $previous = $this->connectionStore->resetLocal('cli');
        $this->logManager->getLogger(self::class)->warning('AQG agent access connection reset locally (no Gateway call).', ['previousStatus' => $previous]);
        if ($newHostKey) {
            $this->renewHostKey($io);
        }
        $io->success('The agent access connection was forgotten in this database. No Gateway call was made.');

        return Command::SUCCESS;
    }

    private function renewHostKey(SymfonyStyle $io): void
    {
        if ($this->hostKey->isConfigured()) {
            $io->warning('This host\'s MCP connector key comes from ' . McpHostKey::ENVIRONMENT_VARIABLE . '. If this is a copy of another installation, give it a new value (32 random bytes, base64) before pairing it.');
            return;
        }
        if ($this->hostKey->discardStoredKey()) {
            $this->logManager->getLogger(self::class)->warning('AQG MCP connector key file removed by reset-local --new-host-key.');
            $io->text('The MCP connector key file was removed; the next pairing creates a new one.');
            return;
        }
        $io->text('No MCP connector key file is stored on this host.');
    }
}
