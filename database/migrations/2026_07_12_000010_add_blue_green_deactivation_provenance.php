<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasProvenanceColumn = collect(Schema::getColumns('application_blue_green_deployments'))
            ->contains(fn (array $column): bool => str_starts_with($column['name'], 'deactivation_'));
        if ($hasProvenanceColumn) {
            $this->assertExactSchema();

            return;
        }

        Schema::table('application_blue_green_deployments', function (Blueprint $table) {
            $table->string('deactivation_operation_id', 64)->nullable()->after('legacy_container_name');
            $table->timestamp('deactivation_started_at')->nullable()->after('deactivation_operation_id');
        });
    }

    public function assertExactSchema(): void
    {
        $expectedColumns = [
            'deactivation_operation_id' => ['typeNames' => ['varchar', 'varchar2'], 'varcharLength' => 64],
            'deactivation_started_at' => ['typeNames' => ['timestamp', 'datetime']],
        ];
        $columns = collect(Schema::getColumns('application_blue_green_deployments'))->keyBy('name');
        $present = collect(array_keys($expectedColumns))->filter(fn (string $name) => $columns->has($name));
        if ($present->count() !== count($expectedColumns)) {
            throw new RuntimeException($present->isEmpty()
                ? 'Deactivation provenance schema is missing.'
                : 'Deactivation provenance schema is partial; refusing a non-convergent replay.');
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            $isExact = Schema::getConnection()->scalar(<<<'SQL'
                with expected(name, formatted_type, not_null, default_expression, generated, identity) as (
                    values
                        ('deactivation_operation_id', 'character varying(64)', false, null::text, '', ''),
                        ('deactivation_started_at', 'timestamp(0) without time zone', false, null::text, '', '')
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
                      and attribute.attname like 'deactivation_%'
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
                throw new RuntimeException('Existing deactivation provenance columns do not match the authorized PostgreSQL catalog.');
            }

            return;
        }

        foreach ($expectedColumns as $name => $expected) {
            $column = $columns->get($name);
            $expectedVarcharType = match ($driver) {
                'sqlite' => isset($expected['varcharLength']) ? 'varchar' : null,
                default => throw new RuntimeException("Unsupported database driver for deactivation provenance attestation: {$driver}."),
            };

            if (! $column['nullable']
                || ! in_array($column['type_name'], $expected['typeNames'], true)
                || ($expectedVarcharType !== null && strtolower($column['type']) !== $expectedVarcharType)) {
                throw new RuntimeException("Existing deactivation provenance column does not match the authorized schema: {$name}.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_12_000010_add_blue_green_deactivation_provenance.');
    }
};
