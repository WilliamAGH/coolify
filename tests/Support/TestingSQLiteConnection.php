<?php

namespace Tests\Support;

use Illuminate\Database\SQLiteConnection;
use LogicException;

/**
 * Preserves historical PostgreSQL migration semantics on the SQLite test connection.
 *
 * Laravel compiles both JSON and text columns to SQLite TEXT by default, so the
 * corresponding PostgreSQL-only forward casts are already satisfied on SQLite.
 */
final class TestingSQLiteConnection extends SQLiteConnection
{
    /** @var array<string, string> */
    private const POSTGRES_TEXT_CONVERSIONS = [
        'ALTER TABLE application_deployment_queues ALTER COLUMN configuration_snapshot TYPE text USING configuration_snapshot::text' => 'configuration_snapshot',
        'ALTER TABLE application_deployment_queues ALTER COLUMN configuration_diff TYPE text USING configuration_diff::text' => 'configuration_diff',
    ];

    /**
     * Execute an SQL statement and return the boolean result.
     *
     * @param  string  $query
     * @param  array<int|string, mixed>  $bindings
     */
    public function statement($query, $bindings = []): bool
    {
        $column = self::POSTGRES_TEXT_CONVERSIONS[$query] ?? null;

        if ($column === null || $bindings !== []) {
            return parent::statement($query, $bindings);
        }

        $columnType = $this->getSchemaBuilder()->getColumnType(
            'application_deployment_queues',
            $column,
        );

        if ($columnType !== 'text') {
            throw new LogicException(
                "The SQLite testing compatibility no-op requires {$column} to already use the text type; {$columnType} found.",
            );
        }

        return true;
    }
}
