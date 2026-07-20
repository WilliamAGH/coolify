<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'operation_drain_started_at',
            'operation_drain_deadline_at',
            'operation_drain_last_observed_connections',
            'operation_drain_observed_at',
        ];
        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('application_blue_green_deployments', $column),
        ));
        if ($present === []) {
            Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
                $table->timestamp('operation_drain_started_at')->nullable();
                $table->timestamp('operation_drain_deadline_at')->nullable();
                $table->unsignedInteger('operation_drain_last_observed_connections')->nullable();
                $table->timestamp('operation_drain_observed_at')->nullable();
            });
        } elseif ($present !== $columns) {
            throw new RuntimeException('Blue-green drain provenance columns are partial; refusing a non-convergent replay.');
        }

        $this->assertExactSchema();
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_19_030000_add_blue_green_drain_provenance.');
    }

    public function assertExactSchema(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $isExact = Schema::getConnection()->scalar(<<<'SQL'
                with expected(name, formatted_type, not_null, default_expression, generated, identity) as (
                    values
                        ('operation_drain_started_at', 'timestamp(0) without time zone', false, null::text, '', ''),
                        ('operation_drain_deadline_at', 'timestamp(0) without time zone', false, null::text, '', ''),
                        ('operation_drain_last_observed_connections', 'integer', false, null::text, '', ''),
                        ('operation_drain_observed_at', 'timestamp(0) without time zone', false, null::text, '', '')
                ),
                actual as (
                    select
                        attribute.attname::text as name,
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
                      and relation.relname = 'application_blue_green_deployments'
                      and attribute.attname in (select name from expected)
                      and attribute.attnum > 0
                      and not attribute.attisdropped
                )
                select not exists (
                    (select * from expected except all select * from actual)
                    union all
                    (select * from actual except all select * from expected)
                )
                SQL, [], false);
            if ($isExact !== true) {
                throw new RuntimeException('Blue-green drain provenance columns do not match the authorized PostgreSQL catalog.');
            }

            return;
        }

        $this->assertExactSqliteSchema();
    }

    private function assertExactSqliteSchema(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'sqlite') {
            throw new RuntimeException("Unsupported database driver for blue-green drain provenance attestation: {$driver}.");
        }

        $expected = [
            'operation_drain_started_at' => ['timestamp', 'datetime'],
            'operation_drain_deadline_at' => ['timestamp', 'datetime'],
            'operation_drain_last_observed_connections' => ['int4', 'integer', 'int', 'bigint'],
            'operation_drain_observed_at' => ['timestamp', 'datetime'],
        ];
        $actual = collect(Schema::getColumns('application_blue_green_deployments'))->keyBy('name');
        foreach ($expected as $name => $typeNames) {
            $column = $actual->get($name);
            if (! is_array($column)
                || ($column['nullable'] ?? null) !== true
                || ! in_array(strtolower((string) ($column['type_name'] ?? '')), $typeNames, true)
                || ($column['default'] ?? null) !== null) {
                throw new RuntimeException("Blue-green drain provenance column does not match the authorized SQLite schema: {$name}.");
            }
        }
    }
};
