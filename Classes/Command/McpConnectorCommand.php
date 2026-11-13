<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpAuditRepository;
use Priebera\A11yQualityGate\Mcp\McpCallRunner;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpConnectorClock;
use Priebera\A11yQualityGate\Mcp\McpConnectorWorkerPool;
use Priebera\A11yQualityGate\Mcp\McpConnectorWorkerPoolFactory;
use Priebera\A11yQualityGate\Mcp\McpConsentLedger;
use Priebera\A11yQualityGate\Mcp\McpPairingService;
use Priebera\A11yQualityGate\Mcp\McpScanRequestStore;
use Priebera\A11yQualityGate\Mcp\McpToolExecutor;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Log\LogManager;

/**
 * The outbound connector of Agent access (MCP), atlas 13 · A: opens HTTPS connections to the AQG MCP Gateway (nothing
 * inbound), fetches agent calls, executes each as the token's TYPO3 user (McpToolExecutor) and returns the result.
 * Each poll is the heartbeat. Must run as a supervised service (systemd, Supervisor, container); the Scheduler cannot
 * keep it running.
 *
 * Delivery (Sol audit F1/F7): the calls of one poll are acknowledged first; then, for each call right before it runs,
 * the Gateway's begin check decides again whether it may run (token not revoked or expired, installation and licence
 * active, tool within the token's scopes, call deadline) and hands out the current principal. A call the Gateway
 * refuses is not executed; a call that began is never offered again (a started scan is not replayed; the agent's
 * idempotencyKey finds it). TYPO3 then re-checks the user, site, page and module rights itself (McpToolExecutor).
 *
 * Parallel calls (Fable F-02): with `--workers` > 1 (default 4) the calls run in that many worker processes
 * (McpConnectorWorkerPool, `a11y:mcp:connector:worker`) and each poll asks for as many calls as workers are idle, so one
 * slow call (a scan start, a status refresh) no longer holds back the others until their deadlines pass. The workers
 * run the same McpCallRunner under this process's instance id; `--workers=1`, `--once`, or a host without proc_open runs
 * calls in this process, one at a time, as before.
 *
 * Clone safety (N2): each process names itself with a random instance id; the Gateway lets one process at a time poll
 * for this installation. A call runs only when its token names a consent this installation recorded for that user
 * (McpConsentLedger); otherwise the answer is `consent_unknown` and the Gateway revokes that token.
 */
final class McpConnectorCommand extends Command
{
    private const BACKOFF_SECONDS = [2, 5, 10, 30, 60];
    /** Audit retention and scan-request expiry are applied this often while the connector runs. */
    private const PRUNE_INTERVAL_SECONDS = 3600;

    private const DEFAULT_WORKERS = 4;
    /** Seconds the workers get to finish their current call when the connector stops. */
    private const WORKER_SHUTDOWN_SECONDS = 15.0;

    private bool $stopRequested = false;
    private readonly McpCallRunner $runner;
    private readonly McpConnectorWorkerPoolFactory $poolFactory;

    public function __construct(
        private readonly McpGatewayClient $gatewayClient,
        private readonly McpToolExecutor $toolExecutor,
        private readonly McpAuditRepository $auditRepository,
        private readonly McpPairingService $pairingService,
        private readonly McpScanRequestStore $scanRequests,
        private readonly McpConsentLedger $consentLedger,
        private readonly McpConnectionStore $connectionStore,
        private readonly ExtensionContextService $extensionContextService,
        private readonly LogManager $logManager,
        private readonly McpConnectorClock $clock,
        ?McpCallRunner $runner = null,
        ?McpConnectorWorkerPoolFactory $poolFactory = null,
    ) {
        parent::__construct();
        $this->runner = $runner ?? new McpCallRunner($gatewayClient, $toolExecutor, $auditRepository, $consentLedger, $logManager);
        $this->poolFactory = $poolFactory ?? new McpConnectorWorkerPoolFactory();
    }

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Poll once, execute what arrived and exit (diagnostics).')
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Exit after this many seconds; the supervisor restarts the connector (0 = unlimited).', '3600')
            ->addOption('workers', null, InputOption::VALUE_REQUIRED, sprintf('Agent calls run in parallel, each in its own worker process (%d–%d; 1 = in this process, one at a time).', McpConnectorWorkerPool::MIN_WORKERS, McpConnectorWorkerPool::MAX_WORKERS), (string)self::DEFAULT_WORKERS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $unusable = $this->connectionStore->get()['unusableReason'];
        if ($unusable !== '') {
            $output->writeln(sprintf('<error>The stored agent access connection cannot be used on this host (%s). If this is a copy of another installation, run a11y:mcp:reset-local here; otherwise connect again in Settings › Agent access (MCP). See the Agent access documentation.</error>', $unusable));
            return Command::FAILURE;
        }
        if (!$this->gatewayClient->isConnected()) {
            $output->writeln('<error>This installation is not connected to the AQG MCP Gateway. Connect it in Accessibility › Settings › Agent access (MCP).</error>');
            return Command::FAILURE;
        }
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopRequested = true);
            pcntl_signal(SIGINT, fn () => $this->stopRequested = true);
        }

        $once = (bool)$input->getOption('once');
        $maxRuntime = max(0, (int)$input->getOption('max-runtime'));
        $startedAt = $this->clock->now();
        $version = $this->extensionContextService->getExtensionVersion();
        $host = (string)(gethostname() ?: '');
        if (!$this->pairingService->ensureActivated()) {
            $output->writeln('<error>The AQG MCP Gateway refused to activate this connection (cancelled, replaced or expired). Connect again in Settings › Agent access (MCP).</error>');
            return Command::FAILURE;
        }
        $instanceId = bin2hex(random_bytes(16));
        $this->gatewayClient->useConnectorInstance($instanceId);
        $pool = $once ? null : $this->createPool($input, $instanceId, $output);
        $output->writeln(sprintf('AQG MCP connector %s started on %s%s.', $version, $host, $pool !== null ? sprintf(' (%d workers)', $pool->fill()) : ''));

        try {
            return $this->pollLoop($output, $version, $host, $once, $maxRuntime, $startedAt, $pool);
        } finally {
            // Workers finish their current call (bounded) before the lease is handed over: their answers need it.
            $pool?->shutdown(self::WORKER_SHUTDOWN_SECONDS);
            try {
                $this->gatewayClient->releaseConnectorLease();
            } catch (ApiRequestFailedException) {
                // The lease lapses on its own.
            }
        }
    }

    private function createPool(InputInterface $input, string $instanceId, OutputInterface $output): ?McpConnectorWorkerPool
    {
        $requested = (string)$input->getOption('workers');
        $workers = preg_match('/^[0-9]{1,2}$/', $requested) === 1 ? (int)$requested : self::DEFAULT_WORKERS;
        $workers = max(McpConnectorWorkerPool::MIN_WORKERS, min(McpConnectorWorkerPool::MAX_WORKERS, $workers));
        if ($workers <= 1) {
            return null;
        }
        $script = isset($_SERVER['argv'][0]) && is_string($_SERVER['argv'][0]) ? $_SERVER['argv'][0] : null;
        $command = $this->poolFactory->workerCommand($script);
        if ($command === null) {
            $output->writeln('<comment>Worker processes are not available on this host (proc_open); agent calls run one at a time.</comment>');
            return null;
        }

        return $this->poolFactory->create($command, $instanceId, $workers, fn (): float => $this->clock->now());
    }

    /**
     * Runs until a signal, `--once`, a refusal that needs an administrator, or `--max-runtime` (Sol AQG-MCP-09): the
     * runtime bounds every path — successful polls, Gateway errors, connector conflicts — and no retry wait outlasts it.
     */
    private function pollLoop(OutputInterface $output, string $version, string $host, bool $once, int $maxRuntime, float $startedAt, ?McpConnectorWorkerPool $pool): int
    {
        $failures = 0;
        $prunedAt = null;
        $deadline = $maxRuntime > 0 ? $startedAt + $maxRuntime : null;
        while (!$this->stopRequested) {
            if ($deadline !== null && $this->clock->now() >= $deadline) {
                $output->writeln('AQG MCP connector reached its maximum runtime; the supervisor restarts it.');
                break;
            }
            if ($prunedAt === null || $this->clock->now() - $prunedAt >= self::PRUNE_INTERVAL_SECONDS) {
                $this->auditRepository->prune();
                $this->scanRequests->prune();
                $this->consentLedger->prune();
                $prunedAt = $this->clock->now();
            }
            if ($pool !== null && $pool->isDisabled()) {
                $output->writeln(sprintf('<comment>Worker processes stopped working (%s); agent calls run one at a time in this process.</comment>', $pool->disabledReason()));
                $pool->shutdown(self::WORKER_SHUTDOWN_SECONDS);
                $pool = null;
            }
            $free = null;
            if ($pool !== null) {
                $pool->reap(0.0);
                $pool->fill();
                $free = $pool->idleCount();
                if ($free === 0 && $pool->busyCount() > 0) {
                    // Every ready worker is busy: no poll, so no call waits here for a free worker; the Gateway keeps it
                    // and withdraws it unstarted at its deadline. Busy workers end within their call's deadline plus a
                    // grace, so the gap without a poll (the heartbeat) stays below the Gateway's offline threshold.
                    $pool->reap(0.25);
                    continue;
                }
                if ($free === 0) {
                    // No worker is ready yet (starting, or hanging at start until the pool gives up on them): keep
                    // polling, one call at a time, and run it here, so the heartbeat never stops.
                    $free = 1;
                }
            }
            try {
                $calls = $this->gatewayClient->poll($version, $host, $free);
                $failures = 0;
            } catch (ApiRequestFailedException $exception) {
                if ($exception->apiErrorCode === 'mcp_not_connected') {
                    // Disconnected or re-paired in the Settings tab meanwhile: stop; the supervisor's restart picks up a new connection.
                    $output->writeln('<error>This installation is no longer connected to the AQG MCP Gateway. Connect it in Settings › Agent access (MCP).</error>');
                    return Command::FAILURE;
                }
                if (in_array($exception->httpStatus, [401, 403], true)) {
                    $output->writeln('<error>The AQG MCP Gateway refused this installation (disconnected or revoked). Reconnect it in Settings › Agent access (MCP).</error>');
                    return Command::FAILURE;
                }
                if ($exception->apiErrorCode === 'connector_upgrade_required') {
                    $output->writeln('<error>The AQG MCP Gateway does not accept this connector version. Update the AQG extension.</error>');
                    return Command::FAILURE;
                }
                $conflict = $exception->apiErrorCode === 'connector_conflict';
                if ($conflict) {
                    // Never "disconnect the other one": a copy holds this installation's credential, and a disconnect from
                    // it would end this installation's connection and every agent token.
                    $output->writeln('<error>Another connector process is polling for this installation. Only one may run: stop the second supervisor, or — if it runs on a copy of this TYPO3 installation — stop it there and run a11y:mcp:reset-local on the copy. Do not disconnect the copy.</error>');
                }
                $delay = (float)self::BACKOFF_SECONDS[min($failures, count(self::BACKOFF_SECONDS) - 1)];
                $failures++;
                if ($deadline !== null) {
                    $delay = min($delay, max(0.0, $deadline - $this->clock->now()));
                }
                // I-12: a conflict is an answer of a reachable Gateway; say only that this connector waits.
                $output->writeln($conflict
                    ? sprintf('<comment>The Gateway refused this connector while another one polls (connector_conflict); retrying in %d s.</comment>', (int)ceil($delay))
                    : sprintf('<comment>Gateway not reachable (%s); retrying in %d s.</comment>', $exception->apiErrorCode ?: 'error', (int)ceil($delay)));
                if ($once) {
                    return Command::FAILURE;
                }
                if ($this->stopRequested) {
                    continue;
                }
                $this->clock->sleep($delay);
                continue;
            }

            foreach ($this->acknowledged($calls, $output) as $call) {
                if ($this->stopRequested) {
                    break;
                }
                if ($pool !== null && $pool->dispatch($call, $this->callDeadline($call))) {
                    continue;
                }
                // No worker took it (none idle, or one failed before the call began): run it here.
                $this->handleCall($call, $output);
            }

            if ($once) {
                break;
            }
        }

        $output->writeln('AQG MCP connector stopped.');

        return Command::SUCCESS;
    }

    /**
     * Confirms receipt of a poll's calls; only acknowledged ones run here (an unacknowledged claim may already be with
     * another poll after its lease ended).
     *
     * @param list<array<string, mixed>> $calls
     * @return list<array<string, mixed>>
     */
    private function acknowledged(array $calls, OutputInterface $output): array
    {
        $claims = [];
        foreach ($calls as $call) {
            $id = (string)($call['id'] ?? '');
            $claimId = (string)($call['claimId'] ?? '');
            if ($id !== '' && $claimId !== '') {
                $claims[] = ['id' => $id, 'claimId' => $claimId];
            }
        }
        if ($claims === []) {
            return [];
        }
        try {
            $acknowledged = array_flip($this->gatewayClient->acknowledge($claims));
        } catch (ApiRequestFailedException $exception) {
            $output->writeln(sprintf('<comment>Calls not acknowledged (%s); the Gateway offers them again.</comment>', $exception->apiErrorCode ?: 'error'), OutputInterface::VERBOSITY_VERBOSE);
            return [];
        }

        return array_values(array_filter($calls, static fn (array $call): bool => isset($acknowledged[(string)($call['id'] ?? '')])));
    }

    /**
     * @param array<string, mixed> $call
     */
    private function handleCall(array $call, OutputInterface $output): void
    {
        $outcome = $this->runner->run($call);
        if ($outcome !== 'executed') {
            $output->writeln(sprintf('<comment>%s: %s.</comment>', (string)($call['tool'] ?? ''), str_replace('_', ' ', $outcome)), OutputInterface::VERBOSITY_VERBOSE);
        }
    }

    /**
     * The call's deadline on this process's clock (the Gateway sends it as an ISO time); a worker still busy well past
     * it is replaced.
     *
     * @param array<string, mixed> $call
     */
    private function callDeadline(array $call): float
    {
        $deadline = is_string($call['deadline'] ?? null) ? strtotime($call['deadline']) : false;
        // The Gateway always sends the deadline (agent wait, about 8 s); without one, assume that wait.
        $remaining = $deadline === false ? 10.0 : max(0.0, min(10.0, $deadline - time()));

        return $this->clock->now() + $remaining;
    }

    /**
     * Exception text for the local log (Sol post-review): bounded, without markup (a crawler or scanned page can put
     * HTML into an upstream error), and with AQG credential shapes, bearer tokens and long secrets masked.
     */
    public static function loggableMessage(string $message): string
    {
        // Markup and whatever follows it may be page content: dropped, not stripped.
        $markup = strpos($message, '<');
        if ($markup !== false) {
            $message = substr($message, 0, $markup) . ' [markup removed]';
        }
        $message = (string)preg_replace('/\baqg[a-z]{0,3}_[A-Za-z0-9_-]{8,}/', 'aqg*_[redacted]', $message);
        $message = (string)preg_replace('/(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 [redacted]', $message);
        $message = (string)preg_replace('/\b[A-Za-z0-9_\-+\/]{40,}={0,2}/', '[redacted]', $message);
        $message = trim((string)preg_replace('/\s+/u', ' ', $message));

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 299) . '…' : $message;
    }
}
