<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Service\InstallationIdentityService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows this installation's AQG identity and, deliberately, gives a database clone a new one.
 *
 * The identity (sys_registry) is what the AQG service knows an installation by: its Agency project, crawler subject,
 * scan history and evidence, and its Free Remote Preview allowance. A clone of an activated database presents the
 * same identity, so a new client site built from it would share the source client's project and history. Only an
 * operator who knows the copy is a separate installation can say so: `--new-installation` must name the current
 * fingerprint (`--confirm`), which is printed by this command on that very installation, so the operation cannot run
 * by accident, from a copied script or against the wrong system. Never schedulable (Services.yaml).
 *
 * Registered in Configuration/Services.yaml only; see the note there before adding #[AsCommand].
 */
final class InstallationIdentityCommand extends Command
{
    public function __construct(
        private readonly InstallationIdentityService $installationIdentityService,
        private readonly ProCacheManager $proCacheManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('new-installation', null, InputOption::VALUE_NONE, 'Give this installation a new AQG identity: only for a database copy that is a separate TYPO3 installation (a new client project), never for a deployment, restore or staging copy')
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'The current identity fingerprint, as this command prints it on this installation; required with --new-installation', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $installationId = $this->installationIdentityService->findInstallationId();
        $fingerprint = $installationId !== null ? InstallationIdentityService::fingerprint($installationId) : null;

        if (!(bool)$input->getOption('new-installation')) {
            $this->describe($io, $fingerprint);
            return Command::SUCCESS;
        }

        if ($fingerprint === null) {
            $io->note('This installation has no AQG identity yet. It gets a new one with its first licence or Free Remote Preview request; nothing to change.');
            return Command::SUCCESS;
        }

        $confirmation = strtolower(trim((string)$input->getOption('confirm')));
        if (!hash_equals($fingerprint, $confirmation)) {
            $io->error($confirmation === ''
                ? 'Nothing changed: --new-installation needs --confirm with the current identity fingerprint.'
                : 'Nothing changed: --confirm does not match the identity fingerprint of this installation.');
            $this->explainConsequences($io);
            $io->text([
                'Run on the copy that is a separate installation, and only there:',
                sprintf('  vendor/bin/typo3 a11y:installation:identity --new-installation --confirm=%s', $fingerprint),
            ]);
            return Command::FAILURE;
        }

        $result = $this->installationIdentityService->assignNewInstallationId();
        // Licence answers, scanner tokens and Free/Trial usage cached for the previous identity must not be used by
        // the new one: a scanner token of the source project would keep reading the source client's history.
        $this->proCacheManager->flushAll();
        $this->logger->warning('AQG installation identity replaced on operator request (new installation).', [
            'previousFingerprint' => $result['previousFingerprint'],
            'fingerprint' => $result['fingerprint'],
        ]);

        $io->success(sprintf(
            'This installation has a new AQG identity: %s (previously %s).',
            $result['fingerprint'],
            (string)$result['previousFingerprint'],
        ));
        $io->text([
            'Cached licence answers and scanner tokens were cleared. The next licence request registers this installation:',
            '  - Agency: as a new project of the licence (one project slot), with its own scan history and evidence.',
            '    Its first public domain is activated automatically; activate further ones in the Licence tab or the portal.',
            '  - PRO and Trial cover one installation: this copy needs its own licence (it is refused as project_mismatch).',
            'The source installation keeps its identity, project and history unchanged.',
            'Scan results stored in this database before the change are local copies of the source installation; their',
            'remote details stay with the source project and are no longer available here.',
        ]);

        return Command::SUCCESS;
    }

    private function describe(SymfonyStyle $io, ?string $fingerprint): void
    {
        $io->title('AQG installation identity');
        $io->definitionList(
            ['Fingerprint' => $fingerprint ?? 'not assigned yet (created with the first licence or Free Remote Preview request)'],
            ['Last replaced' => $this->describeReassignment()],
        );
        $io->text([
            'Two TYPO3 installations with the same fingerprint are one installation for the AQG service: one Agency',
            'project with one scan history. That is right for staging copies, restored backups and moved servers of the',
            'same site. It is wrong for a database copy that became another client\'s installation; give that copy (and',
            'only that copy) a new identity:',
            sprintf('  vendor/bin/typo3 a11y:installation:identity --new-installation --confirm=%s', $fingerprint ?? '<fingerprint>'),
        ]);
    }

    private function describeReassignment(): string
    {
        $reassignment = $this->installationIdentityService->findLastReassignment();
        if ($reassignment === null) {
            return 'never';
        }

        return sprintf(
            '%s (previous fingerprint %s)',
            gmdate('Y-m-d H:i:s', $reassignment['reassignedAt']) . ' UTC',
            $reassignment['previousFingerprint'] ?? 'none',
        );
    }

    private function explainConsequences(SymfonyStyle $io): void
    {
        $io->text([
            'A new identity makes this installation a TYPO3 installation the AQG service has never seen:',
            '  - Agency: it becomes a new project (one more project slot) with an empty scan history; it can no longer',
            '    read the scans, history or evidence of the project it was copied from.',
            '  - PRO and Trial: the licence no longer recognises it (project_mismatch).',
            '  - Free Remote Preview: the site\'s proof changes with the identity.',
            'The previous identity is not kept and cannot be restored by AQG (only from a database backup).',
            'Do not use it after a deployment, a domain or site change, a server move, a restore, or for a staging copy:',
            'those are the same installation and must keep their identity.',
        ]);
    }
}
