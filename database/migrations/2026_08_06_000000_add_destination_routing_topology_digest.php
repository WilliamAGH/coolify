<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the durable destination routing-topology fence.
 *
 * The candidate container set and replica release key are immutable
 * prerequisites owned by the 2026-08-04 migration. This migration attests
 * those exact prerequisites but never creates, replaces, or repairs them.
 */
return new class extends Migration
{
    private const string REPLICA_RELEASE_KEY = 'application_blue_green_replica_release_unique';

    /** @var list<string> */
    private const array REPLICA_RELEASE_COLUMNS = [
        'deployment_uuid',
        'compose_service',
        'replica_index',
    ];

    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql'
            && DB::transactionLevel() === 0) {
            DB::transaction($this->runExpansion(...));

            return;
        }

        $this->runExpansion();
    }

    public function down(): void
    {
        throw new RuntimeException('Control-plane expand migration is forward-only: 2026_08_06_000000_add_destination_routing_topology_digest.');
    }

    public function assertExactSchema(): void
    {
        $this->assertDigestColumnIfPresent(required: true);
        $this->assertCandidateContainerSetPrerequisite();
        $this->assertReplicaReleaseKeyPrerequisite();
    }

    private function runExpansion(): void
    {
        $this->assertDigestColumnIfPresent();
        $this->assertCandidateContainerSetPrerequisite();
        $this->assertReplicaReleaseKeyPrerequisite();

        if (! Schema::hasColumn('application_blue_green_deployments', 'destination_routing_topology_digest')) {
            Schema::table('application_blue_green_deployments', function (Blueprint $table): void {
                $table->string('destination_routing_topology_digest', 64)->nullable();
            });
        }

        $this->assertExactSchema();
    }

    private function assertDigestColumnIfPresent(bool $required = false): void
    {
        $column = $this->column('application_blue_green_deployments', 'destination_routing_topology_digest');
        if ($column === null && ! $required) {
            return;
        }
        if ($column === null || ! $this->isExactColumn(
            $column,
            'application_blue_green_deployments',
            'destination_routing_topology_digest',
            'varchar',
            'character varying(64)',
            'varchar',
            true,
        )) {
            throw new RuntimeException('The destination routing topology digest does not match the authorized database catalog.');
        }
    }

    private function assertCandidateContainerSetPrerequisite(): void
    {
        $candidateSet = $this->column('application_blue_green_deployments', 'operation_candidate_container_set');
        $candidateName = $this->column('application_blue_green_deployments', 'operation_candidate_container_name');
        $candidateId = $this->column('application_blue_green_deployments', 'operation_candidate_container_id');
        if ($candidateSet === null || ! $this->isExactColumn(
            $candidateSet,
            'application_blue_green_deployments',
            'operation_candidate_container_set',
            'text',
            'text',
            'text',
            true,
        ) || $candidateName === null || ! $this->isExactColumn(
            $candidateName,
            'application_blue_green_deployments',
            'operation_candidate_container_name',
            'varchar',
            'character varying(255)',
            'varchar',
            true,
        ) || $candidateId === null || ! $this->isExactColumn(
            $candidateId,
            'application_blue_green_deployments',
            'operation_candidate_container_id',
            'varchar',
            'character varying(64)',
            'varchar',
            true,
        )) {
            throw new RuntimeException('The released candidate container set migration prerequisite is absent or malformed.');
        }
    }

    private function assertReplicaReleaseKeyPrerequisite(): void
    {
        if (! Schema::hasTable('application_blue_green_replicas')) {
            throw new RuntimeException('The released replica ledger prerequisite is absent.');
        }
        $deploymentUuid = $this->column('application_blue_green_replicas', 'deployment_uuid');
        $composeService = $this->column('application_blue_green_replicas', 'compose_service');
        $replicaIndex = $this->column('application_blue_green_replicas', 'replica_index');
        if ($deploymentUuid === null || ! $this->isExactColumn(
            $deploymentUuid,
            'application_blue_green_replicas',
            'deployment_uuid',
            'varchar',
            'character varying(255)',
            'varchar',
            false,
        ) || $composeService === null || ! $this->isExactColumn(
            $composeService,
            'application_blue_green_replicas',
            'compose_service',
            'varchar',
            'character varying(255)',
            'varchar',
            false,
        ) || $replicaIndex === null || ! $this->isExactColumn(
            $replicaIndex,
            'application_blue_green_replicas',
            'replica_index',
            'int2',
            'smallint',
            'integer',
            false,
        )) {
            throw new RuntimeException('The released replica release key prerequisite is absent or malformed.');
        }

        $indexes = collect(Schema::getIndexes('application_blue_green_replicas'))
            ->where('name', self::REPLICA_RELEASE_KEY)
            ->values();
        $index = $indexes->first();
        if ($indexes->count() !== 1
            || ! is_array($index)
            || $index['columns'] !== self::REPLICA_RELEASE_COLUMNS
            || $index['unique'] !== true
            || $index['primary'] !== false) {
            throw new RuntimeException('The released replica release key prerequisite is absent or malformed.');
        }

        match (Schema::getConnection()->getDriverName()) {
            'pgsql' => $this->assertPostgresReplicaReleaseKey(),
            'sqlite' => $this->assertSqliteReplicaReleaseKey(),
            default => throw new RuntimeException('Unsupported database driver for the replica release key prerequisite.'),
        };
    }

    private function assertPostgresReplicaReleaseKey(): void
    {
        $constraint = DB::selectOne(<<<'SQL'
            select
                constraint_record.condeferrable as deferrable,
                constraint_record.condeferred as initially_deferred,
                constraint_record.convalidated as validated,
                index_record.indisunique as unique,
                index_record.indisprimary as primary,
                index_record.indisvalid as valid,
                index_record.indisready as ready,
                coalesce((to_jsonb(index_record)->>'indnullsnotdistinct')::boolean, false) as nulls_not_distinct,
                string_agg(attribute.attname, ',' order by key_column.ordinality) as columns
            from pg_catalog.pg_constraint as constraint_record
            join pg_catalog.pg_class as relation
              on relation.oid = constraint_record.conrelid
            join pg_catalog.pg_namespace as namespace
              on namespace.oid = relation.relnamespace
            join pg_catalog.pg_index as index_record
              on index_record.indexrelid = constraint_record.conindid
            join lateral unnest(constraint_record.conkey) with ordinality as key_column(attribute_number, ordinality)
              on true
            join pg_catalog.pg_attribute as attribute
              on attribute.attrelid = relation.oid
             and attribute.attnum = key_column.attribute_number
            where namespace.nspname = current_schema()
              and relation.relname = 'application_blue_green_replicas'
              and constraint_record.conname = 'application_blue_green_replica_release_unique'
              and constraint_record.contype = 'u'
            group by
                constraint_record.condeferrable,
                constraint_record.condeferred,
                constraint_record.convalidated,
                index_record.indisunique,
                index_record.indisprimary,
                index_record.indisvalid,
                index_record.indisready,
                coalesce((to_jsonb(index_record)->>'indnullsnotdistinct')::boolean, false)
            SQL);
        if ($constraint === null
            || (bool) $constraint->deferrable
            || (bool) $constraint->initially_deferred
            || ! (bool) $constraint->validated
            || ! (bool) $constraint->unique
            || (bool) $constraint->primary
            || ! (bool) $constraint->valid
            || ! (bool) $constraint->ready
            || (bool) $constraint->nulls_not_distinct
            || explode(',', (string) $constraint->columns) !== self::REPLICA_RELEASE_COLUMNS) {
            throw new RuntimeException('The released PostgreSQL replica release key prerequisite is absent or malformed.');
        }
    }

    private function assertSqliteReplicaReleaseKey(): void
    {
        $index = DB::selectOne(<<<'SQL'
            select origin, partial
            from pragma_index_list('application_blue_green_replicas')
            where name = 'application_blue_green_replica_release_unique'
            SQL);
        $columns = DB::select(<<<'SQL'
            select seqno, name, "desc" as descending, coll, key
            from pragma_index_xinfo('application_blue_green_replica_release_unique')
            where key = 1
            order by seqno
            SQL);
        $exactColumns = array_map(static fn (object $column): array => [
            'name' => (string) $column->name,
            'descending' => (int) $column->descending,
            'collation' => strtolower((string) $column->coll),
        ], $columns);
        $expectedColumns = array_map(static fn (string $column): array => [
            'name' => $column,
            'descending' => 0,
            'collation' => 'binary',
        ], self::REPLICA_RELEASE_COLUMNS);

        if ($index === null
            || (string) $index->origin !== 'c'
            || (int) $index->partial !== 0
            || $exactColumns !== $expectedColumns) {
            throw new RuntimeException('The released SQLite replica release key prerequisite is absent or malformed.');
        }
    }

    /** @return array<string, mixed>|null */
    private function column(string $table, string $name): ?array
    {
        $column = collect(Schema::getColumns($table))->firstWhere('name', $name);

        return is_array($column) ? $column : null;
    }

    /** @param array<string, mixed> $column */
    private function isExactColumn(
        array $column,
        string $table,
        string $name,
        string $postgresTypeName,
        string $postgresType,
        string $sqliteType,
        bool $nullable,
    ): bool {
        $driver = Schema::getConnection()->getDriverName();
        $typeMatches = match ($driver) {
            'pgsql' => $column['type_name'] === $postgresTypeName && $column['type'] === $postgresType,
            'sqlite' => $column['type_name'] === $sqliteType && $column['type'] === $sqliteType,
            default => false,
        };
        $collationMatches = match ($driver) {
            'pgsql' => $this->postgresColumnUsesDefaultTypeCollation($table, $name),
            'sqlite' => in_array($column['collation'], [null, 'binary'], true),
            default => false,
        };

        return $typeMatches
            && $collationMatches
            && $column['nullable'] === $nullable
            && $column['default'] === null
            && $column['auto_increment'] === false
            && $column['generation'] === null;
    }

    private function postgresColumnUsesDefaultTypeCollation(string $table, string $name): bool
    {
        $column = DB::selectOne(<<<'SQL'
            select attribute.attcollation = type_record.typcollation as uses_default_type_collation
            from pg_catalog.pg_attribute as attribute
            join pg_catalog.pg_class as relation
              on relation.oid = attribute.attrelid
            join pg_catalog.pg_namespace as namespace
              on namespace.oid = relation.relnamespace
            join pg_catalog.pg_type as type_record
              on type_record.oid = attribute.atttypid
            where namespace.nspname = current_schema()
              and relation.relname = ?
              and attribute.attname = ?
              and attribute.attnum > 0
              and not attribute.attisdropped
            SQL, [$table, $name]);

        return $column !== null && (bool) $column->uses_default_type_collation;
    }
};
