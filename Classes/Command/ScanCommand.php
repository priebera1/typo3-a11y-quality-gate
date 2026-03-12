<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'a11y:scan',
    description: 'Scan TYPO3 content for accessibility issues.',
)]
final class ScanCommand extends Command
{
    public function __construct(
        private readonly ScanOrchestrator $scanOrchestrator,
        private readonly SiteResolutionService $siteResolutionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'root-pid',
                null,
                InputOption::VALUE_REQUIRED,
                'Root page UID for subtree scan',
            )
            ->addOption(
                'depth',
                null,
                InputOption::VALUE_OPTIONAL,
                'Maximum page tree depth',
                99,
            )
            ->addOption(
                'page-uid',
                null,
                InputOption::VALUE_REQUIRED,
                'Scan a single page by UID',
            )
            ->addOption(
                'language',
                null,
                InputOption::VALUE_OPTIONAL,
                'sys_language_uid to scan',
                -1,
            )
            ->addOption(
                'changed-only',
                null,
                InputOption::VALUE_NONE,
                'Process only changed content',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $rootPid = $input->getOption('root-pid') !== null ? (int)$input->getOption('root-pid') : null;
        $pageUid = $input->getOption('page-uid') !== null ? (int)$input->getOption('page-uid') : null;
        $depth = (int)$input->getOption('depth');
        $languageUid = (int)$input->getOption('language');
        $changedOnly = (bool)$input->getOption('changed-only');

        if ($rootPid === null && $pageUid === null) {
            $io->error('Provide either --root-pid or --page-uid.');

            return Command::FAILURE;
        }

        if ($rootPid !== null && $pageUid !== null) {
            $io->error('--root-pid and --page-uid cannot be combined.');

            return Command::FAILURE;
        }

        try {
            if ($pageUid !== null) {
                $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierFromPageId($pageUid);

                $io->section(sprintf(
                    'Scanning page uid=%d on site "%s"%s',
                    $pageUid,
                    $siteIdentifier,
                    $changedOnly ? ' [changed-only]' : ''
                ));

                $result = $this->scanOrchestrator->scanPage(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $pageUid,
                    languageUid: $languageUid,
                    changedOnly: $changedOnly,
                );
            } else {
                $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierFromPageId($rootPid);

                $io->section(sprintf(
                    'Scanning subtree from pid=%d (depth=%d) on site "%s"%s',
                    $rootPid,
                    $depth,
                    $siteIdentifier,
                    $changedOnly ? ' [changed-only]' : ''
                ));

                $result = $this->scanOrchestrator->scanSubtree(
                    siteIdentifier: $siteIdentifier,
                    rootPid: $rootPid,
                    depth: $depth,
                    languageUid: $languageUid,
                    changedOnly: $changedOnly,
                );
            }

            $io->success($result->toSummaryString());

            if ($result->issuesNew > 0) {
                $io->note(sprintf(
                    '%d new issue(s) found. Open the Accessibility backend module to review them.',
                    $result->issuesNew
                ));
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error(sprintf('Scan failed: %s', $e->getMessage()));

            if ($output->isVerbose()) {
                $io->text($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
