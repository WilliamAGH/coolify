<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('application_settings', 'blue_green_inactive_retention_seconds')) {
            Schema::table('application_settings', function (Blueprint $table): void {
                $table->unsignedInteger('blue_green_inactive_retention_seconds')
                    ->default(DEFAULT_BLUE_GREEN_INACTIVE_RETENTION_SECONDS);
            });
        }

        $this->assertExactSchema();
    }

    public function assertExactSchema(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $isExact = Schema::getConnection()->scalar(<<<'SQL'
                select count(*) = 1
                   and bool_and(format_type(attribute.atttypid, attribute.atttypmod) = 'integer')
                   and bool_and(attribute.attnotnull)
                   and bool_and(pg_get_expr(attribute_default.adbin, attribute_default.adrelid) = '''0''::integer')
                   and bool_and(attribute.attgenerated = '')
                   and bool_and(attribute.attidentity = '')
                from pg_attribute as attribute
                join pg_class as relation on relation.oid = attribute.attrelid
                join pg_namespace as namespace on namespace.oid = relation.relnamespace
                left join pg_attrdef as attribute_default
                  on attribute_default.adrelid = relation.oid
                 and attribute_default.adnum = attribute.attnum
                where namespace.nspname = current_schema()
                  and relation.relname = 'application_settings'
                  and attribute.attname = 'blue_green_inactive_retention_seconds'
                  and attribute.attnum > 0
                  and not attribute.attisdropped
                SQL, [], false);
            if ($isExact !== true) {
                throw new RuntimeException('Existing blue-green inactive retention setting does not match the authorized PostgreSQL catalog.');
            }

            return;
        }
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Unsupported database driver for blue-green inactive retention schema attestation.');
        }
        $column = collect(Schema::getColumns('application_settings'))
            ->firstWhere('name', 'blue_green_inactive_retention_seconds');
        $default = strtolower(trim((string) ($column['default'] ?? ''), "()'\""));
        if (! is_array($column)
            || ($column['nullable'] ?? null) !== false
            || ! in_array(strtolower((string) ($column['type_name'] ?? '')), ['int4', 'integer', 'int'], true)
            || strtolower((string) ($column['type'] ?? '')) !== 'integer'
            || $default !== '0') {
            throw new RuntimeException('Existing blue-green inactive retention setting does not match the authorized schema.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Application blue-green expand migration is forward-only: 2026_07_19_120000_add_blue_green_inactive_retention_setting.');
    }
};
