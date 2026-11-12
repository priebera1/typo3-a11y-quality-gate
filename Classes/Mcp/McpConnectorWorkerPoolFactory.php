<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

/**
 * Creates the connector's worker pool (Fable F-02). A service of its own so tests can hand the connector a pool of
 * stand-in worker processes; production uses the TYPO3 CLI the connector was started with.
 */
class McpConnectorWorkerPoolFactory
{
    /**
     * @return list<string>|null the worker command line, or null when this host cannot run worker processes
     */
    public function workerCommand(?string $script): ?array
    {
        return McpConnectorWorkerPool::workerCommandFor($script);
    }

    /**
     * @param list<string> $command
     * @param \Closure(): float $now
     */
    public function create(array $command, string $instanceId, int $size, \Closure $now): McpConnectorWorkerPool
    {
        return new McpConnectorWorkerPool($command, $instanceId, $size, $now);
    }
}
