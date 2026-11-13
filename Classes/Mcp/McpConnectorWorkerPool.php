<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

/**
 * Parallel execution of agent calls for the connector (Fable F-02).
 *
 * The connector used to run the calls of a poll one after another, while each call's deadline (the agent's wait,
 * about 8 s) runs from the moment the agent sent it: with several agents, or one agent calling tools in parallel, later
 * calls waited past their deadline without running. The pool keeps a fixed number of worker processes
 * (`a11y:mcp:connector:worker`), each a TYPO3 CLI process booted once, and hands each one call at a time; the connector
 * polls only for as many calls as it has idle workers, so a call it cannot start soon stays at the Gateway, which
 * withdraws it unstarted at its deadline (`installation_busy`, nothing ran, scan quota given back).
 *
 * What does not change: every call still begins at the Gateway first (the authorization gate; a call begins at most
 * once), runs under the principal the Gateway hands out at begin, and is answered by the process that began it. All
 * workers name themselves with the connector's own instance id, so they act inside its lease, and nothing is replayed:
 * a worker that dies or overruns is replaced, and its call is lost exactly as when the single connector process died.
 *
 * Protocol (one JSON object per line): connector → worker `{"instanceId": …}` once, then `{"call": {…}}`; worker →
 * connector `AQG-MCP-WORKER {"ready": true}`, then `AQG-MCP-WORKER {"done": "<call id>", "outcome": …}` per call. Any
 * other output line is ignored. Closing the worker's stdin ends it after its current call.
 */
final class McpConnectorWorkerPool
{
    public const LINE_PREFIX = 'AQG-MCP-WORKER ';
    public const MIN_WORKERS = 1;
    public const MAX_WORKERS = 8;

    /**
     * A worker that does not report ready within this many seconds is replaced. While no worker is idle the connector
     * does not poll (its heartbeat), so this and deadline + CALL_GRACE stay below the Gateway's 45 s offline threshold.
     */
    private const STARTUP_TIMEOUT = 30.0;
    /** A call runs at most until its deadline (about 8 s after the agent sent it) plus this; a worker still busy then is replaced. */
    private const CALL_GRACE = 30.0;
    /**
     * Workers that end without ever reporting ready (they exit at start, or hang past STARTUP_TIMEOUT), this many in a
     * row, disable the pool: the connector then runs calls itself instead of waiting for workers that never come.
     */
    private const START_FAILURE_LIMIT = 3;

    /** @var array<int, array{process: resource, stdin: resource, stdout: resource, buffer: string, state: string, startedAt: float, busyUntil: float, callId: string}> */
    private array $workers = [];
    private int $nextWorkerId = 0;
    private int $startFailures = 0;
    private bool $disabled = false;
    private string $disabledReason = '';

    /**
     * @param list<string> $command the worker command line (PHP binary, TYPO3 CLI script, command name)
     * @param \Closure(): float $now
     */
    public function __construct(
        private readonly array $command,
        private readonly string $instanceId,
        private readonly int $size,
        private readonly \Closure $now,
    ) {
    }

    /**
     * The worker command for the TYPO3 CLI script this connector was started with (vendor/bin/typo3, or
     * typo3/sysext/core/bin/typo3 in classic mode), run by the same PHP binary. Null when it cannot be determined or
     * proc_open is not available: the connector then runs calls itself, one at a time, as before.
     *
     * @return list<string>|null
     */
    public static function workerCommandFor(?string $script): ?array
    {
        if (!function_exists('proc_open') || !function_exists('stream_select') || PHP_BINARY === '' || $script === null || $script === '') {
            return null;
        }
        $path = realpath($script);
        // Only TYPO3's own CLI entry point (vendor/bin/typo3, typo3/sysext/core/bin/typo3, each named typo3 after
        // resolving links): any other script that started this command (a test runner) is never run as a worker.
        if ($path === false || !is_file($path) || basename($path) !== 'typo3') {
            return null;
        }

        return [PHP_BINARY, $path, 'a11y:mcp:connector:worker'];
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function disabledReason(): string
    {
        return $this->disabledReason;
    }

    /** Starts missing workers; returns how many are running (starting or ready). */
    public function fill(): int
    {
        while (!$this->disabled && count($this->workers) < $this->size) {
            if (!$this->spawn()) {
                break;
            }
        }

        return count($this->workers);
    }

    public function idleCount(): int
    {
        return count(array_filter($this->workers, static fn (array $worker): bool => $worker['state'] === 'idle'));
    }

    public function busyCount(): int
    {
        return count(array_filter($this->workers, static fn (array $worker): bool => $worker['state'] === 'busy'));
    }

    /**
     * Hands a call to an idle worker. False when none is idle or the worker could not take it (it is then replaced); the
     * call has not begun at the Gateway in either case, so the connector may run it itself.
     *
     * @param array<string, mixed> $call
     * @param float $deadline the call's deadline as this clock reads it
     */
    public function dispatch(array $call, float $deadline): bool
    {
        foreach ($this->workers as $id => $worker) {
            if ($worker['state'] !== 'idle') {
                continue;
            }
            if (!$this->writeLine($worker['stdin'], ['call' => $call])) {
                $this->retire($id, false);
                return false;
            }
            $this->workers[$id]['state'] = 'busy';
            $this->workers[$id]['callId'] = (string)($call['id'] ?? '');
            $this->workers[$id]['busyUntil'] = $deadline + self::CALL_GRACE;

            return true;
        }

        return false;
    }

    /**
     * Reads what the workers reported, waiting at most $timeout seconds for the first line, and replaces workers that
     * exited, hung during start-up or overran a call. Returns the ids of the calls that finished.
     *
     * @return list<string>
     */
    public function reap(float $timeout): array
    {
        $finished = [];
        $streams = [];
        foreach ($this->workers as $id => $worker) {
            $streams[$id] = $worker['stdout'];
        }
        if ($streams !== []) {
            $read = array_values($streams);
            $write = null;
            $except = null;
            $seconds = (int)floor(max(0.0, $timeout));
            $microseconds = (int)round((max(0.0, $timeout) - $seconds) * 1_000_000);
            if (@stream_select($read, $write, $except, $seconds, $microseconds) > 0) {
                foreach ($read as $stream) {
                    $id = array_search($stream, $streams, true);
                    if ($id !== false) {
                        array_push($finished, ...$this->readWorker((int)$id));
                    }
                }
            }
        }
        $now = ($this->now)();
        foreach ($this->workers as $id => $worker) {
            $status = proc_get_status($worker['process']);
            if (!$status['running']) {
                // Whatever it wrote before exiting still counts.
                array_push($finished, ...$this->readWorker($id));
                $this->retire($id, false);
                continue;
            }
            $hung = ($worker['state'] === 'starting' && $now - $worker['startedAt'] > self::STARTUP_TIMEOUT)
                || ($worker['state'] === 'busy' && $now > $worker['busyUntil']);
            if ($hung) {
                $this->retire($id, true);
            }
        }

        return $finished;
    }

    /**
     * Ends every worker: stdin closed (each finishes its current call), then terminated after $timeout seconds.
     */
    public function shutdown(float $timeout): void
    {
        foreach ($this->workers as $worker) {
            if (is_resource($worker['stdin'])) {
                fclose($worker['stdin']);
            }
        }
        // Wall-clock time: the stop must end within $timeout whatever clock the connector runs on.
        $until = microtime(true) + max(0.0, $timeout);
        while ($this->workers !== [] && microtime(true) < $until) {
            foreach ($this->workers as $id => $worker) {
                if (!proc_get_status($worker['process'])['running']) {
                    $this->closeWorker($id);
                }
            }
            if ($this->workers !== []) {
                usleep(50_000);
            }
        }
        foreach (array_keys($this->workers) as $id) {
            $this->retire($id, true, false);
        }
    }

    private function spawn(): bool
    {
        $stderr = defined('STDERR') ? STDERR : ['file', '/dev/null', 'w'];
        $pipes = [];
        $process = @proc_open($this->command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr], $pipes);
        if (!is_resource($process)) {
            $this->disable('workers_cannot_start');
            return false;
        }
        stream_set_blocking($pipes[1], false);
        $id = $this->nextWorkerId++;
        $this->workers[$id] = [
            'process' => $process,
            'stdin' => $pipes[0],
            'stdout' => $pipes[1],
            'buffer' => '',
            'state' => 'starting',
            'startedAt' => ($this->now)(),
            'busyUntil' => 0.0,
            'callId' => '',
        ];
        if (!$this->writeLine($pipes[0], ['instanceId' => $this->instanceId])) {
            $this->retire($id, true);
            return false;
        }

        return true;
    }

    /**
     * @return list<string> finished call ids
     */
    private function readWorker(int $id): array
    {
        if (!isset($this->workers[$id])) {
            return [];
        }
        $chunk = stream_get_contents($this->workers[$id]['stdout']);
        if (is_string($chunk)) {
            // Bounded: a worker never writes more than a line per call; anything beyond 64 KiB unread is dropped.
            $this->workers[$id]['buffer'] = substr($this->workers[$id]['buffer'] . $chunk, -65536);
        }
        $finished = [];
        while (($newline = strpos($this->workers[$id]['buffer'], "\n")) !== false) {
            $line = substr($this->workers[$id]['buffer'], 0, $newline);
            $this->workers[$id]['buffer'] = substr($this->workers[$id]['buffer'], $newline + 1);
            if (!str_starts_with($line, self::LINE_PREFIX)) {
                continue;
            }
            try {
                $message = json_decode(substr($line, strlen(self::LINE_PREFIX)), true, 4, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (!is_array($message)) {
                continue;
            }
            if (($message['ready'] ?? false) === true && $this->workers[$id]['state'] === 'starting') {
                $this->workers[$id]['state'] = 'idle';
                $this->startFailures = 0;
            } elseif (is_string($message['done'] ?? null) && $this->workers[$id]['state'] === 'busy' && $message['done'] === $this->workers[$id]['callId']) {
                $finished[] = $message['done'];
                $this->workers[$id]['state'] = 'idle';
                $this->workers[$id]['callId'] = '';
            }
        }

        return $finished;
    }

    /**
     * @param array<string, mixed> $message
     * @param resource $stream
     */
    private function writeLine($stream, array $message): bool
    {
        try {
            $line = json_encode($message, McpCallRunner::JSON_FLAGS) . "\n";
        } catch (\JsonException) {
            return false;
        }

        return is_resource($stream) && @fwrite($stream, $line) === strlen($line) && @fflush($stream);
    }

    private function retire(int $id, bool $terminate, bool $count = true): void
    {
        if (!isset($this->workers[$id])) {
            return;
        }
        if ($count && $this->workers[$id]['state'] === 'starting' && ++$this->startFailures >= self::START_FAILURE_LIMIT) {
            $this->disable('workers_exit_at_start');
        }
        if ($terminate) {
            @proc_terminate($this->workers[$id]['process'], defined('SIGKILL') ? SIGKILL : 9);
        }
        $this->closeWorker($id);
    }

    private function closeWorker(int $id): void
    {
        $worker = $this->workers[$id];
        unset($this->workers[$id]);
        foreach ([$worker['stdin'], $worker['stdout']] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        @proc_close($worker['process']);
    }

    private function disable(string $reason): void
    {
        $this->disabled = true;
        $this->disabledReason = $reason;
    }
}
