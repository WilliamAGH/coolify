<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('application_blue_green_deactivations')) {
            $this->assertExactSchema();

            return;
        }

        Schema::create('application_blue_green_deactivations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('standalone_docker_id')->constrained()->cascadeOnDelete();
            $table->string('operation_id', 64);
            $table->timestamp('started_at');
            $table->unsignedBigInteger('queue_cutoff_id')->default(0);
            $table->string('phase')->default('deactivating');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['application_id', 'standalone_docker_id'], 'app_blue_green_deactivation_unique');
            $table->index('standalone_docker_id', 'app_blue_green_deactivation_docker_index');
            $table->index(['phase', 'started_at'], 'app_blue_green_deactivation_phase_index');
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
            throw new RuntimeException("Unsupported database driver for blue-green deactivation schema attestation: {$driver}.");
        }

        $expectedColumns = [
            'id' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false],
            'application_id' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false],
            'standalone_docker_id' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false],
            'operation_id' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => false, 'varcharLength' => 64],
            'started_at' => ['typeNames' => ['timestamp', 'datetime'], 'nullable' => false],
            'queue_cutoff_id' => ['typeNames' => ['int8', 'integer', 'bigint'], 'nullable' => false],
            'phase' => ['typeNames' => ['varchar', 'varchar2'], 'nullable' => false, 'varcharLength' => 255],
            'completed_at' => ['typeNames' => ['timestamp', 'datetime'], 'nullable' => true],
            'created_at' => ['typeNames' => ['timestamp', 'datetime'], 'nullable' => true],
            'updated_at' => ['typeNames' => ['timestamp', 'datetime'], 'nullable' => true],
        ];
        if (Schema::hasColumn('application_blue_green_deactivations', 'proxy_snapshot')) {
            $expectedColumns['proxy_snapshot'] = ['typeNames' => ['text', 'clob'], 'nullable' => true];
        }
        $columns = collect(Schema::getColumns('application_blue_green_deactivations'))->keyBy('name');

        $laterOwnedColumnGroups = [
            ['supersession_generation'],
            ['intervention_phase', 'intervention_reason'],
        ];
        $laterOwnedColumns = array_merge(...$laterOwnedColumnGroups);
        $columnNames = $columns->keys()->values()->all();
        $unexpectedColumns = array_diff($columnNames, [...array_keys($expectedColumns), ...$laterOwnedColumns]);
        $hasPartialLaterOwnedGroup = collect($laterOwnedColumnGroups)->contains(function (array $group) use ($columnNames): bool {
            $presentCount = count(array_intersect($group, $columnNames));

            return ! in_array($presentCount, [0, count($group)], true);
        });
        if ($unexpectedColumns !== []
            || array_diff(array_keys($expectedColumns), $columnNames) !== []
            || $hasPartialLaterOwnedGroup) {
            throw new RuntimeException('Existing blue-green deactivation table has an unexpected column set.');
        }

        foreach ($expectedColumns as $name => $expected) {
            $column = $columns->get($name);
            $expectedVarcharType = isset($expected['varcharLength']) ? 'varchar' : null;

            if ($column['nullable'] !== $expected['nullable']
                || ! in_array($column['type_name'], $expected['typeNames'], true)
                || ($expectedVarcharType !== null && strtolower($column['type']) !== $expectedVarcharType)) {
                throw new RuntimeException("Existing blue-green deactivation column does not match: {$name}.");
            }
        }

        if (! $columns->get('id')['auto_increment']) {
            throw new RuntimeException('Existing blue-green deactivation ID is not auto-incrementing.');
        }
        $this->assertOwnedIdSequence();

        $cutoffDefault = strtolower((string) $columns->get('queue_cutoff_id')['default']);
        $phaseDefault = strtolower((string) $columns->get('phase')['default']);
        if (! str_starts_with(trim($cutoffDefault, "()'\""), '0') || ! str_contains($phaseDefault, 'deactivating')) {
            throw new RuntimeException('Existing blue-green deactivation default does not match.');
        }

        $indexes = collect(Schema::getIndexes('application_blue_green_deactivations'));
        $expectedIndexes = [
            ['columns' => ['id'], 'unique' => true, 'primary' => true],
            ['name' => 'app_blue_green_deactivation_unique', 'columns' => ['application_id', 'standalone_docker_id'], 'unique' => true],
            ['name' => 'app_blue_green_deactivation_docker_index', 'columns' => ['standalone_docker_id'], 'unique' => false],
            ['name' => 'app_blue_green_deactivation_phase_index', 'columns' => ['phase', 'started_at'], 'unique' => false],
        ];
        foreach ($expectedIndexes as $expectedIndex) {
            $matches = $indexes->contains(fn (array $index) => collect($expectedIndex)
                ->every(fn (mixed $expected, string $key) => $index[$key] === $expected));
            if (! $matches) {
                throw new RuntimeException('Existing blue-green deactivation indexes do not match.');
            }
        }

        $foreignKeys = collect(Schema::getForeignKeys('application_blue_green_deactivations'));
        foreach ([
            ['columns' => ['application_id'], 'foreign_table' => 'applications'],
            ['columns' => ['standalone_docker_id'], 'foreign_table' => 'standalone_dockers'],
        ] as $expectedForeignKey) {
            $matches = $foreignKeys->contains(fn (array $foreignKey) => $foreignKey['columns'] === $expectedForeignKey['columns']
                && $foreignKey['foreign_table'] === $expectedForeignKey['foreign_table']
                && $foreignKey['foreign_columns'] === ['id']
                && $foreignKey['on_delete'] === 'cascade');
            if (! $matches) {
                throw new RuntimeException('Existing blue-green deactivation foreign keys do not match.');
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
                    ('operation_id', 'character varying(64)', true, null::text, '', ''),
                    ('started_at', 'timestamp(0) without time zone', true, null::text, '', ''),
                    ('queue_cutoff_id', 'bigint', true, '''0''::bigint', '', ''),
                    ('proxy_snapshot', 'text', false, null::text, '', ''),
                    ('phase', 'character varying(255)', true, '''deactivating''::character varying', '', ''),
                    ('completed_at', 'timestamp(0) without time zone', false, null::text, '', ''),
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
                  and relation.relname = 'application_blue_green_deactivations'
                  and attribute.attnum > 0
                  and not attribute.attisdropped
            ),
            actual as (
                select *
                from actual_all
                where name not in ('supersession_generation', 'intervention_phase', 'intervention_reason')
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
                  and table_relation.relname = 'application_blue_green_deactivations'
            )
            select
                not exists (
                    (select * from actual except all select * from expected)
                )
                and not exists (
                    select * from expected where name <> 'proxy_snapshot'
                    except all
                    select * from actual
                )
                and (select count(*) from actual_all
                    where name = 'supersession_generation') in (0, 1)
                and (select count(*) from actual_all
                    where name in ('intervention_phase', 'intervention_reason')) in (0, 2)
                and (select count(*) = 4
                    and count(*) filter (where conname = 'application_blue_green_deactivations_pkey'
                        and contype = 'p' and conkey = array[1]::smallint[]
                        and not condeferrable and not condeferred and convalidated) = 1
                    and count(*) filter (where conname = 'app_blue_green_deactivation_unique'
                        and contype = 'u' and conkey = array[2, 3]::smallint[]
                        and not condeferrable and not condeferred and convalidated) = 1
                    and count(*) filter (where conname = 'application_blue_green_deactivations_application_id_foreign'
                        and contype = 'f' and conkey = array[2]::smallint[]
                        and confrelid = 'applications'::regclass and confkey = array[1]::smallint[]
                        and confupdtype = 'a' and confdeltype = 'c' and confmatchtype = 's'
                        and not condeferrable and not condeferred and convalidated) = 1
                    and count(*) filter (where conname = 'application_blue_green_deactivations_standalone_docker_id_forei'
                        and contype = 'f' and conkey = array[3]::smallint[]
                        and confrelid = 'standalone_dockers'::regclass and confkey = array[1]::smallint[]
                        and confupdtype = 'a' and confdeltype = 'c' and confmatchtype = 's'
                        and not condeferrable and not condeferred and convalidated) = 1
                    from pg_constraint
                    where conrelid = 'application_blue_green_deactivations'::regclass
                      and conname <> 'app_blue_green_deactivations_generation_check')
                and (select count(*) = 4
                    and bool_and(indisvalid and indisready and indislive
                        and indexprs is null and indpred is null
                        and indnatts = indnkeyatts and access_method = 'btree'
                        and collation_exact and order_exact)
                    and count(*) filter (where index_name = 'application_blue_green_deactivations_pkey'
                        and indisprimary and indisunique and indkey::text = '1'
                        and opclass_name = array['pg_catalog.int8_ops']::text[]) = 1
                    and count(*) filter (where index_name = 'app_blue_green_deactivation_unique'
                        and not indisprimary and indisunique and indkey::text = '2 3'
                        and opclass_name = array['pg_catalog.int8_ops', 'pg_catalog.int8_ops']::text[]) = 1
                    and count(*) filter (where index_name = 'app_blue_green_deactivation_docker_index'
                        and not indisprimary and not indisunique and indkey::text = '3'
                        and opclass_name = array['pg_catalog.int8_ops']::text[]) = 1
                    and count(*) filter (where index_name = 'app_blue_green_deactivation_phase_index'
                        and not indisprimary and not indisunique and indkey::text = '7 5'
                        and opclass_name = array['pg_catalog.text_ops', 'pg_catalog.timestamp_ops']::text[]) = 1
                    from index_catalog)
            SQL, [], false);

        if ($schemaIsExact !== true) {
            throw new RuntimeException('Existing blue-green deactivation PostgreSQL catalog does not match the authorized schema.');
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
            SQL, ['application_blue_green_deactivations'], false);

        if (! $idSequenceIsExact) {
            throw new RuntimeException('Existing blue-green deactivation ID sequence ownership/default binding does not match.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_12_000011_create_application_blue_green_deactivations_table.');
    }
};
