<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'inactive_retirement_owner_deployment_uuid',
        'inactive_retirement_color',
        'inactive_retirement_deployment_uuid',
        'inactive_retirement_container_id',
        'inactive_retirement_container_routing_revision',
        'inactive_retirement_owner_routing_revision',
        'inactive_retirement_supersession_generation',
        'inactive_retirement_destination_fence_epoch',
        'inactive_retirement_server_boot_id',
        'inactive_retirement_topology_digest',
        'inactive_retirement_routing_config_digest',
        'inactive_retirement_not_before_at',
        'inactive_retirement_drain_deadline_at',
        'inactive_retirement_stop_grace_seconds',
        'inactive_retirement_lease_seconds',
        'inactive_retirement_last_observed_connections',
        'inactive_retirement_observed_at',
        'inactive_retirement_attempts',
        'inactive_retirement_stopped_at',
        'inactive_retirement_intervention_required_at',
        'inactive_retirement_dispatch_reserved_until_at',
    ];

    public function up(): void
    {
        $present = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => Schema::hasColumn('application_blue_green_deployments', $column),
        ));
        if ($present === []) {
            Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
                $table->string('inactive_retirement_owner_deployment_uuid')->nullable();
                $table->string('inactive_retirement_color')->nullable();
                $table->string('inactive_retirement_deployment_uuid')->nullable();
                $table->string('inactive_retirement_container_id', 64)->nullable();
                $table->unsignedBigInteger('inactive_retirement_container_routing_revision')->nullable();
                $table->unsignedBigInteger('inactive_retirement_owner_routing_revision')->nullable();
                $table->unsignedBigInteger('inactive_retirement_supersession_generation')->nullable();
                $table->unsignedBigInteger('inactive_retirement_destination_fence_epoch')->nullable();
                $table->string('inactive_retirement_server_boot_id', 36)->nullable();
                $table->string('inactive_retirement_topology_digest', 64)->nullable();
                $table->string('inactive_retirement_routing_config_digest', 64)->nullable();
                $table->timestamp('inactive_retirement_not_before_at')->nullable();
                $table->timestamp('inactive_retirement_drain_deadline_at')->nullable();
                $table->unsignedInteger('inactive_retirement_stop_grace_seconds')->nullable();
                $table->unsignedBigInteger('inactive_retirement_lease_seconds')->nullable();
                $table->unsignedInteger('inactive_retirement_last_observed_connections')->nullable();
                $table->timestamp('inactive_retirement_observed_at')->nullable();
                $table->unsignedInteger('inactive_retirement_attempts')->default(0);
                $table->timestamp('inactive_retirement_stopped_at')->nullable();
                $table->timestamp('inactive_retirement_intervention_required_at')->nullable();
                $table->timestamp('inactive_retirement_dispatch_reserved_until_at')->nullable();
                $table->index(
                    ['inactive_retirement_not_before_at', 'inactive_retirement_stopped_at', 'inactive_retirement_intervention_required_at', 'inactive_retirement_dispatch_reserved_until_at'],
                    'app_blue_green_inactive_retirement_due_index',
                );
            });
        } elseif ($present !== self::COLUMNS) {
            throw new RuntimeException('Blue-green inactive retirement provenance columns are partial; refusing a non-convergent replay.');
        }

        $this->assertExactSchema();
    }

    public function assertExactSchema(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $columnsAreExact = Schema::getConnection()->scalar(<<<'SQL'
                with expected(name, formatted_type, not_null, default_expression, generated, identity) as (
                    values
                        ('inactive_retirement_owner_deployment_uuid', 'character varying(255)', false, null::text, '', ''),
                        ('inactive_retirement_color', 'character varying(255)', false, null::text, '', ''),
                        ('inactive_retirement_deployment_uuid', 'character varying(255)', false, null::text, '', ''),
                        ('inactive_retirement_container_id', 'character varying(64)', false, null::text, '', ''),
                        ('inactive_retirement_container_routing_revision', 'bigint', false, null::text, '', ''),
                        ('inactive_retirement_owner_routing_revision', 'bigint', false, null::text, '', ''),
                        ('inactive_retirement_supersession_generation', 'bigint', false, null::text, '', ''),
                        ('inactive_retirement_destination_fence_epoch', 'bigint', false, null::text, '', ''),
                        ('inactive_retirement_server_boot_id', 'character varying(36)', false, null::text, '', ''),
                        ('inactive_retirement_topology_digest', 'character varying(64)', false, null::text, '', ''),
                        ('inactive_retirement_routing_config_digest', 'character varying(64)', false, null::text, '', ''),
                        ('inactive_retirement_not_before_at', 'timestamp(0) without time zone', false, null::text, '', ''),
                        ('inactive_retirement_drain_deadline_at', 'timestamp(0) without time zone', false, null::text, '', ''),
                        ('inactive_retirement_stop_grace_seconds', 'integer', false, null::text, '', ''),
                        ('inactive_retirement_lease_seconds', 'bigint', false, null::text, '', ''),
                        ('inactive_retirement_last_observed_connections', 'integer', false, null::text, '', ''),
                        ('inactive_retirement_observed_at', 'timestamp(0) without time zone', false, null::text, '', ''),
                        ('inactive_retirement_attempts', 'integer', true, '0', '', ''),
                        ('inactive_retirement_stopped_at', 'timestamp(0) without time zone', false, null::text, '', ''),
                        ('inactive_retirement_intervention_required_at', 'timestamp(0) without time zone', false, null::text, '', ''),
                        ('inactive_retirement_dispatch_reserved_until_at', 'timestamp(0) without time zone', false, null::text, '', '')
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
            $indexIsExact = Schema::getConnection()->scalar(<<<'SQL'
                select count(*) = 1
                   and bool_and(not index_definition.indisprimary)
                   and bool_and(not index_definition.indisunique)
                   and bool_and(index_definition.indisvalid and index_definition.indisready and index_definition.indislive)
                   and bool_and(index_definition.indexprs is null and index_definition.indpred is null)
                   and bool_and(index_definition.indnatts = index_definition.indnkeyatts)
                   and bool_and(access_method.amname = 'btree')
                   and bool_and(array(
                       select attribute.attname::text
                       from unnest(index_definition.indkey::smallint[]) with ordinality as key(attribute_number, position)
                       join pg_attribute as attribute
                         on attribute.attrelid = index_definition.indrelid
                        and attribute.attnum = key.attribute_number
                       where key.position <= index_definition.indnkeyatts
                       order by key.position
                   ) = array[
                       'inactive_retirement_not_before_at',
                       'inactive_retirement_stopped_at',
                       'inactive_retirement_intervention_required_at',
                       'inactive_retirement_dispatch_reserved_until_at'
                   ]::text[])
                from pg_index as index_definition
                join pg_class as index_relation on index_relation.oid = index_definition.indexrelid
                join pg_class as table_relation on table_relation.oid = index_definition.indrelid
                join pg_namespace as namespace on namespace.oid = table_relation.relnamespace
                join pg_am as access_method on access_method.oid = index_relation.relam
                where namespace.nspname = current_schema()
                  and table_relation.relname = 'application_blue_green_deployments'
                  and index_relation.relname = 'app_blue_green_inactive_retirement_due_index'
                SQL, [], false);
            if ($columnsAreExact !== true || $indexIsExact !== true) {
                throw new RuntimeException('Blue-green inactive retirement provenance does not match the authorized PostgreSQL catalog.');
            }

            return;
        }
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Unsupported database driver for blue-green inactive retirement provenance attestation.');
        }

        $expected = [
            'inactive_retirement_owner_deployment_uuid' => ['types' => ['varchar', 'varchar2'], 'declared' => 'varchar'],
            'inactive_retirement_color' => ['types' => ['varchar', 'varchar2'], 'declared' => 'varchar'],
            'inactive_retirement_deployment_uuid' => ['types' => ['varchar', 'varchar2'], 'declared' => 'varchar'],
            'inactive_retirement_container_id' => ['types' => ['varchar', 'varchar2'], 'declared' => 'varchar'],
            'inactive_retirement_container_routing_revision' => ['types' => ['int8', 'integer', 'bigint'], 'declared' => 'integer'],
            'inactive_retirement_owner_routing_revision' => ['types' => ['int8', 'integer', 'bigint'], 'declared' => 'integer'],
            'inactive_retirement_supersession_generation' => ['types' => ['int8', 'integer', 'bigint'], 'declared' => 'integer'],
            'inactive_retirement_destination_fence_epoch' => ['types' => ['int8', 'integer', 'bigint'], 'declared' => 'integer'],
            'inactive_retirement_server_boot_id' => ['types' => ['varchar', 'varchar2'], 'declared' => 'varchar'],
            'inactive_retirement_topology_digest' => ['types' => ['varchar', 'varchar2'], 'declared' => 'varchar'],
            'inactive_retirement_routing_config_digest' => ['types' => ['varchar', 'varchar2'], 'declared' => 'varchar'],
            'inactive_retirement_not_before_at' => ['types' => ['timestamp', 'datetime'], 'declared' => 'datetime'],
            'inactive_retirement_drain_deadline_at' => ['types' => ['timestamp', 'datetime'], 'declared' => 'datetime'],
            'inactive_retirement_stop_grace_seconds' => ['types' => ['int4', 'integer', 'int'], 'declared' => 'integer'],
            'inactive_retirement_lease_seconds' => ['types' => ['int8', 'integer', 'bigint'], 'declared' => 'integer'],
            'inactive_retirement_last_observed_connections' => ['types' => ['int4', 'integer', 'int'], 'declared' => 'integer'],
            'inactive_retirement_observed_at' => ['types' => ['timestamp', 'datetime'], 'declared' => 'datetime'],
            'inactive_retirement_attempts' => ['types' => ['int4', 'integer', 'int'], 'declared' => 'integer', 'nullable' => false, 'defaultZero' => true],
            'inactive_retirement_stopped_at' => ['types' => ['timestamp', 'datetime'], 'declared' => 'datetime'],
            'inactive_retirement_intervention_required_at' => ['types' => ['timestamp', 'datetime'], 'declared' => 'datetime'],
            'inactive_retirement_dispatch_reserved_until_at' => ['types' => ['timestamp', 'datetime'], 'declared' => 'datetime'],
        ];
        $columns = collect(Schema::getColumns('application_blue_green_deployments'))->keyBy('name');
        foreach ($expected as $name => $shape) {
            $column = $columns->get($name);
            $nullable = $shape['nullable'] ?? true;
            if (! is_array($column)
                || ($column['nullable'] ?? null) !== $nullable
                || ! in_array(strtolower((string) ($column['type_name'] ?? '')), $shape['types'], true)
                || strtolower((string) ($column['type'] ?? '')) !== $shape['declared']) {
                throw new RuntimeException("Blue-green inactive retirement provenance column does not match the authorized schema: {$name}.");
            }
            $default = $column['default'] ?? null;
            if (($shape['defaultZero'] ?? false) === true) {
                if (strtolower(trim((string) $default, "()'\"")) !== '0') {
                    throw new RuntimeException("Blue-green inactive retirement provenance default does not match: {$name}.");
                }
            } elseif ($default !== null) {
                throw new RuntimeException("Blue-green inactive retirement provenance has an unexpected default: {$name}.");
            }
        }
        $index = collect(Schema::getIndexes('application_blue_green_deployments'))
            ->firstWhere('name', 'app_blue_green_inactive_retirement_due_index');
        if (! is_array($index)
            || ($index['unique'] ?? null) !== false
            || ($index['primary'] ?? null) !== false
            || $index['columns'] !== ['inactive_retirement_not_before_at', 'inactive_retirement_stopped_at', 'inactive_retirement_intervention_required_at', 'inactive_retirement_dispatch_reserved_until_at']) {
            throw new RuntimeException('Blue-green inactive retirement due index does not match the authorized schema.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Application blue-green expand migration is forward-only: 2026_07_19_120001_add_blue_green_inactive_retirement_provenance.');
    }
};
