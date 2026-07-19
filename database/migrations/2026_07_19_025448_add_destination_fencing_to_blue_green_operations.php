<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->addStateColumns();
        $this->addQueueColumns();
        $this->assertExactSchema();
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_19_025448_add_destination_fencing_to_blue_green_operations.');
    }

    public function assertExactSchema(): void
    {
        $this->assertExactStateColumns();
        $this->assertExactQueueColumns();
    }

    private function addStateColumns(): void
    {
        $columns = [
            'destination_fence_epoch',
            'destination_fence_operation_id',
            'destination_fence_mutation_sequence',
            'managed_file_sha256',
            'destination_topology_digest',
            'application_routing_config_digest',
            'operation_destination_fence_epoch',
            'operation_previous_destination_fence_epoch',
            'operation_server_boot_id',
            'operation_topology_digest',
            'operation_routing_config_digest',
            'operation_previous_managed_file_sha256',
        ];
        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('application_blue_green_deployments', $column),
        ));
        if ($present === []) {
            Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
                $table->unsignedBigInteger('destination_fence_epoch')->default(0);
                $table->string('destination_fence_operation_id')->nullable();
                $table->unsignedBigInteger('destination_fence_mutation_sequence')->default(0);
                $table->string('managed_file_sha256', 64)->nullable();
                $table->string('destination_topology_digest', 64)->nullable();
                $table->string('application_routing_config_digest', 64)->nullable();
                $table->unsignedBigInteger('operation_destination_fence_epoch')->nullable();
                $table->unsignedBigInteger('operation_previous_destination_fence_epoch')->nullable();
                $table->string('operation_server_boot_id', 36)->nullable();
                $table->string('operation_topology_digest', 64)->nullable();
                $table->string('operation_routing_config_digest', 64)->nullable();
                $table->string('operation_previous_managed_file_sha256', 64)->nullable();
            });

        } elseif ($present !== $columns) {
            throw new RuntimeException('Destination-fencing state columns are partial; refusing a non-convergent replay.');
        }
    }

    private function addQueueColumns(): void
    {
        $columns = [
            'blue_green_destination_fence_epoch',
            'blue_green_server_boot_id',
            'blue_green_topology_digest',
            'blue_green_routing_config_digest',
        ];
        $present = array_values(array_filter(
            $columns,
            static fn (string $column): bool => Schema::hasColumn('application_deployment_queues', $column),
        ));
        if ($present === []) {
            Schema::table('application_deployment_queues', function (Blueprint $table): void {
                $table->unsignedBigInteger('blue_green_destination_fence_epoch')->nullable();
                $table->string('blue_green_server_boot_id', 36)->nullable();
                $table->string('blue_green_topology_digest', 64)->nullable();
                $table->string('blue_green_routing_config_digest', 64)->nullable();
            });

        } elseif ($present !== $columns) {
            throw new RuntimeException('Destination-fencing queue columns are partial; refusing a non-convergent replay.');
        }
    }

    private function assertExactStateColumns(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $isExact = Schema::getConnection()->scalar(<<<'SQL'
                with expected(name, formatted_type, not_null, default_expression, generated, identity) as (
                    values
                        ('destination_fence_epoch', 'bigint', true, '''0''::bigint', '', ''),
                        ('destination_fence_operation_id', 'character varying(255)', false, null::text, '', ''),
                        ('destination_fence_mutation_sequence', 'bigint', true, '''0''::bigint', '', ''),
                        ('managed_file_sha256', 'character varying(64)', false, null::text, '', ''),
                        ('destination_topology_digest', 'character varying(64)', false, null::text, '', ''),
                        ('application_routing_config_digest', 'character varying(64)', false, null::text, '', ''),
                        ('operation_destination_fence_epoch', 'bigint', false, null::text, '', ''),
                        ('operation_previous_destination_fence_epoch', 'bigint', false, null::text, '', ''),
                        ('operation_server_boot_id', 'character varying(36)', false, null::text, '', ''),
                        ('operation_topology_digest', 'character varying(64)', false, null::text, '', ''),
                        ('operation_routing_config_digest', 'character varying(64)', false, null::text, '', ''),
                        ('operation_previous_managed_file_sha256', 'character varying(64)', false, null::text, '', '')
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
                throw new RuntimeException('Destination-fencing state columns do not match the authorized PostgreSQL catalog.');
            }

            return;
        }

        $this->assertExactSqliteColumns('application_blue_green_deployments', [
            'destination_fence_epoch' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false, 'defaultZero' => true],
            'destination_fence_operation_id' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'destination_fence_mutation_sequence' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false, 'defaultZero' => true],
            'managed_file_sha256' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
            'destination_topology_digest' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
            'application_routing_config_digest' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
            'operation_destination_fence_epoch' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => true],
            'operation_previous_destination_fence_epoch' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => true],
            'operation_server_boot_id' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 36],
            'operation_topology_digest' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
            'operation_routing_config_digest' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
            'operation_previous_managed_file_sha256' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
        ]);
    }

    private function assertExactQueueColumns(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $isExact = Schema::getConnection()->scalar(<<<'SQL'
                with expected(name, formatted_type, not_null, default_expression, generated, identity) as (
                    values
                        ('blue_green_destination_fence_epoch', 'bigint', false, null::text, '', ''),
                        ('blue_green_server_boot_id', 'character varying(36)', false, null::text, '', ''),
                        ('blue_green_topology_digest', 'character varying(64)', false, null::text, '', ''),
                        ('blue_green_routing_config_digest', 'character varying(64)', false, null::text, '', '')
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
                      and relation.relname = 'application_deployment_queues'
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
                throw new RuntimeException('Destination-fencing queue columns do not match the authorized PostgreSQL catalog.');
            }

            return;
        }

        $this->assertExactSqliteColumns('application_deployment_queues', [
            'blue_green_destination_fence_epoch' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => true],
            'blue_green_server_boot_id' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 36],
            'blue_green_topology_digest' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
            'blue_green_routing_config_digest' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
        ]);
    }

    /**
     * @param  array<string, array{typeNames: list<string>, nullable: bool, varcharLength?: int, defaultZero?: bool}>  $expectedColumns
     */
    private function assertExactSqliteColumns(string $table, array $expectedColumns): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver !== 'sqlite') {
            throw new RuntimeException("Unsupported database driver for destination-fencing schema attestation: {$driver}.");
        }
        $columns = collect(Schema::getColumns($table))->keyBy('name');
        foreach ($expectedColumns as $name => $expected) {
            $column = $columns->get($name);
            if (! is_array($column)
                || $column['nullable'] !== $expected['nullable']
                || ! in_array($column['type_name'], $expected['typeNames'], true)
                || (isset($expected['varcharLength']) && strtolower($column['type']) !== 'varchar')) {
                throw new RuntimeException("Destination-fencing column does not match the authorized SQLite schema: {$table}.{$name}.");
            }
            $default = $column['default'];
            if (($expected['defaultZero'] ?? false) === true) {
                if (! str_starts_with(trim(strtolower((string) $default), "()'\""), '0')) {
                    throw new RuntimeException("Destination-fencing column default does not match: {$table}.{$name}.");
                }
            } elseif ($default !== null) {
                throw new RuntimeException("Destination-fencing column has an unexpected default: {$table}.{$name}.");
            }
        }
    }
};
