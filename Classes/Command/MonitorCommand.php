<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Monitoring\InvalidMonitoringTargetException;
use Priebera\A11yQualityGate\Monitoring\MonitoringTargetResolver;
use Priebera\A11yQualityGate\Monitoring\MonitoringTaskValidator;
use Priebera\A11yQualityGate\Service\RemoteMonitoringService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Scheduled frontend monitoring with regression notifications (PRO and Agency).
 *
 * Each run scans the site, compares the result with the last complete monitoring scan and mails the
 * recipients only when new or worse issues appear, or when the scan was incomplete or failed. Use it from cron
 * or CI; in the TYPO3 Scheduler the "Accessibility monitoring" task runs the same monitoring with site and
 * language selectors (Scheduler\MonitoringTask). Registered in Configuration/Services.yaml only; see the
 * note there before adding #[AsCommand].
 */
final class MonitorCommand extends Command
{
    public function __construct(
        private readonly RemoteMonitoringService $remoteMonitoringService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly MonitoringTargetResolver $monitoringTargetResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('site', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Site identifier to monitor; repeat for several sites (Agency), or "all"')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'sys_language_uid of the site language to scan', '0')
            ->addOption('notify', null, InputOption::VALUE_REQUIRED, 'Comma-separated e-mail addresses that receive regression notifications', '')
            ->addOption('max-pages', null, InputOption::VALUE_REQUIRED, 'Maximum pages per monitoring scan (1-1000)', '500')
            ->addOption('max-wait', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for the scan before the next run picks it up', '1200')
            ->addOption('backend-url', null, InputOption::VALUE_REQUIRED, 'Scheme and host of the TYPO3 backend for the link in notifications, e.g. https://cms.example.org', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        [$sites, $unknown] = $this->resolveSites((array)$input->getOption('site'));
        if ($unknown !== []) {
            $io->error(sprintf('Unknown site: %s. Configured sites: %s.', implode(', ', $unknown), implode(', ', array_column($this->monitoringTargetResolver->siteChoices(), 'identifier'))));
            return Command::INVALID;
        }
        if ($sites === []) {
            $io->error('Name at least one configured site with --site=<identifier>, or --site=all.');
            return Command::INVALID;
        }

        $recipients = MonitoringTaskValidator::parseRecipients((string)$input->getOption('notify'));
        $invalidRecipients = array_values(array_filter($recipients, static fn(string $address): bool => !GeneralUtility::validEmail($address)));
        if ($invalidRecipients !== []) {
            $io->error(sprintf('Not a valid e-mail address: %s.', implode(', ', $invalidRecipients)));
            return Command::INVALID;
        }
        $languageUid = max(0, (int)$input->getOption('language'));
        $maxPages = max(MonitoringTaskValidator::MAX_PAGES_MIN, min(MonitoringTaskValidator::MAX_PAGES_MAX, (int)$input->getOption('max-pages')));
        $maxWait = max(0, min(MonitoringTaskValidator::MAX_WAIT_MAX, (int)$input->getOption('max-wait')));
        $backendUrl = trim((string)$input->getOption('backend-url'));

        // Several client sites are the Agency tier; PRO monitors its site.
        if (count($sites) > 1) {
            foreach ($sites as $site) {
                if (!$this->remoteMonitoringService->resolveEntitlement($site)['multiSite']) {
                    $io->error('Monitoring several sites in one run needs an AQG Agency licence. Monitor one site per run with PRO.');
                    return Command::FAILURE;
                }
            }
        }

        $exitCode = Command::SUCCESS;
        foreach ($sites as $site) {
            try {
                // A language the site does not have would otherwise scan the site's default page set.
                $this->monitoringTargetResolver->resolve($site->getIdentifier(), $languageUid);
            } catch (InvalidMonitoringTargetException $exception) {
                $io->error(sprintf('%s: %s', $site->getIdentifier(), $exception->getMessage()));
                $exitCode = Command::FAILURE;
                continue;
            }

            try {
                $result = $this->remoteMonitoringService->run($site, $languageUid, $maxPages, $maxWait, $recipients, $backendUrl);
            } catch (\Throwable $exception) {
                $io->error(sprintf('%s: the monitoring scan could not be started (%s).', $site->getIdentifier(), $exception::class));
                $exitCode = Command::FAILURE;
                continue;
            }

            $io->writeln(sprintf(
                '%s: %s%s%s',
                $site->getIdentifier(),
                $this->describe($result['outcome']),
                $result['summary'] !== [] ? sprintf(' (new %d, worse %d, fixed %d, pages compared %d, not checked %d)', $result['summary']['newIssueTypes'] ?? 0, $result['summary']['regressedIssueTypes'] ?? 0, $result['summary']['fixedIssueTypes'] ?? 0, $result['summary']['comparedPages'] ?? 0, $result['summary']['coverageGaps'] ?? 0) : '',
                $result['notified'] ? ' — notification sent' : ''
            ));

            if ($result['outcome'] === RemoteMonitoringService::OUTCOME_NOT_ENTITLED) {
                $exitCode = Command::FAILURE;
            }
        }

        return $exitCode;
    }

    /**
     * @param list<string> $identifiers
     * @return array{0:list<Site>,1:list<string>} the configured sites, and the identifiers that name none
     */
    private function resolveSites(array $identifiers): array
    {
        $identifiers = array_values(array_filter(array_map('trim', $identifiers)));
        if (in_array('all', $identifiers, true)) {
            return [array_values($this->siteResolutionService->getAllSites()), []];
        }

        $sites = [];
        $unknown = [];
        foreach ($identifiers as $identifier) {
            $site = $this->siteResolutionService->resolveSiteByIdentifier($identifier);
            if ($site instanceof Site) {
                $sites[] = $site;
            } else {
                $unknown[] = $identifier;
            }
        }

        return [$sites, $unknown];
    }

    private function describe(string $outcome): string
    {
        return match ($outcome) {
            RemoteMonitoringService::OUTCOME_NOT_ENTITLED => 'monitoring needs a valid AQG PRO or Agency licence for this site',
            RemoteMonitoringService::OUTCOME_BUSY => 'another frontend scan of this site is running; nothing started',
            RemoteMonitoringService::OUTCOME_WAITING => 'the monitoring scan is still running; the next run evaluates it',
            RemoteMonitoringService::OUTCOME_NO_BASELINE => 'first complete monitoring scan stored as the baseline',
            RemoteMonitoringService::OUTCOME_CLEAR => 'no new or worse issues on the pages checked in both scans',
            RemoteMonitoringService::OUTCOME_REGRESSION => 'new or worse issues since the baseline scan',
            RemoteMonitoringService::OUTCOME_INCOMPLETE => 'incomplete: pages of the baseline were not checked, so this is no all-clear',
            RemoteMonitoringService::OUTCOME_FAILED => 'the monitoring scan failed',
            default => $outcome,
        };
    }
}
