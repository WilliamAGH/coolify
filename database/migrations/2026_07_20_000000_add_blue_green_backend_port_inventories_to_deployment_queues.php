<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const COLUMNS = [
        'blue_green_backend_port_inventory',
        'blue_green_drain_backend_port_inventory',
    ];

    public function up(): void
    {
        $present = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => Schema::hasColumn('application_deployment_queues', $column),
        ));
        if ($present === []) {
            Schema::table('application_deployment_queues', function (Blueprint $table): void {
                $table->text('blue_green_backend_port_inventory')->nullable();
                $table->text('blue_green_drain_backend_port_inventory')->nullable();
            });
        } elseif ($present !== self::COLUMNS) {
            throw new RuntimeException('Blue-green backend port inventory columns are partial; refusing a non-convergent replay.');
        }

        $this->assertExactSchema();
    }

    public function assertExactSchema(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $columnsAreExact = Schema::getConnection()->scalar(<<<'SQL'
                with expected(name, formatted_type, not_null, default_expression, generated, identity) as (
                    values
                        ('blue_green_backend_port_inventory', 'text', false, null::text, '', ''),
                        ('blue_green_drain_backend_port_inventory', 'text', false, null::text, '', '')
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
            if ($columnsAreExact !== true) {
                throw new RuntimeException('Blue-green backend port inventories do not match the authorized PostgreSQL catalog.');
            }

            return;
        }
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Unsupported database driver for blue-green backend port inventory attestation.');
        }

        $columns = collect(Schema::getColumns('application_deployment_queues'))->keyBy('name');
        foreach (self::COLUMNS as $name) {
            $column = $columns->get($name);
            if (! is_array($column)
                || ($column['nullable'] ?? null) !== true
                || ! in_array(strtolower((string) ($column['type_name'] ?? '')), ['text', 'clob'], true)
                || strtolower((string) ($column['type'] ?? '')) !== 'text'
                || ($column['default'] ?? null) !== null) {
                throw new RuntimeException("Blue-green backend port inventory column does not match the authorized schema: {$name}.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_20_000000_add_blue_green_backend_port_inventories_to_deployment_queues.');
    }
};
