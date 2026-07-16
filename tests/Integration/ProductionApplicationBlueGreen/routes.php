<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

function productionSagaRevision(): string
{
    $revision = getenv('REVISION');

    return is_string($revision) && in_array($revision, ['blue', 'green'], true)
        ? $revision
        : 'blue';
}

function productionSagaStatePath(string $idempotencyKey): string
{
    return '/state/'.hash('sha256', $idempotencyKey).'.json';
}

Route::get('/health', static fn () => response()->json([
    'healthy' => true,
    'revision' => productionSagaRevision(),
]));

Route::get('/revision', static fn () => response()->json([
    'revision' => productionSagaRevision(),
]));

Route::get('/sse', static function (Request $request) {
    $streamId = (string) $request->query('stream', 'production-saga');

    return response()->stream(static function () use ($streamId): void {
        for ($sequence = 1; $sequence <= 150; $sequence++) {
            echo 'data: '.json_encode([
                'revision' => productionSagaRevision(),
                'sequence' => $sequence,
                'streamId' => $streamId,
            ], JSON_THROW_ON_ERROR)."\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
            usleep(100_000);
        }
    }, 200, [
        'Cache-Control' => 'no-cache',
        'Content-Type' => 'text/event-stream',
        'X-Accel-Buffering' => 'no',
    ]);
});

Route::post('/transactions', static function (Request $request) {
    $idempotencyKey = $request->header('Idempotency-Key');
    if (! is_string($idempotencyKey) || $idempotencyKey === '') {
        return response()->json(['error' => 'Idempotency-Key is required'], 400);
    }

    $recordPath = productionSagaStatePath($idempotencyKey);
    $lock = fopen($recordPath.'.lock', 'c');
    if ($lock === false || ! flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock the production saga transaction.');
    }

    try {
        if (is_file($recordPath)) {
            $transaction = json_decode((string) file_get_contents($recordPath), true, flags: JSON_THROW_ON_ERROR);

            return response()->json(['created' => false, 'transaction' => $transaction]);
        }

        $transaction = [
            'createdByRevision' => productionSagaRevision(),
            'idempotencyKey' => $idempotencyKey,
            'payload' => $request->json()->all(),
            'transactionId' => bin2hex(random_bytes(16)),
            'writeCount' => 1,
        ];
        $temporaryPath = $recordPath.'.'.bin2hex(random_bytes(8)).'.tmp';
        file_put_contents($temporaryPath, json_encode($transaction, JSON_THROW_ON_ERROR), LOCK_EX);
        rename($temporaryPath, $recordPath);

        return response()->json(['created' => true, 'transaction' => $transaction], 201);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
});
