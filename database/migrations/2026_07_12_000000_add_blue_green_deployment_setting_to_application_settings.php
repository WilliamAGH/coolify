<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('application_settings', 'is_blue_green_deployment_enabled')) {
            $this->assertExactSchema();

            return;
        }

        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('is_blue_green_deployment_enabled')->default(false);
        });
    }

    public function assertExactSchema(): void
    {
        if (! Schema::hasColumn('application_settings', 'is_blue_green_deployment_enabled')) {
            throw new RuntimeException('Authorized blue-green deployment setting is missing.');
        }
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $isExact = Schema::getConnection()->scalar(<<<'SQL'
                select count(*) = 1 and bool_and(
                    format_type(attribute.atttypid, attribute.atttypmod) = 'boolean'
                    and attribute.attnotnull
                    and pg_get_expr(attribute_default.adbin, attribute_default.adrelid) = 'false'
                    and attribute.attgenerated = ''
                    and attribute.attidentity = ''
                )
                from pg_attribute as attribute
                join pg_class as relation on relation.oid = attribute.attrelid
                join pg_namespace as namespace on namespace.oid = relation.relnamespace
                left join pg_attrdef as attribute_default
                  on attribute_default.adrelid = relation.oid
                 and attribute_default.adnum = attribute.attnum
                where namespace.nspname = current_schema()
                  and relation.relname = 'application_settings'
                  and attribute.attname = 'is_blue_green_deployment_enabled'
                  and attribute.attnum > 0
                  and not attribute.attisdropped
                SQL, [], false);

            if ($isExact !== true) {
                throw new RuntimeException('Existing blue-green deployment setting does not match the authorized PostgreSQL catalog.');
            }

            return;
        }

        $column = collect(Schema::getColumns('application_settings'))
            ->firstWhere('name', 'is_blue_green_deployment_enabled');
        $default = strtolower(trim((string) ($column['default'] ?? ''), "()'\""));

        if ($column === null
            || ! in_array($column['type_name'], ['bool', 'boolean', 'tinyint'], true)
            || $column['nullable']
            || ! in_array($default, ['0', 'false'], true)) {
            throw new RuntimeException('Existing blue-green deployment setting does not match the authorized schema.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_12_000000_add_blue_green_deployment_setting_to_application_settings.');
    }
};
