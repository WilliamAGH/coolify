<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('application_blue_green_deployments')) {
            $this->assertExactSchema();

            return;
        }

        Schema::create('application_blue_green_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standalone_docker_id')->constrained()->cascadeOnDelete();
            $table->string('active_color')->nullable();
            $table->string('pending_color')->nullable();
            $table->string('blue_deployment_uuid')->nullable();
            $table->string('green_deployment_uuid')->nullable();
            $table->string('pending_deployment_uuid')->nullable();
            $table->string('legacy_container_name')->nullable();
            $table->string('operation_deployment_uuid')->nullable();
            $table->string('operation_previous_active_color')->nullable();
            $table->string('operation_previous_deployment_uuid')->nullable();
            $table->unsignedBigInteger('operation_previous_routing_revision')->nullable();
            $table->string('operation_previous_container_name')->nullable();
            $table->string('operation_previous_container_id', 64)->nullable();
            $table->string('operation_candidate_container_name')->nullable();
            $table->string('operation_candidate_container_id', 64)->nullable();
            $table->string('operation_rollback_managed_filename')->nullable();
            $table->timestamp('operation_routing_mutated_at')->nullable();
            $table->unsignedSmallInteger('operation_legacy_routing_snapshot_version')->nullable();
            $table->text('operation_legacy_routing_snapshot')->nullable();
            $table->string('operation_legacy_routing_snapshot_sha256', 64)->nullable();
            $table->string('phase')->default('idle');
            $table->unsignedBigInteger('routing_revision')->default(0);
            $table->timestamps();

            $table->unique(['application_id', 'standalone_docker_id'], 'app_blue_green_destination_unique');
            $table->index('standalone_docker_id', 'app_blue_green_standalone_docker_index');
        });
    }

    public function assertExactSchema(): void
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            $this->assertExactPostgresSchema();

            return;
        }
        if ($driver !== 'sqlite') {
            throw new RuntimeException("Unsupported database driver for blue-green schema attestation: {$driver}.");
        }

        $expectedColumns = [
            'id' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false],
            'application_id' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false],
            'standalone_docker_id' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false],
            'active_color' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'pending_color' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'blue_deployment_uuid' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'green_deployment_uuid' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'pending_deployment_uuid' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'legacy_container_name' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'operation_deployment_uuid' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'operation_previous_active_color' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'operation_previous_deployment_uuid' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'operation_previous_routing_revision' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => true],
            'operation_previous_container_name' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'operation_previous_container_id' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
            'operation_candidate_container_name' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'operation_candidate_container_id' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
            'operation_rollback_managed_filename' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 255],
            'operation_routing_mutated_at' => ['typeNames' => ['timestamp', 'datetime'], 'nullable' => true],
            'operation_legacy_routing_snapshot_version' => ['typeNames' => ['int2', 'integer', 'smallint'], 'nullable' => true],
            'operation_legacy_routing_snapshot' => ['typeNames' => ['text'], 'nullable' => true],
            'operation_legacy_routing_snapshot_sha256' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => true, 'varcharLength' => 64],
            'phase' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => false, 'varcharLength' => 255],
            'routing_revision' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false],
            'created_at' => ['typeNames' => ['timestamp', 'datetime'], 'nullable' => true],
            'updated_at' => ['typeNames' => ['timestamp', 'datetime'], 'nullable' => true],
        ];
        $columns = collect(Schema::getColumns('application_blue_green_deployments'))->keyBy('name');
        $laterOwnedColumnGroups = [
            ['deactivation_operation_id', 'deactivation_started_at'],
            [
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
            ],
            ['supersession_generation'],
            [
                'operation_drain_started_at',
                'operation_drain_deadline_at',
                'operation_drain_last_observed_connections',
                'operation_drain_observed_at',
            ],
            [
                'operation_previous_proxy_state',
                'operation_previous_proxy_state_sha256',
                'operation_rollback_proxy_state',
                'operation_rollback_proxy_state_sha256',
            ],
        ];
        $laterOwnedColumns = array_merge(...$laterOwnedColumnGroups);
        $columnNames = $columns->keys()->values()->all();
        $unexpectedColumns = array_diff($columnNames, [...array_keys($expectedColumns), ...$laterOwnedColumns]);
        $missingColumns = array_diff(array_keys($expectedColumns), $columnNames);
        $hasPartialLaterOwnedGroup = collect($laterOwnedColumnGroups)->contains(function (array $group) use ($columnNames): bool {
            $presentCount = count(array_intersect($group, $columnNames));

            return ! in_array($presentCount, [0, count($group)], true);
        });

        if ($unexpectedColumns !== []
            || $missingColumns !== []
            || $hasPartialLaterOwnedGroup) {
            throw new RuntimeException('Existing blue-green deployment table has an unexpected column set.');
        }

        foreach ($expectedColumns as $name => $expected) {
            $column = $columns->get($name);
            $expectedVarcharType = isset($expected['varcharLength']) ? 'varchar' : null;

            if ($column['nullable'] !== $expected['nullable']
                || ! in_array($column['type_name'], $expected['typeNames'], true)
                || ($expectedVarcharType !== null && strtolower($column['type']) !== $expectedVarcharType)) {
                throw new RuntimeException("Existing blue-green deployment column does not match: {$name}.");
            }
        }

        if (! $columns->get('id')['auto_increment']) {
            throw new RuntimeException('Existing blue-green deployment ID is not auto-incrementing.');
        }
        $this->assertOwnedIdSequence();

        $phaseDefault = strtolower((string) $columns->get('phase')['default']);
        $revisionDefault = strtolower((string) $columns->get('routing_revision')['default']);
        if (! str_contains($phaseDefault, 'idle') || ! str_starts_with(trim($revisionDefault, "()'\""), '0')) {
            throw new RuntimeException('Existing blue-green deployment defaults do not match.');
        }

        $indexes = collect(Schema::getIndexes('application_blue_green_deployments'));
        $expectedIndexes = [
            ['columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'app_blue_green_destination_unique', 'columns' => ['application_id', 'standalone_docker_id'], 'unique' => true],
            ['name' => 'app_blue_green_standalone_docker_index', 'columns' => ['standalone_docker_id'], 'unique' => false],
        ];
        foreach ($expectedIndexes as $expectedIndex) {
            $matches = $indexes->contains(fn (array $index) => collect($expectedIndex)
                ->every(fn (mixed $expected, string $key) => $index[$key] === $expected));
            if (! $matches) {
                throw new RuntimeException('Existing blue-green deployment indexes do not match.');
            }
        }

        $foreignKeys = collect(Schema::getForeignKeys('application_blue_green_deployments'));
        foreach ([
            ['columns' => ['application_id'], 'foreign_table' => 'applications'],
            ['columns' => ['standalone_docker_id'], 'foreign_table' => 'standalone_dockers'],
        ] as $expectedForeignKey) {
            $matches = $foreignKeys->contains(fn (array $foreignKey) => $foreignKey['columns'] === $expectedForeignKey['columns']
                && $foreignKey['foreign_table'] === $expectedForeignKey['foreign_table']
                && $foreignKey['foreign_columns'] === ['id']
                && $foreignKey['on_delete'] === 'cascade');
            if (! $matches) {
                throw new RuntimeException('Existing blue-green deployment foreign keys do not match.');
            }
        }
    }

    private function assertExactPostgresSchema(): void
    {
        $schemaIsExact = Schema::getConnection()->scalar(<<<'SQL'
            with expected(name, formatted_type, not_null, default_expression, generated, identity) as (
                values
                    ('id', 'bigint', true, '<owned-sequence>', '', ''),
                    ('application_id', 'bigint', true, null::text, '', ''),
                    ('standalone_docker_id', 'bigint', true, null::text, '', ''),
                    ('active_color', 'character varying(255)', false, null::text, '', ''),
                    ('pending_color', 'character varying(255)', false, null::text, '', ''),
                    ('blue_deployment_uuid', 'character varying(255)', false, null::text, '', ''),
                    ('green_deployment_uuid', 'character varying(255)', false, null::text, '', ''),
                    ('pending_deployment_uuid', 'character varying(255)', false, null::text, '', ''),
                    ('legacy_container_name', 'character varying(255)', false, null::text, '', ''),
                    ('operation_deployment_uuid', 'character varying(255)', false, null::text, '', ''),
                    ('operation_previous_active_color', 'character varying(255)', false, null::text, '', ''),
                    ('operation_previous_deployment_uuid', 'character varying(255)', false, null::text, '', ''),
                    ('operation_previous_routing_revision', 'bigint', false, null::text, '', ''),
                    ('operation_previous_container_name', 'character varying(255)', false, null::text, '', ''),
                    ('operation_previous_container_id', 'character varying(64)', false, null::text, '', ''),
                    ('operation_candidate_container_name', 'character varying(255)', false, null::text, '', ''),
                    ('operation_candidate_container_id', 'character varying(64)', false, null::text, '', ''),
                    ('operation_rollback_managed_filename', 'character varying(255)', false, null::text, '', ''),
                    ('operation_routing_mutated_at', 'timestamp(0) without time zone', false, null::text, '', ''),
                    ('operation_legacy_routing_snapshot_version', 'smallint', false, null::text, '', ''),
                    ('operation_legacy_routing_snapshot', 'text', false, null::text, '', ''),
                    ('operation_legacy_routing_snapshot_sha256', 'character varying(64)', false, null::text, '', ''),
                    ('phase', 'character varying(255)', true, '''idle''::character varying', '', ''),
                    ('routing_revision', 'bigint', true, '''0''::bigint', '', ''),
                    ('created_at', 'timestamp(0) without time zone', false, null::text, '', ''),
                    ('updated_at', 'timestamp(0) without time zone', false, null::text, '', '')
            ),
            actual_all as (
                select
                    attribute.attname::text as name,
                    format_type(attribute.atttypid, attribute.atttypmod) as formatted_type,
                    attribute.attnotnull as not_null,
                    case when attribute.attname = 'id'
                        then '<owned-sequence>'
                        else pg_get_expr(attribute_default.adbin, attribute_default.adrelid)
                    end as default_expression,
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
                  and attribute.attnum > 0
                  and not attribute.attisdropped
            ),
            actual_owned as (
                select actual_all.* from actual_all join expected using (name)
            ),
            index_catalog as (
                select
                    index_relation.relname as index_name,
                    index_definition.*,
                    access_method.amname as access_method,
                    array(
                        select opclass_namespace.nspname || '.' || opclass.opcname
                        from unnest(index_definition.indclass::oid[]) with ordinality
                            as selected_opclass(opclass_oid, position)
                        join pg_opclass as opclass on opclass.oid = selected_opclass.opclass_oid
                        join pg_namespace as opclass_namespace on opclass_namespace.oid = opclass.opcnamespace
                        order by selected_opclass.position
                    ) as opclass_name,
                    not exists (
                        select 1
                        from generate_series(0, index_definition.indnkeyatts - 1) as key_position(position)
                        join pg_attribute as indexed_attribute
                          on indexed_attribute.attrelid = index_definition.indrelid
                         and indexed_attribute.attnum = index_definition.indkey[position]
                        where index_definition.indcollation[position] <> indexed_attribute.attcollation
                    ) as collation_exact,
                    not exists (
                        select 1
                        from generate_series(0, index_definition.indnkeyatts - 1) as key_position(position)
                        where index_definition.indoption[position] <> 0
                    ) as order_exact
                from pg_index as index_definition
                join pg_class as index_relation on index_relation.oid = index_definition.indexrelid
                join pg_class as table_relation on table_relation.oid = index_definition.indrelid
                join pg_namespace as namespace on namespace.oid = table_relation.relnamespace
                join pg_am as access_method on access_method.oid = index_relation.relam
                where namespace.nspname = current_schema()
                  and table_relation.relname = 'application_blue_green_deployments'
            )
            select
                not exists (
                    (select * from expected except all select * from actual_owned)
                    union all
                    (select * from actual_owned except all select * from expected)
                )
                and not exists (
                    select 1 from actual_all
                    where name not in (select name from expected)
                      and name not in (
                          'deactivation_operation_id',
                          'deactivation_started_at',
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
                          'supersession_generation',
                          'operation_drain_started_at',
                          'operation_drain_deadline_at',
                          'operation_drain_last_observed_connections',
                          'operation_drain_observed_at',
                          'operation_previous_proxy_state',
                          'operation_previous_proxy_state_sha256',
                          'operation_rollback_proxy_state',
                          'operation_rollback_proxy_state_sha256'
                      )
                )
                and (select count(*) from actual_all
                    where name in ('deactivation_operation_id', 'deactivation_started_at')) in (0, 2)
                and (select count(*) from actual_all
                    where name in (
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
                        'operation_previous_managed_file_sha256'
                    )) in (0, 12)
                and (select count(*) from actual_all
                    where name = 'supersession_generation') in (0, 1)
                and (select count(*) from actual_all
                    where name in (
                        'operation_drain_started_at',
                        'operation_drain_deadline_at',
                        'operation_drain_last_observed_connections',
                        'operation_drain_observed_at'
                    )) in (0, 4)
                and (select count(*) from actual_all
                    where name in (
                        'operation_previous_proxy_state',
                        'operation_previous_proxy_state_sha256',
                        'operation_rollback_proxy_state',
                        'operation_rollback_proxy_state_sha256'
                    )) in (0, 4)
                and (select count(*) = 4
                    and count(*) filter (where conname = 'application_blue_green_deployments_pkey'
                        and contype = 'p' and conkey = array[1]::smallint[]
                        and not condeferrable and not condeferred and convalidated) = 1
                    and count(*) filter (where conname = 'app_blue_green_destination_unique'
                        and contype = 'u' and conkey = array[2, 3]::smallint[]
                        and not condeferrable and not condeferred and convalidated) = 1
                    and count(*) filter (where conname = 'application_blue_green_deployments_application_id_foreign'
                        and contype = 'f' and conkey = array[2]::smallint[]
                        and confrelid = 'applications'::regclass and confkey = array[1]::smallint[]
                        and confupdtype = 'a' and confdeltype = 'c' and confmatchtype = 's'
                        and not condeferrable and not condeferred and convalidated) = 1
                    and count(*) filter (where conname = 'application_blue_green_deployments_standalone_docker_id_foreign'
                        and contype = 'f' and conkey = array[3]::smallint[]
                        and confrelid = 'standalone_dockers'::regclass and confkey = array[1]::smallint[]
                        and confupdtype = 'a' and confdeltype = 'c' and confmatchtype = 's'
                        and not condeferrable and not condeferred and convalidated) = 1
                    from pg_constraint
                    where conrelid = 'application_blue_green_deployments'::regclass)
                and (select count(*) = 3
                    and bool_and(indisvalid and indisready and indislive
                        and indexprs is null and indpred is null
                        and indnatts = indnkeyatts and access_method = 'btree'
                        and collation_exact and order_exact)
                    and count(*) filter (where index_name = 'application_blue_green_deployments_pkey'
                        and indisprimary and indisunique and indkey::text = '1'
                        and opclass_name = array['pg_catalog.int8_ops']::text[]) = 1
                    and count(*) filter (where index_name = 'app_blue_green_destination_unique'
                        and not indisprimary and indisunique and indkey::text = '2 3'
                        and opclass_name = array['pg_catalog.int8_ops', 'pg_catalog.int8_ops']::text[]) = 1
                    and count(*) filter (where index_name = 'app_blue_green_standalone_docker_index'
                        and not indisprimary and not indisunique and indkey::text = '3'
                        and opclass_name = array['pg_catalog.int8_ops']::text[]) = 1
                    from index_catalog)
            SQL, [], false);

        if ($schemaIsExact !== true) {
            throw new RuntimeException('Existing blue-green deployment PostgreSQL catalog does not match the authorized schema.');
        }

        $this->assertOwnedIdSequence();
    }

    private function assertOwnedIdSequence(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        $idSequenceIsExact = Schema::getConnection()->scalar(<<<'SQL'
            with target_column as (
                select
                    table_class.oid as table_oid,
                    table_attribute.attnum,
                    column_default.oid as default_oid,
                    column_default.adbin
                from pg_class as table_class
                join pg_namespace as table_namespace
                  on table_namespace.oid = table_class.relnamespace
                join pg_attribute as table_attribute
                  on table_attribute.attrelid = table_class.oid
                 and table_attribute.attname = 'id'
                 and table_attribute.attnum > 0
                 and not table_attribute.attisdropped
                join pg_attrdef as column_default
                  on column_default.adrelid = table_class.oid
                 and column_default.adnum = table_attribute.attnum
                where table_namespace.nspname = current_schema()
                  and table_class.relname = ?
            ),
            owned_sequence as (
                select sequence_class.oid, target_column.*
                from target_column
                join pg_depend as ownership
                  on ownership.refclassid = 'pg_class'::regclass
                 and ownership.refobjid = target_column.table_oid
                 and ownership.refobjsubid = target_column.attnum
                 and ownership.deptype in ('a', 'i')
                join pg_class as sequence_class
                  on sequence_class.oid = ownership.objid
                 and sequence_class.relkind = 'S'
            ),
            default_sequence as (
                select sequence_class.oid
                from target_column
                join pg_depend as default_dependency
                  on default_dependency.classid = 'pg_attrdef'::regclass
                 and default_dependency.objid = target_column.default_oid
                 and default_dependency.refclassid = 'pg_class'::regclass
                 and default_dependency.deptype = 'n'
                join pg_class as sequence_class
                  on sequence_class.oid = default_dependency.refobjid
                 and sequence_class.relkind = 'S'
            ),
            sequence_parameters as (
                select sequence_catalog.*
                from owned_sequence
                join pg_sequence as sequence_catalog on sequence_catalog.seqrelid = owned_sequence.oid
            )
            select
                (select count(*) from owned_sequence) = 1
                and (select count(*) from default_sequence) = 1
                and (select oid from owned_sequence) = (select oid from default_sequence)
                and (select pg_get_expr(adbin, table_oid) from target_column)
                    = (select format('nextval(%L::regclass)', oid::regclass::text) from owned_sequence)
                and (select count(*) = 1 and bool_and(
                    seqtypid = 'bigint'::regtype
                    and seqstart = 1 and seqincrement = 1
                    and seqmin = 1 and seqmax = 9223372036854775807
                    and seqcache = 1 and not seqcycle
                ) from sequence_parameters)
            SQL, ['application_blue_green_deployments'], false);

        if (! $idSequenceIsExact) {
            throw new RuntimeException('Existing blue-green deployment ID sequence ownership/default binding does not match.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_12_000001_create_application_blue_green_deployments_table.');
    }
};
