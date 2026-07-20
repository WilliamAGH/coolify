<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql'
            && DB::transactionLevel() === 0) {
            DB::transaction($this->runExpansion(...));

            return;
        }

        $this->runExpansion();
    }

    private function runExpansion(): void
    {
        $this->addColumns();
        $this->assertExactSchema();
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_07_19_025449_add_blue_green_supersession_generation.');
    }

    public function assertExactSchema(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            $this->assertExactPostgresSchema();

            return;
        }

        $this->assertExactSqliteSchema();
    }

    private function addColumns(): void
    {
        $columns = [
            'application_blue_green_deployments' => 'supersession_generation',
            'application_blue_green_deactivations' => 'supersession_generation',
            'application_deployment_queues' => 'blue_green_supersession_generation',
        ];
        $present = array_filter(
            $columns,
            static fn (string $column, string $table): bool => Schema::hasColumn($table, $column),
            ARRAY_FILTER_USE_BOTH,
        );
        if ($present === []) {
            $this->lockOwnerTablesForExpansion();
            $this->assertNoUnversionedActiveOwners();
            Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
                $table->unsignedBigInteger('supersession_generation')->default(0);
            });
            Schema::table('application_blue_green_deactivations', function (Blueprint $table): void {
                $table->unsignedBigInteger('supersession_generation')->default(0);
            });
            Schema::table('application_deployment_queues', function (Blueprint $table): void {
                $table->unsignedBigInteger('blue_green_supersession_generation')->nullable();
            });
            DB::table('application_blue_green_deactivations')->update([
                'supersession_generation' => 1,
            ]);
            $this->addPostgresOwnerConstraints();

            return;
        }
        if (count($present) !== count($columns)) {
            throw new RuntimeException('Blue-green supersession-generation columns are partial; refusing a non-convergent replay.');
        }
    }

    private function lockOwnerTablesForExpansion(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            lock table
                application_blue_green_deployments,
                application_blue_green_deactivations,
                application_deployment_queues
            in access exclusive mode
            SQL);
    }

    private function addPostgresOwnerConstraints(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(<<<'SQL'
            alter table application_blue_green_deployments
            add constraint app_blue_green_deployments_generation_owner_check
            check (
                supersession_generation > 0
                or (
                    operation_deployment_uuid is null
                    and deactivation_operation_id is null
                )
            )
            SQL);
        DB::statement(<<<'SQL'
            alter table application_blue_green_deactivations
            add constraint app_blue_green_deactivations_generation_check
            check (supersession_generation > 0)
            SQL);
        DB::statement(<<<'SQL'
            alter table application_deployment_queues
            add constraint app_deployment_queues_blue_green_generation_check
            check (
                status <> 'in_progress'
                or blue_green_phase is null
                or (
                    blue_green_supersession_generation is not null
                    and blue_green_supersession_generation > 0
                )
            )
            SQL);
    }

    private function assertNoUnversionedActiveOwners(): void
    {
        $hasDeploymentOwner = Schema::hasColumn('application_blue_green_deployments', 'operation_deployment_uuid')
            && DB::table('application_blue_green_deployments')->whereNotNull('operation_deployment_uuid')->exists();
        $hasDeactivationOwner = Schema::hasColumn('application_blue_green_deactivations', 'phase')
            && DB::table('application_blue_green_deactivations')->where('phase', 'deactivating')->exists();
        $hasQueueOwner = Schema::hasColumn('application_deployment_queues', 'blue_green_phase')
            && Schema::hasColumn('application_deployment_queues', 'status')
            && DB::table('application_deployment_queues')
                ->whereNotNull('blue_green_phase')
                ->where('status', 'in_progress')
                ->exists();
        if ($hasDeploymentOwner || $hasDeactivationOwner || $hasQueueOwner) {
            throw new RuntimeException('Active unversioned blue-green owners must drain before supersession-generation expansion.');
        }
    }

    private function assertExactPostgresSchema(): void
    {
        $isExact = Schema::getConnection()->scalar(<<<'SQL'
            with expected(table_name, column_name, formatted_type, not_null, default_expression, generated, identity) as (
                values
                    ('application_blue_green_deployments', 'supersession_generation', 'bigint', true, '''0''::bigint', '', ''),
                    ('application_blue_green_deactivations', 'supersession_generation', 'bigint', true, '''0''::bigint', '', ''),
                    ('application_deployment_queues', 'blue_green_supersession_generation', 'bigint', false, null::text, '', '')
            ),
            actual as (
                select
                    relation.relname::text as table_name,
                    attribute.attname::text as column_name,
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
                  and (relation.relname, attribute.attname) in (
                      select table_name, column_name from expected
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
            throw new RuntimeException('Blue-green supersession-generation columns do not match the authorized PostgreSQL catalog.');
        }

        $this->assertExactPostgresConstraints();
    }

    private function assertExactPostgresConstraints(): void
    {
        $constraints = collect(Schema::getConnection()->select(<<<'SQL'
            select
                relation.relname::text as table_name,
                constraint_record.conname::text as constraint_name,
                constraint_record.convalidated as validated,
                pg_get_constraintdef(constraint_record.oid, true) as definition
            from pg_constraint as constraint_record
            join pg_class as relation on relation.oid = constraint_record.conrelid
            join pg_namespace as namespace on namespace.oid = relation.relnamespace
            where namespace.nspname = current_schema()
              and constraint_record.conname in (
                  'app_blue_green_deployments_generation_owner_check',
                  'app_blue_green_deactivations_generation_check',
                  'app_deployment_queues_blue_green_generation_check'
              )
              and constraint_record.contype = 'c'
            order by constraint_record.conname
            SQL))->map(static function (object $constraint): array {
            $definition = strtolower((string) $constraint->definition);
            $definition = str_replace(
                ['::character varying', '::varchar', '::text', '::bigint', '::integer'],
                '',
                $definition,
            );
            $definition = preg_replace('/[()\s"]+/', '', $definition);

            return [
                'table' => (string) $constraint->table_name,
                'name' => (string) $constraint->constraint_name,
                'validated' => (bool) $constraint->validated,
                'definition' => $definition,
            ];
        })->values()->all();
        $expected = [
            [
                'table' => 'application_blue_green_deactivations',
                'name' => 'app_blue_green_deactivations_generation_check',
                'validated' => true,
                'definition' => 'checksupersession_generation>0',
            ],
            [
                'table' => 'application_blue_green_deployments',
                'name' => 'app_blue_green_deployments_generation_owner_check',
                'validated' => true,
                'definition' => 'checksupersession_generation>0oroperation_deployment_uuidisnullanddeactivation_operation_idisnull',
            ],
            [
                'table' => 'application_deployment_queues',
                'name' => 'app_deployment_queues_blue_green_generation_check',
                'validated' => true,
                'definition' => "checkstatus<>'in_progress'orblue_green_phaseisnullorblue_green_supersession_generationisnotnullandblue_green_supersession_generation>0",
            ],
        ];
        if ($constraints !== $expected) {
            throw new RuntimeException('Blue-green supersession-generation owner constraints do not match the authorized PostgreSQL catalog.');
        }
    }

    private function assertExactSqliteSchema(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            throw new RuntimeException('Unsupported database driver for blue-green supersession-generation schema attestation.');
        }

        $this->assertExactSqliteColumn('application_blue_green_deployments', 'supersession_generation', false, true);
        $this->assertExactSqliteColumn('application_blue_green_deactivations', 'supersession_generation', false, true);
        $this->assertExactSqliteColumn('application_deployment_queues', 'blue_green_supersession_generation', true, false);
    }

    private function assertExactSqliteColumn(
        string $table,
        string $name,
        bool $nullable,
        bool $defaultZero,
    ): void {
        $column = collect(Schema::getColumns($table))->firstWhere('name', $name);
        if (! is_array($column)
            || $column['nullable'] !== $nullable
            || ! in_array($column['type_name'], ['int8', 'integer', 'bigint'], true)) {
            throw new RuntimeException("Blue-green supersession-generation column does not match the authorized SQLite schema: {$table}.{$name}.");
        }
        $default = $column['default'];
        if ($defaultZero) {
            if (trim(strtolower((string) $default), "()'\"") !== '0') {
                throw new RuntimeException("Blue-green supersession-generation column default does not match: {$table}.{$name}.");
            }

            return;
        }
        if ($default !== null) {
            throw new RuntimeException("Blue-green supersession-generation column has an unexpected default: {$table}.{$name}.");
        }
    }
};
