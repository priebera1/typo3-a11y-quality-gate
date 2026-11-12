<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Scan\ScanResult;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * `a11y:scan`. Registered in Configuration/Services.yaml only; see the note there before adding #[AsCommand].
 */
final class ScanCommand extends Command
{
    public function __construct(
        private readonly ScanOrchestrator $scanOrchestrator,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ScanStatusService $scanStatusService,
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
                'sys_language_uid to scan, or all',
                'all',
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
        $languageOption = trim((string)$input->getOption('language'));
        $changedOnly = (bool)$input->getOption('changed-only');

        if ($rootPid === null && $pageUid === null) {
            $io->error('Provide either --root-pid or --page-uid.');

            return Command::FAILURE;
        }

        if ($rootPid !== null && $pageUid !== null) {
            $io->error('--root-pid and --page-uid cannot be combined.');

            return Command::FAILURE;
        }

        // ML-04: `all` or a language id of the page's site. A language code ("de") used to be cast to 0 and scanned
        // English; a language the site does not have "completed" and stored findings nobody could open.
        // `-1` (the stored "all languages" value) and a bare `--language` keep meaning the default, all languages.
        if (in_array(strtolower($languageOption), ['all', '-1', ''], true)) {
            $languageUid = -1;
        } elseif (preg_match('/^\d{1,9}$/', $languageOption) === 1) {
            $languageUid = (int)$languageOption;
            $languageError = $this->languageErrorForSite((int)($pageUid ?? $rootPid), $languageUid);
            if ($languageError !== '') {
                $io->error($languageError);

                return Command::FAILURE;
            }
        } else {
            $io->error(sprintf('--language must be "all" or a sys_language_uid of the site (a number), "%s" given.', $languageOption));

            return Command::FAILURE;
        }

        // NEW-06 / review P2-1: the CLI scan takes the same installation-wide lock as the backend scans.
        $scanLock = $this->scanStatusService->acquireScanLock();
        if ($scanLock === null) {
            $io->error('A content scan is already running. Try again when it has finished.');

            return Command::FAILURE;
        }
        $keepLock = fn (): bool => !$this->scanStatusService->renewScanLock($scanLock);

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
                    shouldCancel: $keepLock,
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
                    shouldCancel: $keepLock,
                );
            }

            if (in_array(ScanResult::WARNING_NO_SCAN_FIELDS, array_column($result->warnings, 'code'), true)) {
                // F-05: not a clean result — nothing was checked.
                $io->warning($result->toSummaryString() . "\nNo content fields are enabled for scanning, so no content was checked. Enable fields in Settings › Scan fields.");
            } else {
                $io->success($result->toSummaryString());
            }

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
        } finally {
            $this->scanStatusService->releaseScanLock($scanLock);
        }
    }

    private function languageErrorForSite(int $pageUid, int $languageUid): string
    {
        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        if ($site === null) {
            // A page outside every site has only the default language.
            return $languageUid === 0 ? '' : sprintf('Page %d belongs to no site: use --language=all or --language=0.', $pageUid);
        }
        foreach ($site->getLanguages() as $language) {
            if ($language->getLanguageId() === $languageUid) {
                return '';
            }
        }

        return sprintf('Language %d is not a language of site "%s". Use --language=all or one of: %s.',
            $languageUid,
            $site->getIdentifier(),
            implode(', ', array_map(static fn ($language): int => $language->getLanguageId(), $site->getLanguages()))
        );
    }
}
