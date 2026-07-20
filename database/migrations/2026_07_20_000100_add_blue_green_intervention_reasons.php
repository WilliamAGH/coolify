<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'application_blue_green_deployments',
        'application_blue_green_deactivations',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                throw new RuntimeException("Blue-green intervention reason migration requires {$tableName}.");
            }
            if (! Schema::hasColumn($tableName, 'intervention_phase')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('intervention_phase', 32)->nullable();
                });
            }
            if (! Schema::hasColumn($tableName, 'intervention_reason')) {
                Schema::table($tableName, function (Blueprint $table): void {
                    $table->string('intervention_reason', 2048)->nullable();
                });
            }
        }

        $this->assertExactSchema();
    }

    public function assertExactSchema(): void
    {
        foreach (self::TABLES as $tableName) {
            if (! Schema::hasTable($tableName)) {
                throw new RuntimeException("Blue-green intervention reason migration requires {$tableName}.");
            }

            $this->assertExactColumn($tableName, 'intervention_phase', 32);
            $this->assertExactColumn($tableName, 'intervention_reason', 2048);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_20_000100_add_blue_green_intervention_reasons.');
    }

    private function assertExactColumn(string $tableName, string $columnName, int $length): void
    {
        $connection = Schema::getConnection();
        if ($connection->getDriverName() === 'pgsql') {
            $isExact = $connection->scalar(<<<SQL
                with expected(formatted_type, not_null, default_expression, generated, identity) as (
                    values ('character varying({$length})', false, null::text, '', '')
                ),
                actual as (
                    select
                        format_type(attribute.atttypid, attribute.atttypmod) as formatted_type,
                        attribute.attnotnull as not_null,
                        pg_get_expr(attribute_default.adbin, attribute_default.adrelid) as default_expression,
                        attribute.attgenerated::text as generated,
                        attribute.attidentity::text as identity
                    from pg_attribute as attribute
                    join pg_class as relation on relation.oid = attribute.attrelid
                    join pg_namespace as namespace on namespace.oid = relation.relnamespace
                    left join pg_attrdef as attribute_default
                      on attribute_default.adrelid = relation.oid
                     and attribute_default.adnum = attribute.attnum
                    where namespace.nspname = current_schema()
                      and relation.relname = ?
                      and attribute.attname = ?
                      and attribute.attnum > 0
                      and not attribute.attisdropped
                )
                select not exists (
                    (select * from expected except all select * from actual)
                    union all
                    (select * from actual except all select * from expected)
                )
                SQL, [$tableName, $columnName], false);
            if ($isExact !== true) {
                throw new RuntimeException("Blue-green intervention {$columnName} column does not match the authorized PostgreSQL schema on {$tableName}.");
            }

            return;
        }
        if ($connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException("Unsupported database driver for blue-green intervention schema attestation: {$connection->getDriverName()}.");
        }

        $column = collect(Schema::getColumns($tableName))->keyBy('name')->get($columnName);
        if (! is_array($column)
            || ($column['nullable'] ?? null) !== true
            || ! in_array(strtolower((string) ($column['type_name'] ?? '')), ['varchar', 'string'], true)
            || ($column['default'] ?? null) !== null) {
            throw new RuntimeException("Blue-green intervention {$columnName} column does not match the authorized SQLite schema on {$tableName}.");
        }
    }
};
