<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasProvenanceColumn = collect(Schema::getColumns('application_deployment_queues'))
            ->contains(fn (array $column): bool => str_starts_with($column['name'], 'blue_green_'));
        if ($hasProvenanceColumn) {
            $this->assertExactSchema();

            return;
        }

        Schema::table('application_deployment_queues', function (Blueprint $table) {
            $table->string('blue_green_color')->nullable();
            $table->string('blue_green_phase')->nullable();
            $table->unsignedBigInteger('blue_green_routing_revision')->nullable();
            $table->string('blue_green_previous_container_id', 64)->nullable();
            $table->string('blue_green_candidate_container_id', 64)->nullable();
            $table->string('blue_green_rollback_managed_filename')->nullable();
            $table->timestamp('blue_green_routing_mutated_at')->nullable();
        });
    }

    public function assertExactSchema(): void
    {
        $expectedColumns = [
            'blue_green_color' => ['typeNames' => ['varchar', 'varchar2'], 'varcharLength' => 255],
            'blue_green_phase' => ['typeNames' => ['varchar', 'varchar2'], 'varcharLength' => 255],
            'blue_green_routing_revision' => ['typeNames' => ['int8', 'integer', 'bigint']],
            'blue_green_previous_container_id' => ['typeNames' => ['varchar', 'varchar2'], 'varcharLength' => 64],
            'blue_green_candidate_container_id' => ['typeNames' => ['varchar', 'varchar2'], 'varcharLength' => 64],
            'blue_green_rollback_managed_filename' => ['typeNames' => ['varchar', 'varchar2'], 'varcharLength' => 255],
            'blue_green_routing_mutated_at' => ['typeNames' => ['timestamp', 'datetime']],
        ];
        $columns = collect(Schema::getColumns('application_deployment_queues'))->keyBy('name');
        $present = collect(array_keys($expectedColumns))->filter(fn (string $name) => $columns->has($name));
        $laterOwnedColumnGroups = [
            [
                'blue_green_destination_fence_epoch',
                'blue_green_server_boot_id',
                'blue_green_topology_digest',
                'blue_green_routing_config_digest',
            ],
            ['blue_green_supersession_generation'],
        ];
        if ($present->count() !== count($expectedColumns)) {
            throw new RuntimeException($present->isEmpty()
                ? 'Queue provenance schema is missing.'
                : 'Queue provenance schema is partial; refusing a non-convergent replay.');
        }
        $hasPartialLaterOwnedGroup = collect($laterOwnedColumnGroups)->contains(function (array $group) use ($columns): bool {
            $presentCount = collect($group)->filter(fn (string $name) => $columns->has($name))->count();

            return ! in_array($presentCount, [0, count($group)], true);
        });
        if ($hasPartialLaterOwnedGroup) {
            throw new RuntimeException('Later-owned queue provenance schema is partial; refusing a non-convergent replay.');
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'pgsql') {
            $isExact = Schema::getConnection()->scalar(<<<'SQL'
                with expected(name, formatted_type, not_null, default_expression, generated, identity) as (
                    values
                        ('blue_green_color', 'character varying(255)', false, null::text, '', ''),
                        ('blue_green_phase', 'character varying(255)', false, null::text, '', ''),
                        ('blue_green_routing_revision', 'bigint', false, null::text, '', ''),
                        ('blue_green_previous_container_id', 'character varying(64)', false, null::text, '', ''),
                        ('blue_green_candidate_container_id', 'character varying(64)', false, null::text, '', ''),
                        ('blue_green_rollback_managed_filename', 'character varying(255)', false, null::text, '', ''),
                        ('blue_green_routing_mutated_at', 'timestamp(0) without time zone', false, null::text, '', '')
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
                      and attribute.attname in (
                          'blue_green_color',
                          'blue_green_phase',
                          'blue_green_routing_revision',
                          'blue_green_previous_container_id',
                          'blue_green_candidate_container_id',
                          'blue_green_rollback_managed_filename',
                          'blue_green_routing_mutated_at'
                      )
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
                throw new RuntimeException('Existing queue provenance columns do not match the authorized PostgreSQL catalog.');
            }

            return;
        }

        foreach ($expectedColumns as $name => $expected) {
            $column = $columns->get($name);
            $expectedVarcharType = match ($driver) {
                'sqlite' => isset($expected['varcharLength']) ? 'varchar' : null,
                default => throw new RuntimeException("Unsupported database driver for queue provenance attestation: {$driver}."),
            };

            if (! $column['nullable']
                || ! in_array($column['type_name'], $expected['typeNames'], true)
                || ($expectedVarcharType !== null && strtolower($column['type']) !== $expectedVarcharType)) {
                throw new RuntimeException("Existing queue provenance column does not match the authorized schema: {$name}.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_12_000002_add_blue_green_provenance_to_application_deployment_queues.');
    }
};
