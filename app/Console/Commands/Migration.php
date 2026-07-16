<?php

namespace App\Console\Commands;

use App\Support\ControlPlaneMigrationInventory;
use App\Support\ControlPlaneMode;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration as DatabaseMigration;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

class Migration extends Command
{
    private const CONTROL_PLANE_MIGRATION_APPLICATION_NAME = 'coolify-control-plane-expand';

    private const CONTROL_PLANE_MIGRATION_ATTEMPT_APPLICATION_PREFIX = 'coolify-control-plane-expand-';

    private const CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_PREFIX = 'coolify-control-plane-expand-attempt:';

    private const GLOBAL_SCHEMA_MIGRATION_LOCK = 'coolify-control-plane-expand';

    protected $signature = 'start:migration
        {--control-plane-expand : Run only the authorized control-plane expand migrations}
        {--print-control-plane-migration-inventory : Print the authorized control-plane migration inventory}';

    protected $description = 'Start Migration';

    public function handle(): int
    {
        if ($this->option('print-control-plane-migration-inventory')) {
            foreach (self::controlPlaneMigrationNames() as $migrationName) {
                $this->line($migrationName);
            }

            return self::SUCCESS;
        }

        if (! ControlPlaneMode::startupWorkAllowed()) {
            $this->info('Control plane startup work is disabled: migration skipped.');

            return self::SUCCESS;
        }

        if (! config('constants.migration.is_migration_enabled')) {
            $this->info('Migration is disabled on this server.');

            return self::SUCCESS;
        }

        $this->info('Migration is enabled on this server.');
        if ($this->option('control-plane-expand') && ! $this->controlPlaneMigrationModeIsValid()) {
            return self::FAILURE;
        }

        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql') {
            if ($this->option('control-plane-expand')) {
                $this->error('Control-plane expand requires PostgreSQL.');

                return self::FAILURE;
            }

            return $this->call('migrate', ['--force' => true, '--isolated' => 1]);
        }

        $connection->useWriteConnectionWhenReading();
        try {
            $lockedPdo = $connection->getPdo();
            if (! $lockedPdo instanceof PDO) {
                $this->error('Schema migration did not resolve a PostgreSQL PDO session.');

                return self::FAILURE;
            }

            if (! $this->option('control-plane-expand')) {
                return $this->withGlobalSchemaMigrationLock(
                    $connection,
                    $lockedPdo,
                    fn (): int => $this->call('migrate', ['--force' => true, '--isolated' => 1]),
                );
            }

            $migrationAttemptIdentity = $this->controlPlaneMigrationAttemptIdentity();
            if ($migrationAttemptIdentity === null) {
                return self::FAILURE;
            }

            $backendPid = $this->configureControlPlaneMigrationSession(
                $connection,
                $lockedPdo,
                $migrationAttemptIdentity['applicationName'],
            );
            if ($backendPid === null) {
                return self::FAILURE;
            }

            $authorizedMigrationNames = self::controlPlaneMigrationNames();

            return $this->withGlobalSchemaMigrationLock($connection, $lockedPdo, function () use (
                $connection,
                $lockedPdo,
                $backendPid,
                $authorizedMigrationNames,
                $migrationAttemptIdentity,
            ): int {
                $runControlPlaneExpand = fn (): int => $this->runControlPlaneExpand(
                    $connection,
                    $lockedPdo,
                    $backendPid,
                    $migrationAttemptIdentity['applicationName'],
                    $authorizedMigrationNames,
                );

                if ($migrationAttemptIdentity['advisoryLockIdentity'] === null) {
                    return $runControlPlaneExpand();
                }

                return $this->withControlPlaneMigrationAttemptLock(
                    $connection,
                    $lockedPdo,
                    $migrationAttemptIdentity['advisoryLockIdentity'],
                    $runControlPlaneExpand,
                );
            });
        } finally {
            $connection->useWriteConnectionWhenReading(false);
        }
    }

    private function controlPlaneMigrationModeIsValid(): bool
    {
        $isLiveExpand = filter_var(env('CONTROL_PLANE_LIVE_EXPAND_MIGRATION', false), FILTER_VALIDATE_BOOL);
        $isRehearsal = filter_var(env('CONTROL_PLANE_MIGRATION_REHEARSAL', false), FILTER_VALIDATE_BOOL);
        if ($isLiveExpand === $isRehearsal) {
            $this->error('Control-plane expand requires exactly one live or rehearsal mode.');

            return false;
        }

        return true;
    }

    /** @return array{applicationName: string, advisoryLockIdentity: ?string}|null */
    private function controlPlaneMigrationAttemptIdentity(): ?array
    {
        $isLiveExpand = filter_var(env('CONTROL_PLANE_LIVE_EXPAND_MIGRATION', false), FILTER_VALIDATE_BOOL);
        if (! $isLiveExpand) {
            return [
                'applicationName' => self::CONTROL_PLANE_MIGRATION_APPLICATION_NAME,
                'advisoryLockIdentity' => null,
            ];
        }

        $operationId = env('CONTROL_PLANE_MIGRATION_OPERATION_ID');
        $attempt = env('CONTROL_PLANE_MIGRATION_ATTEMPT');
        $providedApplicationName = env('CONTROL_PLANE_MIGRATION_APPLICATION_NAME');
        $providedAdvisoryLockIdentity = env('CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY');
        if (! is_string($operationId)
            || preg_match('/\A[A-Za-z0-9._:-]{16,128}\z/', $operationId) !== 1
            || ! is_string($attempt)
            || preg_match('/\A[1-9][0-9]*\z/', $attempt) !== 1
            || ! is_string($providedApplicationName)
            || ! is_string($providedAdvisoryLockIdentity)) {
            $this->error('Control-plane live migration operation or attempt identity is missing or malformed.');

            return null;
        }

        $identityDigest = substr(hash('sha256', "{$operationId}:{$attempt}\n"), 0, 32);
        $applicationName = self::CONTROL_PLANE_MIGRATION_ATTEMPT_APPLICATION_PREFIX.$identityDigest;
        $advisoryLockIdentity = self::CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_PREFIX.$identityDigest;
        if (! hash_equals($applicationName, $providedApplicationName)
            || ! hash_equals($advisoryLockIdentity, $providedAdvisoryLockIdentity)) {
            $this->error('Control-plane live migration operation or attempt identity does not match its exact derived values.');

            return null;
        }

        $this->line('control-plane-migration-attempt-lock='.$advisoryLockIdentity);

        return [
            'applicationName' => $applicationName,
            'advisoryLockIdentity' => $advisoryLockIdentity,
        ];
    }

    private function configureControlPlaneMigrationSession(
        Connection $connection,
        PDO $lockedPdo,
        string $applicationName,
    ): ?int {
        $lockTimeout = env('CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT');
        $statementTimeout = env('CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT');
        if (! is_string($lockTimeout)
            || preg_match('/\A[1-9][0-9]*(?:ms|s)\z/', $lockTimeout) !== 1
            || ! is_string($statementTimeout)
            || preg_match('/\A[1-9][0-9]*(?:ms|s)\z/', $statementTimeout) !== 1) {
            $this->error('Control-plane migration timeouts are missing or malformed.');

            return null;
        }

        $configuredSession = $connection->selectOne(<<<'SQL'
            SELECT
                set_config('lock_timeout', ?, false) AS lock_timeout,
                set_config('statement_timeout', ?, false) AS statement_timeout,
                set_config('application_name', ?, false) AS application_name,
                pg_backend_pid() AS backend_pid
            SQL, [
            $lockTimeout,
            $statementTimeout,
            $applicationName,
        ], false);
        if (! is_object($configuredSession)
            || $configuredSession->lock_timeout !== $lockTimeout
            || $configuredSession->statement_timeout !== $statementTimeout
            || $configuredSession->application_name !== $applicationName
            || $this->positiveInteger($configuredSession->backend_pid ?? null) === null
            || $connection->getPdo() !== $lockedPdo) {
            $this->error('Control-plane migration session did not apply the exact identity and timeouts.');

            return null;
        }

        $backendPid = $this->positiveInteger($configuredSession->backend_pid);
        $this->line('control-plane-migration-application-name='.$applicationName);
        $this->line('control-plane-migration-backend-pid='.$backendPid);

        return $backendPid;
    }

    private function attestControlPlaneDatabase(Connection $connection): bool
    {
        $isLiveExpand = filter_var(env('CONTROL_PLANE_LIVE_EXPAND_MIGRATION', false), FILTER_VALIDATE_BOOL);
        $isRehearsal = filter_var(env('CONTROL_PLANE_MIGRATION_REHEARSAL', false), FILTER_VALIDATE_BOOL);

        $identity = $connection->selectOne(<<<'SQL'
            SELECT
                (SELECT system_identifier::text FROM pg_control_system()) AS system_identifier,
                current_database() AS database_name,
                host(inet_server_addr()) AS server_address,
                inet_server_port() AS server_port,
                (SELECT oid::text FROM pg_database WHERE datname = current_database()) AS database_oid
            SQL, [], false);
        if (! is_object($identity)
            || ! is_string($identity->system_identifier)
            || ! is_string($identity->database_name)
            || ! is_string($identity->server_address)
            || $this->positiveInteger($identity->server_port ?? null) === null
            || ! is_string($identity->database_oid)) {
            $this->error('Control-plane database identity query returned an incomplete result.');

            return false;
        }

        $serverPort = $this->positiveInteger($identity->server_port);
        $instanceMarker = hash('sha256', $identity->system_identifier.':'.$identity->database_oid);
        $identityPayload = implode(';', [
            'system_identifier='.$identity->system_identifier,
            'database='.$identity->database_name,
            'server_address='.$identity->server_address,
            'server_port='.$serverPort,
            'instance_marker='.$instanceMarker,
        ]);
        $identitySha256 = hash('sha256', $identityPayload);
        $expectedIdentitySha256 = env('CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256');
        $liveSystemIdentifier = env('CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER');

        if (! is_string($expectedIdentitySha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $expectedIdentitySha256) !== 1
            || ! hash_equals($expectedIdentitySha256, $identitySha256)
            || ! is_string($liveSystemIdentifier)
            || preg_match('/\A[0-9]+\z/', $liveSystemIdentifier) !== 1
            || ($isRehearsal && hash_equals($liveSystemIdentifier, $identity->system_identifier))
            || ($isLiveExpand && ! hash_equals($liveSystemIdentifier, $identity->system_identifier))) {
            $this->error('Control-plane database identity attestation failed.');

            return false;
        }

        $this->line('control-plane-database-identity='.$identityPayload);
        $this->line('control-plane-database-identity-sha256='.$identitySha256);

        return true;
    }

    private function withGlobalSchemaMigrationLock(
        Connection $connection,
        PDO $lockedPdo,
        Closure $migration,
    ): int {
        return $this->withSchemaMigrationAdvisoryLock(
            $connection,
            $lockedPdo,
            self::GLOBAL_SCHEMA_MIGRATION_LOCK,
            'global',
            'Another schema migration holds the PostgreSQL advisory lock.',
            $migration,
        );
    }

    private function withControlPlaneMigrationAttemptLock(
        Connection $connection,
        PDO $lockedPdo,
        string $advisoryLockIdentity,
        Closure $migration,
    ): int {
        return $this->withSchemaMigrationAdvisoryLock(
            $connection,
            $lockedPdo,
            $advisoryLockIdentity,
            'control-plane migration attempt',
            'Another control-plane migration attempt holds the PostgreSQL advisory lock.',
            $migration,
        );
    }

    private function withSchemaMigrationAdvisoryLock(
        Connection $connection,
        PDO $lockedPdo,
        string $advisoryLockIdentity,
        string $lockScope,
        string $alreadyHeldMessage,
        Closure $migration,
    ): int {
        if ($connection->getPdo() !== $lockedPdo) {
            throw new RuntimeException("Schema migration changed PostgreSQL sessions before the {$lockScope} advisory lock.");
        }

        $lockAcquired = $connection->scalar(
            'SELECT pg_try_advisory_lock(hashtextextended(?, 0))',
            [$advisoryLockIdentity],
            false,
        );
        if ($lockAcquired !== true) {
            $this->error($alreadyHeldMessage);

            return self::FAILURE;
        }

        $migrationFailure = null;
        try {
            return $migration();
        } catch (Throwable $throwable) {
            $migrationFailure = $throwable;

            throw $throwable;
        } finally {
            try {
                if ($connection->getPdo() !== $lockedPdo) {
                    throw new RuntimeException("Schema migration changed PostgreSQL sessions while the {$lockScope} advisory lock was held.");
                }

                $lockReleased = $connection->scalar(
                    'SELECT pg_advisory_unlock(hashtextextended(?, 0))',
                    [$advisoryLockIdentity],
                    false,
                );
                if ($lockReleased !== true) {
                    throw new RuntimeException("Schema migration {$lockScope} advisory lock release failed.");
                }
            } catch (Throwable $releaseFailure) {
                if ($migrationFailure === null) {
                    throw $releaseFailure;
                }

                $this->error("Schema migration {$lockScope} advisory lock cleanup also failed: ".$releaseFailure->getMessage());
            }
        }
    }

    /** @param list<string> $authorizedMigrationNames */
    private function runControlPlaneExpand(
        Connection $connection,
        PDO $lockedPdo,
        int $backendPid,
        string $applicationName,
        array $authorizedMigrationNames,
    ): int {
        $connectionName = $connection->getName();
        $authorizedMigrations = $this->loadAuthorizedControlPlaneMigrations(
            $connectionName,
            $authorizedMigrationNames,
        );

        if (! $this->attestControlPlaneDatabase($connection)) {
            return self::FAILURE;
        }
        $this->assertStableControlPlaneMigrationSession(
            $connection,
            $lockedPdo,
            $backendPid,
            $applicationName,
        );

        DB::usingConnection($connectionName, function () use (
            $authorizedMigrations,
            $authorizedMigrationNames,
            $backendPid,
            $connection,
            $connectionName,
            $lockedPdo,
            $applicationName,
        ): void {
            $connection->transaction(function () use (
                $authorizedMigrations,
                $authorizedMigrationNames,
                $backendPid,
                $connection,
                $connectionName,
                $lockedPdo,
                $applicationName,
            ): void {
                if ($connection->transactionLevel() !== 1) {
                    throw new RuntimeException('Control-plane expand did not start one outer transaction.');
                }
                $this->assertStableControlPlaneMigrationSession(
                    $connection,
                    $lockedPdo,
                    $backendPid,
                    $applicationName,
                );
                $this->lockControlPlaneMigrationTables($connection);

                $legacyRowCountBefore = $this->controlPlaneLegacyRowCounts($connection);
                $this->assertNewControlPlaneTablesAreEmpty($connection, false);
                $ledgerAttestation = $this->attestControlPlaneMigrationLedger(
                    $connection,
                    $authorizedMigrationNames,
                );

                $exitCode = $this->call('migrate', [
                    '--force' => true,
                    '--isolated' => 1,
                    '--database' => $connectionName,
                    '--path' => $this->authorizedControlPlaneMigrationPaths($authorizedMigrationNames),
                ]);
                if ($exitCode !== self::SUCCESS) {
                    throw new RuntimeException("Authorized control-plane migration command failed with exit code {$exitCode}.");
                }
                if ($connection->transactionLevel() !== 1) {
                    throw new RuntimeException('Authorized control-plane migrations escaped the outer transaction.');
                }

                $this->assertStableControlPlaneMigrationSession(
                    $connection,
                    $lockedPdo,
                    $backendPid,
                    $applicationName,
                );
                $this->assertExactControlPlaneMigrationLedgerAppend(
                    $connection,
                    $ledgerAttestation['rows'],
                    $ledgerAttestation['expectedBatch'],
                    $authorizedMigrationNames,
                );
                if ($this->controlPlaneLegacyRowCounts($connection) !== $legacyRowCountBefore) {
                    throw new RuntimeException('Control-plane expand changed a legacy table row count.');
                }
                $this->assertNewControlPlaneTablesAreEmpty($connection, true);
                $this->attestAuthorizedControlPlaneSchema($authorizedMigrations);
                $this->assertStableControlPlaneMigrationSession(
                    $connection,
                    $lockedPdo,
                    $backendPid,
                    $applicationName,
                );
                $this->line('control-plane-schema-attestation=passed');
            }, 1);
        });

        return self::SUCCESS;
    }

    private function assertStableControlPlaneMigrationSession(
        Connection $connection,
        PDO $lockedPdo,
        int $backendPid,
        string $applicationName,
    ): void {
        if ($connection->getPdo() !== $lockedPdo) {
            throw new RuntimeException('Control-plane expand changed PostgreSQL PDO sessions.');
        }

        $session = $connection->selectOne(<<<'SQL'
            SELECT
                pg_backend_pid() AS backend_pid,
                current_setting('application_name') AS application_name
            SQL, [], false);
        if (! is_object($session)
            || $this->positiveInteger($session->backend_pid ?? null) !== $backendPid
            || $session->application_name !== $applicationName
            || $connection->getPdo() !== $lockedPdo) {
            throw new RuntimeException('Control-plane expand changed PostgreSQL backend identity.');
        }
    }

    private function lockControlPlaneMigrationTables(Connection $connection): void
    {
        $connection->statement(<<<'SQL'
            LOCK TABLE
                migrations,
                application_settings,
                application_deployment_queues
            IN ACCESS EXCLUSIVE MODE
            SQL);

        foreach ($this->newControlPlaneTableNames() as $tableName) {
            $tableExists = $connection->scalar(
                'SELECT to_regclass(current_schema() || \'.\' || ?) IS NOT NULL',
                [$tableName],
                false,
            );
            if ($tableExists === true) {
                $connection->statement("LOCK TABLE {$tableName} IN ACCESS EXCLUSIVE MODE");
            }
        }
    }

    /** @return array{applicationSettings: int, applicationDeploymentQueues: int} */
    private function controlPlaneLegacyRowCounts(Connection $connection): array
    {
        $rowCounts = $connection->selectOne(<<<'SQL'
            SELECT
                (SELECT count(*) FROM application_settings) AS application_settings,
                (SELECT count(*) FROM application_deployment_queues) AS application_deployment_queues
            SQL, [], false);
        $applicationSettings = is_object($rowCounts)
            ? $this->nonNegativeInteger($rowCounts->application_settings ?? null)
            : null;
        $applicationDeploymentQueues = is_object($rowCounts)
            ? $this->nonNegativeInteger($rowCounts->application_deployment_queues ?? null)
            : null;
        if ($applicationSettings === null || $applicationDeploymentQueues === null) {
            throw new RuntimeException('Control-plane legacy row-count query returned a malformed result.');
        }

        return [
            'applicationSettings' => $applicationSettings,
            'applicationDeploymentQueues' => $applicationDeploymentQueues,
        ];
    }

    private function assertNewControlPlaneTablesAreEmpty(Connection $connection, bool $mustExist): void
    {
        foreach ($this->newControlPlaneTableNames() as $tableName) {
            $tableExists = $connection->scalar(
                'SELECT to_regclass(current_schema() || \'.\' || ?) IS NOT NULL',
                [$tableName],
                false,
            );
            if ($tableExists !== true) {
                if ($mustExist) {
                    throw new RuntimeException("Authorized control-plane table is missing: {$tableName}.");
                }

                continue;
            }

            $rowCount = $this->nonNegativeInteger(
                $connection->scalar("SELECT count(*) FROM {$tableName}", [], false),
            );
            if ($rowCount !== 0) {
                throw new RuntimeException("Authorized control-plane table is not empty: {$tableName}.");
            }
        }
    }

    /**
     * @param  list<string>  $authorizedMigrationNames
     * @return array{
     *     rows: list<array{migration: string, batch: int}>,
     *     expectedBatch: int
     * }
     */
    private function attestControlPlaneMigrationLedger(Connection $connection, array $authorizedMigrationNames): array
    {
        $expectedLedgerSha256 = env('CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256');
        $expectedPendingSha256 = env('CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256');
        $expectedBatchInput = env('CONTROL_PLANE_EXPECTED_MIGRATION_BATCH');
        if (! is_string($expectedLedgerSha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $expectedLedgerSha256) !== 1
            || ! is_string($expectedPendingSha256)
            || preg_match('/\A[0-9a-f]{64}\z/', $expectedPendingSha256) !== 1
            || ! is_string($expectedBatchInput)
            || preg_match('/\A[1-9][0-9]*\z/', $expectedBatchInput) !== 1) {
            throw new RuntimeException('Control-plane migration ledger attestation inputs are missing or malformed.');
        }
        $expectedBatch = $this->positiveInteger($expectedBatchInput);

        $ledger = '';
        $recordedMigration = [];
        $maximumBatch = 0;
        foreach ($connection->select('SELECT migration, batch FROM migrations ORDER BY migration', [], false) as $row) {
            if (! is_object($row)
                || ! is_string($row->migration)
                || preg_match('/\A[0-9A-Za-z_]+\z/', $row->migration) !== 1
                || ($batch = $this->positiveInteger($row->batch ?? null)) === null
                || isset($recordedMigration[$row->migration])) {
                throw new RuntimeException('Control-plane migration ledger query returned malformed or duplicate rows.');
            }
            $ledger .= "{$row->migration},{$batch}\n";
            $recordedMigration[$row->migration] = true;
            $maximumBatch = max($maximumBatch, $batch);
        }

        $this->assertControlPlaneMigrationInventory($recordedMigration, $authorizedMigrationNames);
        $pendingMigrations = array_values(array_filter(
            $authorizedMigrationNames,
            fn (string $migration): bool => ! isset($recordedMigration[$migration]),
        ));
        if ($pendingMigrations !== $authorizedMigrationNames) {
            throw new RuntimeException('Control-plane migration ledger does not have the exact authorized migration gap.');
        }
        $pending = implode("\n", $pendingMigrations)."\n";

        if (! hash_equals($expectedLedgerSha256, hash('sha256', $ledger))
            || ! hash_equals($expectedPendingSha256, hash('sha256', $pending))
            || $expectedBatch !== $maximumBatch + 1) {
            throw new RuntimeException('Control-plane migration ledger changed before DDL.');
        }

        $this->line('control-plane-migration-ledger-sha256='.$expectedLedgerSha256);
        $this->line('control-plane-migration-pending-sha256='.$expectedPendingSha256);
        $this->line('control-plane-migration-batch='.$expectedBatch);

        return [
            'rows' => $this->controlPlaneMigrationLedgerRows($connection),
            'expectedBatch' => $expectedBatch,
        ];
    }

    /**
     * @param  array<string, true>  $recordedMigration
     * @param  list<string>  $authorizedMigrationNames
     */
    private function assertControlPlaneMigrationInventory(
        array $recordedMigration,
        array $authorizedMigrationNames,
    ): void {
        $surplusPath = base_path('database/migrations/control-plane-immutable-baseline-surplus.list');
        $surplusContents = is_file($surplusPath) && ! is_link($surplusPath)
            ? file_get_contents($surplusPath)
            : false;
        if (! is_string($surplusContents)
            || preg_match('/\A(?:[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+\n){3}\z/', $surplusContents) !== 1) {
            throw new RuntimeException('Immutable baseline migration surplus is missing or malformed.');
        }
        $surplus = explode("\n", rtrim($surplusContents, "\n"));
        $orderedSurplus = $surplus;
        sort($orderedSurplus, SORT_STRING);
        if ($surplus !== $orderedSurplus || count(array_unique($surplus)) !== 3) {
            throw new RuntimeException('Immutable baseline migration surplus is not ordered and unique.');
        }

        $candidateMigration = [];
        $migrationPaths = glob(base_path('database/migrations/*.php'), GLOB_NOSORT);
        if (! is_array($migrationPaths) || $migrationPaths === []) {
            throw new RuntimeException('Candidate migration inventory is unavailable.');
        }
        sort($migrationPaths, SORT_STRING);
        foreach ($migrationPaths as $migrationPath) {
            $migration = pathinfo($migrationPath, PATHINFO_FILENAME);
            if (! is_file($migrationPath)
                || is_link($migrationPath)
                || ! is_readable($migrationPath)
                || preg_match('/\A[0-9A-Za-z_]+\z/', $migration) !== 1
                || isset($candidateMigration[$migration])) {
                throw new RuntimeException('Candidate migration inventory is malformed or duplicated.');
            }
            $candidateMigration[$migration] = true;
        }

        $observedSurplus = array_keys(array_diff_key($recordedMigration, $candidateMigration));
        sort($observedSurplus, SORT_STRING);
        if ($observedSurplus !== $surplus
            || array_intersect_key(array_fill_keys($surplus, true), $candidateMigration) !== []) {
            throw new RuntimeException('Migration ledger surplus differs from the exact immutable baseline inventory.');
        }

        $missingCandidateMigration = array_keys(array_diff_key($candidateMigration, $recordedMigration));
        sort($missingCandidateMigration, SORT_STRING);
        if ($missingCandidateMigration !== $authorizedMigrationNames) {
            throw new RuntimeException('Candidate migration inventory does not have the exact authorized migration gap.');
        }
    }

    /**
     * @param  list<array{migration: string, batch: int}>  $ledgerBefore
     * @param  list<string>  $authorizedMigrationNames
     */
    private function assertExactControlPlaneMigrationLedgerAppend(
        Connection $connection,
        array $ledgerBefore,
        int $expectedBatch,
        array $authorizedMigrationNames,
    ): void {
        $expectedLedger = $ledgerBefore;
        foreach ($authorizedMigrationNames as $migration) {
            $expectedLedger[] = ['migration' => $migration, 'batch' => $expectedBatch];
        }

        if ($this->controlPlaneMigrationLedgerRows($connection) !== $expectedLedger) {
            throw new RuntimeException('Control-plane migrations did not append the exact ordered authorized migration ledger batch.');
        }
    }

    /** @return list<array{migration: string, batch: int}> */
    private function controlPlaneMigrationLedgerRows(Connection $connection): array
    {
        $ledgerRows = [];
        $recordedMigration = [];
        $previousId = 0;
        foreach ($connection->select('SELECT id, migration, batch FROM migrations ORDER BY id', [], false) as $row) {
            $id = is_object($row) ? $this->positiveInteger($row->id ?? null) : null;
            $batch = is_object($row) ? $this->positiveInteger($row->batch ?? null) : null;
            if (! is_object($row)
                || $id === null
                || $id <= $previousId
                || ! is_string($row->migration)
                || preg_match('/\A[0-9A-Za-z_]+\z/', $row->migration) !== 1
                || isset($recordedMigration[$row->migration])
                || $batch === null) {
                throw new RuntimeException('Control-plane migration ledger append order is malformed or duplicated.');
            }

            $ledgerRows[] = ['migration' => $row->migration, 'batch' => $batch];
            $recordedMigration[$row->migration] = true;
            $previousId = $id;
        }

        return $ledgerRows;
    }

    /**
     * @param  list<string>  $authorizedMigrationNames
     * @return array<string, DatabaseMigration>
     */
    private function loadAuthorizedControlPlaneMigrations(string $connectionName, array $authorizedMigrationNames): array
    {
        $authorizedMigrations = [];
        foreach ($authorizedMigrationNames as $migrationName) {
            $path = "database/migrations/{$migrationName}.php";
            $absolutePath = base_path($path);
            if (! is_file($absolutePath) || is_link($absolutePath) || ! is_readable($absolutePath)) {
                throw new RuntimeException("Authorized control-plane migration is missing, linked, or unreadable: {$path}.");
            }

            $migration = require $absolutePath;
            if (! $migration instanceof DatabaseMigration) {
                throw new RuntimeException("Authorized control-plane migration did not return a migration: {$path}.");
            }
            if ($migration->withinTransaction !== true) {
                throw new RuntimeException("Authorized control-plane migration is nontransactional: {$path}.");
            }
            if ($migration->getConnection() !== null && $migration->getConnection() !== $connectionName) {
                throw new RuntimeException("Authorized control-plane migration selects another database connection: {$path}.");
            }
            if (! method_exists($migration, 'assertExactSchema')) {
                throw new RuntimeException("Authorized control-plane migration has no read-only schema attestation: {$path}.");
            }

            $authorizedMigrations[$migrationName] = $migration;
        }

        return $authorizedMigrations;
    }

    /** @param array<string, DatabaseMigration> $authorizedMigrations */
    private function attestAuthorizedControlPlaneSchema(array $authorizedMigrations): void
    {
        foreach ($authorizedMigrations as $migration) {
            $migration->assertExactSchema();
        }
    }

    /** @return list<string> */
    public static function controlPlaneMigrationNames(
        ?string $migrationDirectory = null,
        ?string $fingerprintPath = null,
        bool $requireExclusiveMigrationDirectory = false,
    ): array {
        return ControlPlaneMigrationInventory::names(
            $migrationDirectory,
            $fingerprintPath,
            $requireExclusiveMigrationDirectory,
        );
    }

    /**
     * @param  list<string>  $authorizedMigrationNames
     * @return list<string>
     */
    private function authorizedControlPlaneMigrationPaths(array $authorizedMigrationNames): array
    {
        return array_map(
            fn (string $migration): string => "database/migrations/{$migration}.php",
            $authorizedMigrationNames,
        );
    }

    /** @return list<string> */
    private function newControlPlaneTableNames(): array
    {
        return [
            'application_blue_green_deployments',
            'application_blue_green_deactivations',
        ];
    }

    private function positiveInteger(mixed $candidate): ?int
    {
        $integer = $this->nonNegativeInteger($candidate);

        return $integer !== null && $integer > 0 ? $integer : null;
    }

    private function nonNegativeInteger(mixed $candidate): ?int
    {
        if (is_int($candidate)) {
            return $candidate >= 0 ? $candidate : null;
        }
        if (! is_string($candidate) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $candidate) !== 1) {
            return null;
        }

        $integer = (int) $candidate;

        return (string) $integer === $candidate ? $integer : null;
    }
}
