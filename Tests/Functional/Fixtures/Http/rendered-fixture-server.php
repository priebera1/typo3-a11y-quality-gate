<?php

declare(strict_types=1);

/**
 * Loopback-only HTTP fixture for RenderedPageFetcherBoundedTransferFunctionalTest (SOL-FINAL-02). Synthetic content only.
 * Usage: php rendered-fixture-server.php <port-file> <log-file>
 * Each connection is served by a forked child; the child appends "<path> <bytes accepted by the kernel> <complete|aborted>"
 * to the log. Bytes "accepted by the kernel" include whatever sat in socket buffers when the client stopped reading.
 */
[$portFile, $logFile] = [$argv[1], $argv[2]];
const MIB = 1048576;
const LIMIT = 5242880;
pcntl_signal(SIGCHLD, SIG_IGN);
pcntl_signal(SIGPIPE, SIG_IGN);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, $errstr);
    exit(1);
}
file_put_contents($portFile, (string)parse_url('tcp://' . stream_socket_get_name($server, false), PHP_URL_PORT));

function html(int $bytes): string
{
    $head = '<!DOCTYPE html><html lang="en"><head><title>Fixture</title></head><body><main><h1>Fixture</h1><p>';
    $tail = '</p></main></body></html>';
    return $head . str_repeat('a', max(0, $bytes - strlen($head) - strlen($tail))) . $tail;
}

/** @return array{0:int,1:bool} bytes written, completed */
function send($client, string $data, int $chunk = 65536, int $sleepAfter = 0, int $stopAfter = -1): array
{
    $sent = 0;
    $length = strlen($data);
    while ($sent < $length) {
        if ($stopAfter >= 0 && $sent >= $stopAfter) {
            if ($sleepAfter > 0) {
                sleep($sleepAfter);
            }
            return [$sent, false];
        }
        $written = @fwrite($client, substr($data, $sent, $chunk));
        if ($written === false || $written === 0) {
            return [$sent, false];
        }
        $sent += $written;
    }
    return [$sent, true];
}

function head(int $status, array $headers): string
{
    $reason = [200 => 'OK', 302 => 'Found', 500 => 'Internal Server Error'][$status] ?? 'Status';
    $lines = ["HTTP/1.1 $status $reason"];
    foreach ($headers as $name => $value) {
        $lines[] = "$name: $value";
    }
    return implode("\r\n", $lines) . "\r\nConnection: close\r\n\r\n";
}

function serve($client, string $path): array
{
    $type = ['Content-Type' => 'text/html; charset=utf-8'];
    switch ($path) {
        case '/small':
            $body = html(2048);
            return send($client, head(200, $type + ['Content-Length' => strlen($body)]) . $body);
        case '/exact':
            $body = html(LIMIT);
            return send($client, head(200, $type + ['Content-Length' => strlen($body)]) . $body);
        case '/exact-plus-one':
            $body = html(LIMIT + 1);
            return send($client, head(200, $type + ['Content-Length' => strlen($body)]) . $body);
        case '/known':
            $body = html(8 * MIB);
            return send($client, head(200, $type + ['Content-Length' => strlen($body)]) . $body);
        case '/no-length':
            return send($client, head(200, $type) . html(8 * MIB));
        case '/dishonest-short':
            // Declares 1,000 bytes, then keeps sending 8 MiB: a client must stop at the declared length.
            return send($client, head(200, $type + ['Content-Length' => '1000']) . html(8 * MIB));
        case '/chunked':
            [$sent, $complete] = send($client, head(200, $type + ['Transfer-Encoding' => 'chunked']));
            $body = html(8 * MIB);
            for ($offset = 0; $offset < strlen($body) && $complete; $offset += 65536) {
                $part = substr($body, $offset, 65536);
                [$n, $complete] = send($client, dechex(strlen($part)) . "\r\n" . $part . "\r\n");
                $sent += $n;
            }
            if ($complete) {
                [$n, $complete] = send($client, "0\r\n\r\n");
                $sent += $n;
            }
            return [$sent, $complete];
        case '/gzip':
            $body = gzencode(html(64 * MIB), 9);
            return send($client, head(200, $type + ['Content-Encoding' => 'gzip', 'Content-Length' => strlen($body)]) . $body);
        case '/gzip-small':
            $body = gzencode(html(4096), 9);
            return send($client, head(200, $type + ['Content-Encoding' => 'gzip', 'Content-Length' => strlen($body)]) . $body);
        case '/redirect-oversize':
            return send($client, head(302, ['Location' => '/chunked', 'Content-Length' => '0']));
        case '/redirect-external':
            return send($client, head(302, ['Location' => 'http://not-the-site.example/', 'Content-Length' => '0']));
        case '/error-large':
            // 500 with an 8 MiB HTML error page: the status decides, the body is never read.
            $body = html(8 * MIB);
            return send($client, head(500, $type + ['Content-Length' => strlen($body)]) . $body);
        case '/json-large':
            $body = str_repeat('{"a":1}', MIB);
            return send($client, head(200, ['Content-Type' => 'application/json', 'Transfer-Encoding' => 'identity']) . $body);
        case '/redirect-large-body':
            // A redirect with an 8 MiB body: followed from its headers.
            $body = html(8 * MIB);
            return send($client, head(302, ['Location' => '/small', 'Content-Type' => 'text/html', 'Content-Length' => strlen($body)]) . $body);
        case '/early-hints':
            [$sent] = send($client, "HTTP/1.1 103 Early Hints\r\nLink: </style.css>; rel=preload\r\n\r\n");
            $body = html(2048);
            [$n, $complete] = send($client, head(200, $type + ['Content-Length' => strlen($body)]) . $body);
            return [$sent + $n, $complete];
        case '/midway':
            $body = html(3 * MIB);
            return send($client, head(200, $type + ['Content-Length' => strlen($body)]) . $body, 65536, 0, MIB);
        case '/slow':
            $body = html(MIB);
            return send($client, head(200, $type + ['Content-Length' => strlen($body)]) . $body, 4096, 20, 16384);
        default:
            return send($client, head(404, ['Content-Length' => '0']));
    }
}

while (true) {
    $client = @stream_socket_accept($server, 600);
    if ($client === false) {
        exit(0);
    }
    $pid = pcntl_fork();
    if ($pid !== 0) {
        fclose($client);
        continue;
    }
    $request = '';
    while (!str_contains($request, "\r\n\r\n") && !feof($client) && strlen($request) < 65536) {
        $request .= (string)fread($client, 8192);
    }
    $path = explode(' ', explode("\r\n", $request)[0] ?? '')[1] ?? '/';
    [$sent, $complete] = serve($client, $path);
    @fclose($client);
    file_put_contents($logFile, sprintf("%s %d %s\n", $path, $sent, $complete ? 'complete' : 'aborted'), FILE_APPEND | LOCK_EX);
    exit(0);
}
