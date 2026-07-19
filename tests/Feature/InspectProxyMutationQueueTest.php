<?php

use App\Support\ProxyMutationQueue;
use App\Support\ProxyMutationQueueDiagnostics;
use App\Support\ProxyMutationRedisQueue;
use Illuminate\Queue\QueueManager;

function proxyMutationDiagnosticPayload(string $uuid, string $displayName, string $secret): string
{
    return json_encode([
        'uuid' => $uuid,
        'id' => $uuid,
        'displayName' => $displayName,
        ProxyMutationQueue::PAYLOAD_MARKER => true,
        'secret' => $secret,
    ], JSON_THROW_ON_ERROR);
}

/** @return array{ProxyMutationQueueDiagnostics, object} */
function proxyMutationDiagnosticsFixture(array $pending, array $reserved, array $delayed): array
{
    $connection = Mockery::mock();
    $connection->shouldNotReceive('lrange', 'zrange');
    $connection->shouldReceive('eval')->once()->andReturn([
        '',
        count($pending),
        count($reserved),
        count($delayed),
        $pending,
        $reserved,
        $delayed,
    ]);

    $queue = Mockery::mock(ProxyMutationRedisQueue::class);
    $queue->shouldReceive('getQueue')->andReturnUsing(
        static fn (string $name): string => 'queues:'.$name,
    );
    $queue->shouldReceive('getConnection')->andReturn($connection);

    $queues = Mockery::mock(QueueManager::class);
    $queues->shouldReceive('connection')->once()->with(ProxyMutationQueue::CONNECTION)->andReturn($queue);

    return [new ProxyMutationQueueDiagnostics($queues), $connection];
}

test('explicitly inspects every queue state without printing raw payload secrets', function () {
    [$diagnostics] = proxyMutationDiagnosticsFixture(
        [proxyMutationDiagnosticPayload('pending-uuid', 'PendingMutation', 'pending-production-secret')],
        [proxyMutationDiagnosticPayload('reserved-uuid', 'ReservedMutation', 'reserved-production-secret')],
        [proxyMutationDiagnosticPayload('delayed-uuid', 'DelayedMutation', 'delayed-production-secret')],
    );
    app()->instance(ProxyMutationQueueDiagnostics::class, $diagnostics);

    $this->artisan('proxy-mutations:inspect')
        ->expectsOutputToContain('Cardinality: pending=1 reserved=1 delayed=1')
        ->expectsOutputToContain('pending-uuid')
        ->expectsOutputToContain('reserved-uuid')
        ->expectsOutputToContain('delayed-uuid')
        ->doesntExpectOutputToContain('production-secret')
        ->expectsOutputToContain('All inspected proxy-mutation payloads are marker-backed')
        ->assertSuccessful();
});

test('fails closed for malformed or unmarked payloads without echoing their bodies', function () {
    [$diagnostics] = proxyMutationDiagnosticsFixture(
        ['{"secret":"malformed-production-secret"'],
        [json_encode(['uuid' => 'unmarked', 'id' => 'unmarked', 'secret' => 'unmarked-production-secret'], JSON_THROW_ON_ERROR)],
        [],
    );
    app()->instance(ProxyMutationQueueDiagnostics::class, $diagnostics);

    $this->artisan('proxy-mutations:inspect')
        ->expectsOutputToContain('INVALID:')
        ->expectsOutputToContain('Raw payload bodies were not printed')
        ->doesntExpectOutputToContain('production-secret')
        ->assertFailed();
});

test('fails closed for duplicate and cross-state payload identities from one atomic snapshot', function () {
    $duplicate = proxyMutationDiagnosticPayload('duplicate-uuid', 'DuplicateMutation', 'duplicate-production-secret');
    $overlap = proxyMutationDiagnosticPayload('overlap-uuid', 'OverlapMutation', 'overlap-production-secret');
    [$diagnostics] = proxyMutationDiagnosticsFixture(
        [$duplicate, $duplicate, $overlap],
        [$overlap],
        [],
    );
    app()->instance(ProxyMutationQueueDiagnostics::class, $diagnostics);

    $this->artisan('proxy-mutations:inspect')
        ->expectsOutputToContain('identity is duplicated')
        ->expectsOutputToContain('identity also exists in pending')
        ->doesntExpectOutputToContain('production-secret')
        ->assertFailed();
});

test('rejects an unbounded diagnostic request before reading Redis', function () {
    $queues = Mockery::mock(QueueManager::class);
    $queues->shouldNotReceive('connection');
    app()->instance(ProxyMutationQueueDiagnostics::class, new ProxyMutationQueueDiagnostics($queues));

    $this->artisan('proxy-mutations:inspect --limit=1001')
        ->expectsOutputToContain('must be an integer between 1 and 1000')
        ->assertExitCode(2);
});
