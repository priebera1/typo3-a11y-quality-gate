<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Command;

use Priebera\A11yQualityGate\Mcp\Http\McpGatewayClient;
use Priebera\A11yQualityGate\Mcp\McpCallRunner;
use Priebera\A11yQualityGate\Mcp\McpConnectionStore;
use Priebera\A11yQualityGate\Mcp\McpConnectorWorkerPool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * A worker process of the Agent access connector (Fable F-02; McpConnectorWorkerPool). Started only by
 * `a11y:mcp:connector`, never by hand: it reads its connector's instance id and then one call per line from stdin and
 * runs each through McpCallRunner — the Gateway's begin check first, then TYPO3's own consent and permission checks —
 * exactly as the connector process does. It holds no lease of its own and polls nothing: a call reaches it only after
 * the connector acknowledged it, and runs only if the Gateway begins it for this installation's connector secret.
 */
final class McpConnectorWorkerCommand extends Command
{
    private bool $stopRequested = false;

    public function __construct(
        private readonly McpCallRunner $runner,
        private readonly McpGatewayClient $gatewayClient,
        private readonly McpConnectionStore $connectionStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->connectionStore->get()['unusableReason'] !== '' || !$this->gatewayClient->isConnected()) {
            return Command::FAILURE;
        }
        if (function_exists('pcntl_async_signals')) {
            // A stop of the whole process group (Ctrl-C, a supervisor's group stop) lets the current call finish; the
            // connector closes stdin to end the worker.
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn () => $this->stopRequested = true);
            pcntl_signal(SIGINT, fn () => $this->stopRequested = true);
        }
        // The connector's pipe; a console input stream when one is set (tests).
        $stdin = $input instanceof StreamableInputInterface && is_resource($input->getStream())
            ? $input->getStream()
            : (defined('STDIN') ? STDIN : fopen('php://stdin', 'rb'));
        if (!is_resource($stdin)) {
            return Command::FAILURE;
        }
        $hello = $this->readMessage($stdin);
        $instanceId = is_string($hello['instanceId'] ?? null) ? $hello['instanceId'] : '';
        try {
            $this->gatewayClient->useConnectorInstance($instanceId);
        } catch (\InvalidArgumentException) {
            return Command::FAILURE;
        }
        $this->report($output, ['ready' => true]);

        while (!$this->stopRequested) {
            $message = $this->readMessage($stdin);
            if ($message === null) {
                break;
            }
            $call = is_array($message['call'] ?? null) ? $message['call'] : null;
            if ($call === null || !is_string($call['id'] ?? null)) {
                continue;
            }
            $outcome = $this->runner->run($call);
            $this->report($output, ['done' => $call['id'], 'outcome' => $outcome]);
        }

        return Command::SUCCESS;
    }

    /**
     * @param resource $stdin
     * @return array<string, mixed>|null null at end of input
     */
    private function readMessage($stdin): ?array
    {
        while (true) {
            $line = fgets($stdin, 131072);
            if ($line === false) {
                return feof($stdin) || $this->stopRequested ? null : [];
            }
            try {
                $decoded = json_decode(trim($line), true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            return is_array($decoded) ? $decoded : [];
        }
    }

    /**
     * @param array<string, mixed> $message
     */
    private function report(OutputInterface $output, array $message): void
    {
        $output->writeln(McpConnectorWorkerPool::LINE_PREFIX . json_encode($message, McpCallRunner::JSON_FLAGS), OutputInterface::OUTPUT_RAW);
    }
}
