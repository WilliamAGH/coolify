<?php

use App\Actions\Application\BlueGreen\BlueGreenTopologyLock;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\SQLiteConnection;
use Tests\TestCase;

uses(TestCase::class);

it('requires an active database transaction before acquiring the topology lock', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
    $connection->shouldNotReceive('getDriverName');

    expect(fn () => BlueGreenTopologyLock::acquire($connection))
        ->toThrow(RuntimeException::class, 'requires an active database transaction');
});

it('allows an active SQLite transaction without issuing a PostgreSQL advisory query', function () {
    $connection = new SQLiteConnection(
        new PDO('sqlite::memory:'),
        ':memory:',
        '',
        ['driver' => 'sqlite'],
    );
    $connection->beginTransaction();

    try {
        expect($connection->transactionLevel())
            ->toBe(1)
            ->and(fn () => BlueGreenTopologyLock::acquire($connection))
            ->not->toThrow(RuntimeException::class);
    } finally {
        $connection->rollBack();
    }
});

it('acquires a transaction-scoped PostgreSQL advisory lock', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
    $connection->shouldReceive('getDriverName')->twice()->andReturn('pgsql');
    $connection->shouldReceive('statement')
        ->once()
        ->with("SELECT pg_advisory_xact_lock(hashtextextended('coolify-blue-green-topology', 0))");

    BlueGreenTopologyLock::acquire($connection);
});

it('fails closed for active transactions on unsupported database drivers', function () {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
    $connection->shouldReceive('getDriverName')->twice()->andReturn('mysql');
    $connection->shouldNotReceive('statement');

    expect(fn () => BlueGreenTopologyLock::acquire($connection))
        ->toThrow(RuntimeException::class, 'requires PostgreSQL');
});
