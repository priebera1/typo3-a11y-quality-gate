<?php

declare(strict_types=1);

// Stand-in for `typo3 a11y:mcp:connector:worker` in McpConnectorWorkerPoolTest: speaks the worker protocol
// (McpConnectorWorkerPool) without TYPO3. Behaviour per call tool: "sleep:<ms>", "crash", "hang", "noise".
// Writes the instance id it was given and every call id it finished to the log file named in argv[1].
$log = $argv[1] ?? '';
$mode = $argv[2] ?? '';
if ($mode === 'exit-at-start') {
    exit(3);
}
$hello = json_decode((string)fgets(STDIN), true);
file_put_contents($log, 'instance ' . ($hello['instanceId'] ?? '') . "\n", FILE_APPEND);
if ($mode === 'never-ready') {
    sleep(600);
}
echo "TYPO3 notice on stdout before the protocol starts\n";
echo 'AQG-MCP-WORKER ' . json_encode(['ready' => true]) . "\n";
while (($line = fgets(STDIN)) !== false) {
    $call = json_decode($line, true)['call'] ?? null;
    if (!is_array($call)) {
        continue;
    }
    $tool = (string)($call['tool'] ?? '');
    if ($tool === 'crash') {
        exit(1);
    }
    if ($tool === 'hang') {
        sleep(600);
    }
    if (str_starts_with($tool, 'sleep:')) {
        usleep((int)substr($tool, 6) * 1000);
    }
    if ($tool === 'noise') {
        echo "AQG-MCP-WORKER not json\n", 'AQG-MCP-WORKER ' . json_encode(['done' => 'someone-else']) . "\n";
    }
    file_put_contents($log, 'done ' . $call['id'] . "\n", FILE_APPEND);
    echo 'AQG-MCP-WORKER ' . json_encode(['done' => $call['id'], 'outcome' => 'executed']) . "\n";
}
file_put_contents($log, "stdin closed\n", FILE_APPEND);
