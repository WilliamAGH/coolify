<?php

namespace App\Actions\Application\BlueGreen;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class BlueGreenTopologyLock
{
    public static function acquire(?ConnectionInterface $connection = null): void
    {
        $connection ??= DB::connection();
        if ($connection->transactionLevel() < 1) {
            throw new RuntimeException('The blue-green topology lock requires an active database transaction.');
        }

        if ($connection->getDriverName() === 'sqlite') {
            return;
        }
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('The blue-green topology lock requires PostgreSQL.');
        }

        $connection->statement(
            "SELECT pg_advisory_xact_lock(hashtextextended('coolify-blue-green-topology', 0))",
        );
    }
}
