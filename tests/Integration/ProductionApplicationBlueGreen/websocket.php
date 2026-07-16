<?php

declare(strict_types=1);

$revision = getenv('REVISION');
$revision = is_string($revision) && in_array($revision, ['blue', 'green'], true) ? $revision : 'blue';
$server = stream_socket_server('tcp://127.0.0.1:9001', $errorCode, $errorMessage);
if ($server === false) {
    fwrite(STDERR, "WebSocket listener failed: {$errorCode} {$errorMessage}\n");
    exit(1);
}

while ($connection = @stream_socket_accept($server, -1)) {
    stream_set_timeout($connection, 5);
    $request = '';
    while (! str_contains($request, "\r\n\r\n") && ! feof($connection)) {
        $request .= (string) fread($connection, 4096);
    }
    if (preg_match('/^Sec-WebSocket-Key:\s*(.+)$/mi', $request, $matches) !== 1) {
        fclose($connection);

        continue;
    }

    $accept = base64_encode(sha1(trim($matches[1]).'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    fwrite($connection, "HTTP/1.1 101 Switching Protocols\r\n");
    fwrite($connection, "Upgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: {$accept}\r\n\r\n");
    for ($sequence = 1; $sequence <= 150; $sequence++) {
        $payload = json_encode([
            'revision' => $revision,
            'sequence' => $sequence,
        ], JSON_THROW_ON_ERROR);
        fwrite($connection, chr(0x81).chr(strlen($payload)).$payload);
        usleep(100_000);
    }
    fclose($connection);
}
