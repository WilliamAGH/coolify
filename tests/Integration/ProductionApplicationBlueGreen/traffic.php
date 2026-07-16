<?php

declare(strict_types=1);

const EVENT_LOG = '/saga/events.jsonl';
const STOP_FILE = '/saga/stop';

function recordEvent(array $event): void
{
    $event['at'] = microtime(true);
    file_put_contents(EVENT_LOG, json_encode($event, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
}

/** @return array{body: string, contentType: string, status: int} */
function httpRequest(string $path, string $method = 'GET', array $header = [], ?string $body = null): array
{
    $request = curl_init('http://traefik'.$path);
    curl_setopt_array($request, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $header,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 25_000,
    ]);
    if ($body !== null) {
        curl_setopt($request, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($request);
    if (! is_string($responseBody)) {
        throw new RuntimeException(curl_error($request));
    }
    $headerSize = curl_getinfo($request, CURLINFO_HEADER_SIZE);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $contentType = curl_getinfo($request, CURLINFO_CONTENT_TYPE);
    curl_close($request);

    return [
        'body' => substr($responseBody, $headerSize),
        'contentType' => is_string($contentType) ? $contentType : '',
        'status' => $status,
    ];
}

function decodedJson(string $body): array
{
    $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Expected a JSON object.');
    }

    return $decoded;
}

function assertStatus(string $protocol, int $actual, int ...$expected): void
{
    recordEvent(['protocol' => $protocol, 'status' => $actual, 'type' => 'status']);
    if (! in_array($actual, $expected, true)) {
        throw new RuntimeException("Expected {$protocol} HTTP status ".implode(' or ', $expected).", received {$actual}.");
    }
}

function recordRevision(string $protocol, mixed $revision): void
{
    if (! is_string($revision) || ! in_array($revision, ['blue', 'green'], true)) {
        throw new RuntimeException("{$protocol} returned an invalid revision.");
    }
    recordEvent(['protocol' => $protocol, 'revision' => $revision, 'type' => 'success']);
}

function websocketFrameBytes(mixed $socket, string &$buffer, int $requiredBytes): void
{
    while (strlen($buffer) < $requiredBytes && ! feof($socket)) {
        $buffer .= (string) fread($socket, 4096);
    }
    if (strlen($buffer) < $requiredBytes) {
        throw new RuntimeException('WebSocket frame ended before the advertised payload length.');
    }
}

function websocketSession(): void
{
    $startedAt = microtime(true);
    $sessionId = bin2hex(random_bytes(8));
    $socket = stream_socket_client('tcp://traefik:80', $errorCode, $errorMessage, 5);
    if ($socket === false) {
        throw new RuntimeException("WebSocket connection failed: {$errorCode} {$errorMessage}");
    }
    stream_set_timeout($socket, 25);
    $key = base64_encode(random_bytes(16));
    fwrite($socket, "GET /ws HTTP/1.1\r\nHost: traefik\r\nConnection: Upgrade\r\nUpgrade: websocket\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Key: {$key}\r\n\r\n");
    $response = '';
    while (! str_contains($response, "\r\n\r\n") && ! feof($socket)) {
        $response .= (string) fread($socket, 4096);
    }
    [$headers, $frame] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
    if (! str_starts_with($headers, 'HTTP/1.1 101')) {
        preg_match('/^HTTP\/1\.1\s+(\d+)/', $headers, $matches);
        $status = isset($matches[1]) ? (int) $matches[1] : 0;
        recordEvent(['protocol' => 'websocket', 'status' => $status, 'type' => 'status']);
        throw new RuntimeException("WebSocket upgrade failed: {$headers}");
    }
    recordEvent(['protocol' => 'websocket', 'status' => 101, 'type' => 'status']);
    for ($expectedSequence = 1; $expectedSequence <= 150; $expectedSequence++) {
        websocketFrameBytes($socket, $frame, 2);
        $payloadLength = ord($frame[1]) & 0x7F;
        $frameHeaderLength = 2;
        if ($payloadLength === 126) {
            websocketFrameBytes($socket, $frame, 4);
            $payloadLength = unpack('n', substr($frame, 2, 2))[1];
            $frameHeaderLength = 4;
        }
        websocketFrameBytes($socket, $frame, $frameHeaderLength + $payloadLength);
        $payload = decodedJson(substr($frame, $frameHeaderLength, $payloadLength));
        $frame = substr($frame, $frameHeaderLength + $payloadLength);
        if (($payload['sequence'] ?? null) !== $expectedSequence) {
            throw new RuntimeException('WebSocket frame sequence was not continuous.');
        }
        recordRevision('websocket', $payload['revision'] ?? null);
    }
    fclose($socket);
    recordEvent([
        'completedAt' => microtime(true),
        'frameCount' => 150,
        'protocol' => 'websocket',
        'sessionId' => $sessionId,
        'startedAt' => $startedAt,
        'status' => 101,
        'type' => 'session',
    ]);
}

function runWorker(string $protocol, int $deadline): never
{
    while (! is_file(STOP_FILE) && time() < $deadline) {
        try {
            if ($protocol === 'http') {
                $response = httpRequest('/api/revision');
                assertStatus($protocol, $response['status'], 200);
                recordRevision($protocol, decodedJson($response['body'])['revision'] ?? null);
            } elseif ($protocol === 'write') {
                $idempotencyKey = 'production-saga-'.bin2hex(random_bytes(12));
                $body = json_encode(['idempotencyKey' => $idempotencyKey], JSON_THROW_ON_ERROR);
                $header = ['Content-Type: application/json', "Idempotency-Key: {$idempotencyKey}"];
                $created = httpRequest('/api/transactions', 'POST', $header, $body);
                assertStatus($protocol, $created['status'], 201);
                $createdJson = decodedJson($created['body']);
                $replayed = httpRequest('/api/transactions', 'POST', $header, $body);
                assertStatus($protocol, $replayed['status'], 200);
                $replayedJson = decodedJson($replayed['body']);
                if (($createdJson['created'] ?? null) !== true
                    || ($replayedJson['created'] ?? null) !== false
                    || ($createdJson['transaction']['transactionId'] ?? null) !== ($replayedJson['transaction']['transactionId'] ?? null)
                    || ($replayedJson['transaction']['writeCount'] ?? null) !== 1) {
                    throw new RuntimeException('Idempotent replay did not preserve exactly one write.');
                }
                recordEvent([
                    'createdCount' => 1,
                    'protocol' => $protocol,
                    'replayedCount' => 1,
                    'type' => 'write',
                ]);
                recordRevision($protocol, $createdJson['transaction']['createdByRevision'] ?? null);
            } elseif ($protocol === 'sse') {
                $startedAt = microtime(true);
                $streamId = bin2hex(random_bytes(8));
                $response = httpRequest('/api/sse?stream='.$streamId);
                assertStatus($protocol, $response['status'], 200);
                if (! str_starts_with(strtolower($response['contentType']), 'text/event-stream')) {
                    throw new RuntimeException('SSE returned an unexpected content type.');
                }
                preg_match_all('/^data:\s*(.+)$/m', $response['body'], $matches);
                if (count($matches[1]) !== 150) {
                    throw new RuntimeException('SSE did not return 150 complete events.');
                }
                foreach ($matches[1] as $event) {
                    $payload = decodedJson($event);
                    if (($payload['streamId'] ?? null) !== $streamId) {
                        throw new RuntimeException('SSE stream identity changed in flight.');
                    }
                    recordRevision($protocol, $payload['revision'] ?? null);
                }
                recordEvent([
                    'completedAt' => microtime(true),
                    'contentType' => $response['contentType'],
                    'eventCount' => 150,
                    'protocol' => $protocol,
                    'startedAt' => $startedAt,
                    'status' => 200,
                    'streamId' => $streamId,
                    'type' => 'stream',
                ]);
            } elseif ($protocol === 'websocket') {
                websocketSession();
            }
        } catch (Throwable $exception) {
            recordEvent([
                'message' => $exception->getMessage(),
                'protocol' => $protocol,
                'type' => 'error',
            ]);
        }
        usleep(40_000);
    }
    exit(0);
}

@unlink(EVENT_LOG);
@unlink(STOP_FILE);
$deadlineSeconds = filter_var(getenv('TRAFFIC_DEADLINE_SECONDS'), FILTER_VALIDATE_INT) ?: 180;
$deadline = time() + $deadlineSeconds;
$children = [];
foreach (['http', 'sse', 'websocket', 'write'] as $protocol) {
    $processId = pcntl_fork();
    if ($processId === 0) {
        runWorker($protocol, $deadline);
    }
    if ($processId < 0) {
        throw new RuntimeException("Could not start {$protocol} traffic worker.");
    }
    $children[] = $processId;
}
foreach ($children as $processId) {
    pcntl_waitpid($processId, $status);
}

$event = is_file(EVENT_LOG) ? file(EVENT_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
$report = [
    'durableWriteCount' => 0,
    'durableWritesExactlyOnce' => true,
    'error' => [],
    'heldAcrossPromotion' => [
        'sse' => [],
        'websocket' => [],
    ],
    'operation' => [
        'sseStream' => 0,
        'websocketSession' => 0,
        'writeCreated' => 0,
        'writeReplayed' => 0,
    ],
    'protocol' => [],
    'switchObservedAt' => 0.0,
    'revision' => [],
    'status' => [],
];
$report['switchObservedAt'] = is_file('/saga/switch-observed')
    ? (float) file_get_contents('/saga/switch-observed')
    : 0.0;
foreach ($event as $encodedEvent) {
    $decodedEvent = decodedJson($encodedEvent);
    $protocol = (string) ($decodedEvent['protocol'] ?? 'unknown');
    $eventType = $decodedEvent['type'] ?? null;
    if ($eventType === 'error') {
        $report['error'][] = $decodedEvent;

        continue;
    }
    if ($eventType === 'status') {
        $statusKey = (string) $decodedEvent['status'];
        $report['status'][$statusKey] = ($report['status'][$statusKey] ?? 0) + 1;

        continue;
    }
    if ($eventType === 'success') {
        $report['protocol'][$protocol] = ($report['protocol'][$protocol] ?? 0) + 1;
        $report['revision'][$decodedEvent['revision']] = ($report['revision'][$decodedEvent['revision']] ?? 0) + 1;

        continue;
    }
    if ($eventType === 'write') {
        $report['operation']['writeCreated'] += (int) $decodedEvent['createdCount'];
        $report['operation']['writeReplayed'] += (int) $decodedEvent['replayedCount'];

        continue;
    }
    if ($eventType === 'stream' || $eventType === 'session') {
        $operation = $eventType === 'stream' ? 'sseStream' : 'websocketSession';
        $report['operation'][$operation]++;
        if ((float) $decodedEvent['startedAt'] < $report['switchObservedAt']
            && $report['switchObservedAt'] < (float) $decodedEvent['completedAt']) {
            $connectionId = $eventType === 'stream'
                ? $decodedEvent['streamId']
                : $decodedEvent['sessionId'];
            $report['heldAcrossPromotion'][$protocol][] = [
                'completedAt' => $decodedEvent['completedAt'],
                'connectionId' => $connectionId,
                'startedAt' => $decodedEvent['startedAt'],
                'status' => $decodedEvent['status'],
            ];
        }
    }
}
$durableWritePath = glob('/state/*.json') ?: [];
$report['durableWriteCount'] = count($durableWritePath);
foreach ($durableWritePath as $recordPath) {
    $transaction = decodedJson((string) file_get_contents($recordPath));
    if (($transaction['writeCount'] ?? null) !== 1) {
        $report['durableWritesExactlyOnce'] = false;
    }
}
ksort($report['protocol']);
ksort($report['revision']);
ksort($report['status']);
$encodedReport = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
file_put_contents('/saga/report.json', $encodedReport);
fwrite(STDOUT, $encodedReport);

$hasEveryProtocol = count(array_intersect(array_keys($report['protocol']), ['http', 'sse', 'websocket', 'write'])) === 4;
$hasForbiddenStatus = array_any(array_keys($report['status']), static fn (string $status): bool => (int) $status >= 300);
$writeCountMatches = $report['durableWritesExactlyOnce']
    && $report['durableWriteCount'] === $report['operation']['writeCreated']
    && $report['operation']['writeCreated'] === $report['operation']['writeReplayed'];
$heldAcrossPromotion = $report['heldAcrossPromotion']['sse'] !== []
    && $report['heldAcrossPromotion']['websocket'] !== [];
if ($report['error'] !== []
    || ! $hasEveryProtocol
    || $hasForbiddenStatus
    || ! isset($report['revision']['blue'], $report['revision']['green'])
    || ! isset($report['status']['101'])
    || ! $writeCountMatches
    || ! $heldAcrossPromotion) {
    exit(1);
}
