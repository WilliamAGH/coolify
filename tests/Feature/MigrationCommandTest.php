<?php

use App\Console\Commands\Migration;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration as DatabaseMigration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** @return list<string> */
function controlPlaneMigrationNames(): array
{
    return Migration::controlPlaneMigrationNames();
}

/** @param Closure(string, list<string>): void $assertions */
function withControlPlaneMigrationFixture(Closure $assertions): void
{
    $originalDatabasePath = database_path();
    $databasePath = sys_get_temp_dir().'/coolify-control-plane-migration-'.bin2hex(random_bytes(8));
    $migrationPath = $databasePath.'/migrations';
    $migrationNames = controlPlaneMigrationNames();
    if (! mkdir($migrationPath, 0700, true) && ! is_dir($migrationPath)) {
        throw new RuntimeException('Could not create the temporary control-plane migration directory.');
    }
    foreach ($migrationNames as $migrationName) {
        if (! touch("{$migrationPath}/{$migrationName}.php")) {
            throw new RuntimeException("Could not create the temporary migration: {$migrationName}.");
        }
    }

    app()->useDatabasePath($databasePath);
    try {
        $assertions($migrationPath, $migrationNames);
    } finally {
        app()->useDatabasePath($originalDatabasePath);
        (new Filesystem)->deleteDirectory($databasePath);
    }
}

/**
 * @param  list<string>  $migrationNames
 * @return list<string>
 */
function controlPlaneMigrationPaths(array $migrationNames): array
{
    return array_map(
        fn (string $migration): string => "database/migrations/{$migration}.php",
        $migrationNames,
    );
}

/** @return list<string> */
function controlPlaneBaselineSurplusNames(): array
{
    $surplus = file(
        base_path('database/migrations/control-plane-immutable-baseline-surplus.list'),
        FILE_IGNORE_NEW_LINES,
    );

    return is_array($surplus) ? $surplus : [];
}

/**
 * @param  list<string>  $authorizedMigrationNames
 * @return list<array{migration: string, batch: int}>
 */
function controlPlaneBaselineLedgerRows(array $authorizedMigrationNames): array
{
    $authorizedMigration = array_fill_keys($authorizedMigrationNames, true);
    $candidatePaths = glob(base_path('database/migrations/*.php')) ?: [];
    $ledgerMigration = array_map(
        fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
        $candidatePaths,
    );
    $baselineMigration = [
        ...array_values(array_filter(
            $ledgerMigration,
            fn (string $migration): bool => ! isset($authorizedMigration[$migration]),
        )),
        ...controlPlaneBaselineSurplusNames(),
    ];
    sort($baselineMigration, SORT_STRING);

    return array_map(
        fn (string $migration): array => ['migration' => $migration, 'batch' => 1],
        $baselineMigration,
    );
}

/** @param list<array{migration: string, batch: int}> $ledgerRows */
function controlPlaneMigrationLedger(array $ledgerRows): string
{
    return implode("\n", array_map(
        fn (array $row): string => "{$row['migration']},{$row['batch']}",
        $ledgerRows,
    ))."\n";
}

/** @param list<string> $migrationNames */
function configureControlPlaneMigrationEnvironment(array $migrationNames, bool $rehearsal = false): object
{
    $systemIdentifier = '7612345678901234567';
    $liveSystemIdentifier = $rehearsal ? '7699999999999999999' : $systemIdentifier;
    $databaseOid = '16384';
    $instanceMarker = hash('sha256', "{$systemIdentifier}:{$databaseOid}");
    $identityPayload = "system_identifier={$systemIdentifier};database=coolify;server_address=10.0.1.4;server_port=5432;instance_marker={$instanceMarker}";
    $pending = implode("\n", $migrationNames)."\n";
    $operationId = 'control-plane-migration-test-operation';
    $attempt = '1';
    $attemptIdentityDigest = substr(hash('sha256', "{$operationId}:{$attempt}\n"), 0, 32);
    $attemptApplicationName = "coolify-control-plane-expand-{$attemptIdentityDigest}";
    $attemptAdvisoryLockIdentity = "coolify-control-plane-expand-attempt:{$attemptIdentityDigest}";

    putenv('CONTROL_PLANE_LIVE_EXPAND_MIGRATION='.($rehearsal ? 'false' : 'true'));
    putenv('CONTROL_PLANE_MIGRATION_REHEARSAL='.($rehearsal ? 'true' : 'false'));
    putenv('CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256='.hash('sha256', $identityPayload));
    putenv("CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER={$liveSystemIdentifier}");
    putenv('CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256='.hash(
        'sha256',
        controlPlaneMigrationLedger(controlPlaneBaselineLedgerRows($migrationNames)),
    ));
    putenv('CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256='.hash('sha256', $pending));
    putenv('CONTROL_PLANE_EXPECTED_MIGRATION_BATCH=2');
    putenv('CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT=750ms');
    putenv('CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT=5s');
    putenv("CONTROL_PLANE_MIGRATION_OPERATION_ID={$operationId}");
    putenv("CONTROL_PLANE_MIGRATION_ATTEMPT={$attempt}");
    putenv("CONTROL_PLANE_MIGRATION_APPLICATION_NAME={$attemptApplicationName}");
    putenv("CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY={$attemptAdvisoryLockIdentity}");

    return (object) [
        'system_identifier' => $systemIdentifier,
        'database_name' => 'coolify',
        'server_address' => '10.0.1.4',
        'server_port' => 5432,
        'database_oid' => $databaseOid,
        'migration_application_name' => $attemptApplicationName,
        'migration_attempt_lock_identity' => $attemptAdvisoryLockIdentity,
    ];
}

/** @param list<string> $migrationNames */
function prepareControlPlaneMigrationSchema(array $migrationNames): void
{
    Schema::dropIfExists('application_blue_green_deactivations');
    Schema::dropIfExists('application_blue_green_deployments');
    Schema::dropIfExists('application_deployment_queues');
    Schema::dropIfExists('application_settings');
    Schema::dropIfExists('standalone_dockers');
    Schema::dropIfExists('applications');

    Schema::create('applications', fn (Blueprint $table) => $table->id());
    Schema::create('standalone_dockers', fn (Blueprint $table) => $table->id());
    Schema::create('application_settings', fn (Blueprint $table) => $table->id());
    Schema::create('application_deployment_queues', function (Blueprint $table) {
        $table->id();
        $table->string('status');
    });

    foreach (controlPlaneMigrationPaths($migrationNames) as $path) {
        $migration = require base_path($path);
        if (! $migration instanceof DatabaseMigration) {
            throw new RuntimeException("The controlled migration path did not return a migration: {$path}.");
        }

        $migration->up();
    }
}

/** @param array<string, bool|int|string|list<string>> $arguments */
function migrateControlPlanePaths(object $state, array $arguments): void
{
    $paths = $arguments['--path'] ?? null;
    if (! is_array($paths)) {
        throw new RuntimeException('The controlled migration command did not receive authorized paths.');
    }

    $state->nestedMigrationPaths = $paths;
    foreach ($paths as $path) {
        if (! is_string($path)) {
            throw new RuntimeException('The controlled migration command received an invalid path.');
        }

        $state->ledgerRows[] = [
            'migration' => pathinfo($path, PATHINFO_FILENAME),
            'batch' => 2,
        ];
    }

    $state->newControlPlaneTablesExist = true;
}

function controlPlaneMigrationCommand(
    object $state,
    int $nestedExitCode = Command::SUCCESS,
    ?Closure $afterNestedMigration = null,
): Migration {
    return new class($state, $nestedExitCode, $afterNestedMigration) extends Migration
    {
        /** @var array{command: string, arguments: array<string, bool|int|string|list<string>>}|null */
        public ?array $nestedCommand = null;

        /** @var list<string> */
        public array $errors = [];

        /** @var list<string> */
        public array $lines = [];

        public function __construct(
            private readonly object $state,
            private readonly int $nestedExitCode,
            private readonly ?Closure $afterNestedMigration,
        ) {
            parent::__construct();
        }

        public function call($command, array $arguments = []): int
        {
            $this->nestedCommand = ['command' => $command, 'arguments' => $arguments];
            $this->state->nestedMigrationTransactionLevel = $this->state->transactionLevel;

            if ($command === 'migrate' && $this->nestedExitCode === Command::SUCCESS) {
                migrateControlPlanePaths($this->state, $arguments);
                if ($this->afterNestedMigration !== null) {
                    ($this->afterNestedMigration)($this->state);
                }
            }

            return $this->nestedExitCode;
        }

        public function info($string, $verbosity = null) {}

        public function line($string, $style = null, $verbosity = null)
        {
            $this->lines[] = $string;
        }

        public function error($string, $verbosity = null)
        {
            $this->errors[] = $string;
        }

        public function option($key = null): mixed
        {
            return $key === 'control-plane-expand';
        }
    };
}

/** @param list<string> $migrationNames */
function controlPlaneMigrationHarness(object $identity, array $migrationNames): object
{
    $schemaBuilder = Schema::getFacadeRoot();
    $pdo = new PDO('sqlite::memory:');
    $state = (object) [
        'identity' => $identity,
        'pdo' => $pdo,
        'alternatePdo' => null,
        'switchPdoOnCall' => null,
        'pdoCallCount' => 0,
        'pdoSessionIdentifiers' => [],
        'sessionApplicationName' => filter_var(
            getenv('CONTROL_PLANE_LIVE_EXPAND_MIGRATION'),
            FILTER_VALIDATE_BOOL,
        ) ? $identity->migration_application_name : 'coolify-control-plane-expand',
        'backendPid' => 8123,
        'sessionConfigurationBindings' => null,
        'sessionIdentityChecks' => 0,
        'advisoryLockAvailable' => true,
        'advisoryLockAvailability' => [],
        'advisoryLockAcquisitions' => [],
        'advisoryLockReleases' => [],
        'newControlPlaneTablesExist' => false,
        'newControlPlaneTableChecks' => [],
        'newControlPlaneTableRowCounts' => [],
        'ledgerRows' => controlPlaneBaselineLedgerRows($migrationNames),
        'legacyRowCounts' => [
            ['application_settings' => 7, 'application_deployment_queues' => 11],
            ['application_settings' => 7, 'application_deployment_queues' => 11],
        ],
        'legacyRowCountReads' => [],
        'transactionLevel' => 0,
        'outerTransactionCalls' => 0,
        'transactionAttempts' => [],
        'usingConnections' => [],
        'lockedTables' => [],
        'nestedMigrationTransactionLevel' => null,
        'nestedMigrationPaths' => [],
    ];
    $connection = Mockery::mock(Connection::class);
    $state->connection = $connection;

    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schemaBuilder);
    $connection->shouldReceive('useWriteConnectionWhenReading')->andReturn($connection);
    $connection->shouldReceive('getPdo')->andReturnUsing(function () use ($state): PDO {
        $state->pdoCallCount++;
        $pdo = $state->alternatePdo instanceof PDO
            && $state->switchPdoOnCall !== null
            && $state->pdoCallCount >= $state->switchPdoOnCall
            ? $state->alternatePdo
            : $state->pdo;
        $state->pdoSessionIdentifiers[] = spl_object_id($pdo);

        return $pdo;
    });
    $connection->shouldReceive('getName')->andReturn('control-plane');
    $connection->shouldReceive('selectOne')->andReturnUsing(
        function (string $sql, array $bindings = [], bool $useReadPdo = true) use ($state): object {
            if (str_contains($sql, "set_config('lock_timeout'")) {
                $state->sessionConfigurationBindings = $bindings;

                return (object) [
                    'lock_timeout' => $bindings[0] ?? null,
                    'statement_timeout' => $bindings[1] ?? null,
                    'application_name' => $state->sessionApplicationName,
                    'backend_pid' => $state->backendPid,
                ];
            }

            if (str_contains($sql, 'pg_control_system')) {
                return $state->identity;
            }

            if (str_contains($sql, "current_setting('application_name')")) {
                $state->sessionIdentityChecks++;

                return (object) [
                    'backend_pid' => $state->backendPid,
                    'application_name' => $state->sessionApplicationName,
                ];
            }

            if (str_contains($sql, 'count(*) FROM application_settings')) {
                $index = min(
                    count($state->legacyRowCountReads),
                    count($state->legacyRowCounts) - 1,
                );
                $rowCounts = $state->legacyRowCounts[$index];
                $state->legacyRowCountReads[] = $rowCounts;

                return (object) $rowCounts;
            }

            throw new RuntimeException("Unexpected PostgreSQL select-one query: {$sql}");
        },
    );
    $connection->shouldReceive('scalar')->andReturnUsing(
        function (string $sql, array $bindings = [], bool $useReadPdo = true) use ($state): bool|int {
            if (str_contains($sql, 'pg_try_advisory_lock')) {
                $state->advisoryLockAcquisitions[] = $bindings;

                return $state->advisoryLockAvailability === []
                    ? $state->advisoryLockAvailable
                    : array_shift($state->advisoryLockAvailability);
            }

            if (str_contains($sql, 'pg_advisory_unlock')) {
                $state->advisoryLockReleases[] = $bindings;

                return true;
            }

            if (str_contains($sql, 'to_regclass')) {
                $state->newControlPlaneTableChecks[] = $bindings[0] ?? null;

                return $state->newControlPlaneTablesExist;
            }

            if (str_contains($sql, 'SELECT count(*) FROM application_blue_green_')) {
                $state->newControlPlaneTableRowCounts[] = $sql;

                return 0;
            }

            throw new RuntimeException("Unexpected PostgreSQL scalar query: {$sql}");
        },
    );
    $connection->shouldReceive('statement')->andReturnUsing(function (string $sql) use ($state): bool {
        $state->lockedTables[] = $sql;

        return true;
    });
    $connection->shouldReceive('select')->andReturnUsing(
        function (string $sql, array $bindings = [], bool $useReadPdo = true) use ($state): array {
            if ($sql === 'SELECT migration, batch FROM migrations ORDER BY migration') {
                $ledgerRows = $state->ledgerRows;
                usort($ledgerRows, fn (array $left, array $right): int => $left['migration'] <=> $right['migration']);

                return array_map(fn (array $row): object => (object) $row, $ledgerRows);
            }

            if ($sql === 'SELECT id, migration, batch FROM migrations ORDER BY id') {
                return array_map(
                    fn (array $row, int $index): object => (object) ['id' => $index + 1, ...$row],
                    $state->ledgerRows,
                    array_keys($state->ledgerRows),
                );
            }

            throw new RuntimeException("Unexpected PostgreSQL select query: {$sql}");
        },
    );
    $connection->shouldReceive('transaction')->andReturnUsing(
        function (Closure $callback, int $attempts = 1) use ($state): mixed {
            $state->outerTransactionCalls++;
            $state->transactionAttempts[] = $attempts;
            $state->transactionLevel = 1;

            try {
                return $callback();
            } finally {
                $state->transactionLevel = 0;
            }
        },
    );
    $connection->shouldReceive('transactionLevel')->andReturnUsing(fn (): int => $state->transactionLevel);

    DB::shouldReceive('connection')->withNoArgs()->andReturn($connection);
    DB::shouldReceive('usingConnection')->andReturnUsing(
        function (string $connectionName, Closure $callback) use ($state): mixed {
            $state->usingConnections[] = $connectionName;

            return $callback();
        },
    );

    return $state;
}

$originalMigrationEnvironment = collect([
    'CONTROL_PLANE_LIVE_EXPAND_MIGRATION',
    'CONTROL_PLANE_MIGRATION_REHEARSAL',
    'CONTROL_PLANE_EXPECTED_DATABASE_IDENTITY_SHA256',
    'CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER',
    'CONTROL_PLANE_EXPECTED_MIGRATION_LEDGER_SHA256',
    'CONTROL_PLANE_EXPECTED_MIGRATION_PENDING_SHA256',
    'CONTROL_PLANE_EXPECTED_MIGRATION_BATCH',
    'CONTROL_PLANE_MIGRATION_LOCK_TIMEOUT',
    'CONTROL_PLANE_MIGRATION_STATEMENT_TIMEOUT',
    'CONTROL_PLANE_MIGRATION_OPERATION_ID',
    'CONTROL_PLANE_MIGRATION_ATTEMPT',
    'CONTROL_PLANE_MIGRATION_APPLICATION_NAME',
    'CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY',
])->mapWithKeys(fn (string $name) => [$name => getenv($name)])->all();

beforeEach(function () {
    config()->set([
        'control-plane.mode' => 'active',
        'control-plane.startup_mode' => 'full',
        'constants.migration.is_migration_enabled' => true,
    ]);
});

afterEach(function () use ($originalMigrationEnvironment) {
    foreach ($originalMigrationEnvironment as $name => $environmentValue) {
        putenv($environmentValue === false ? $name : "{$name}={$environmentValue}");
    }
});

it('propagates the general nested migrate command exit code', function () {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');
    DB::shouldReceive('connection')->once()->withNoArgs()->andReturn($connection);
    $command = new class extends Migration
    {
        public array $nestedCommand = [];

        public function call($command, array $arguments = []): int
        {
            $this->nestedCommand = ['command' => $command, 'arguments' => $arguments];

            return Command::FAILURE;
        }

        public function info($string, $verbosity = null) {}

        public function option($key = null): mixed
        {
            return false;
        }
    };

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($command->nestedCommand)->toBe([
            'command' => 'migrate',
            'arguments' => ['--force' => true, '--isolated' => 1],
        ]);
});

it('loads an ordered unique reviewed control-plane migration inventory', function () {
    $migrationNames = Migration::controlPlaneMigrationNames();
    $orderedMigrationNames = $migrationNames;
    sort($orderedMigrationNames, SORT_STRING);

    expect($migrationNames)
        ->not->toBeEmpty()
        ->toBe($orderedMigrationNames)
        ->toHaveCount(count(array_unique($migrationNames)));
});

it('prints the canonical control-plane migration inventory without startup work', function () {
    config()->set([
        'control-plane.mode' => 'passive',
        'control-plane.startup_mode' => 'fenced',
        'constants.migration.is_migration_enabled' => false,
    ]);

    $command = $this->artisan('start:migration', [
        '--print-control-plane-migration-inventory' => true,
    ]);
    foreach (controlPlaneMigrationNames() as $migrationName) {
        $command->expectsOutput($migrationName);
    }

    $command->assertExitCode(Command::SUCCESS);
});

it('rejects a well-formed migration added outside the reviewed inventory fingerprint', function () {
    withControlPlaneMigrationFixture(function (string $migrationPath): void {
        touch("{$migrationPath}/2026_07_12_999999_unreviewed_migration.php");

        expect(fn () => Migration::controlPlaneMigrationNames())
            ->toThrow(
                RuntimeException::class,
                'Control-plane migration inventory does not match the reviewed fingerprint.',
            );
    });
});

it('rejects a well-formed migration removed from the reviewed inventory fingerprint', function () {
    withControlPlaneMigrationFixture(function (string $migrationPath, array $migrationNames): void {
        unlink("{$migrationPath}/{$migrationNames[array_key_last($migrationNames)]}.php");

        expect(fn () => Migration::controlPlaneMigrationNames())
            ->toThrow(
                RuntimeException::class,
                'Control-plane migration inventory does not match the reviewed fingerprint.',
            );
    });
});

it('rejects a matching regular migration symlink', function () {
    withControlPlaneMigrationFixture(function (string $migrationPath, array $migrationNames): void {
        symlink(
            "{$migrationNames[0]}.php",
            "{$migrationPath}/2026_07_12_999998_regular_symlink.php",
        );

        expect(fn () => Migration::controlPlaneMigrationNames())
            ->toThrow(
                RuntimeException::class,
                'Control-plane migration inventory contains an unsafe or unexpected file.',
            );
    });
});

it('rejects a matching dangling migration symlink', function () {
    withControlPlaneMigrationFixture(function (string $migrationPath): void {
        symlink(
            'missing-control-plane-migration.php',
            "{$migrationPath}/2026_07_12_999999_dangling_symlink.php",
        );

        expect(fn () => Migration::controlPlaneMigrationNames())
            ->toThrow(
                RuntimeException::class,
                'Control-plane migration inventory contains an unsafe or unexpected file.',
            );
    });
});

it('rejects foreign migrations when an isolated control-plane image requires an exact directory', function () {
    withControlPlaneMigrationFixture(function (string $migrationPath): void {
        touch("{$migrationPath}/2026_07_11_000000_foreign.php");

        expect(fn () => Migration::controlPlaneMigrationNames(requireExclusiveMigrationDirectory: true))
            ->toThrow(
                RuntimeException::class,
                'Control-plane migration directory contains unexpected migration files.',
            );
    });
});

it('runs the exact authorized migration expansion on one PostgreSQL session and outer transaction', function () {
    $migrationNames = controlPlaneMigrationNames();
    $identity = configureControlPlaneMigrationEnvironment($migrationNames);
    prepareControlPlaneMigrationSchema($migrationNames);
    $state = controlPlaneMigrationHarness($identity, $migrationNames);
    $command = controlPlaneMigrationCommand($state);
    $expectedPaths = controlPlaneMigrationPaths($migrationNames);
    $expectedLedgerAppend = array_map(
        fn (string $migration): array => ['migration' => $migration, 'batch' => 2],
        $migrationNames,
    );

    expect($command->handle())->toBe(Command::SUCCESS)
        ->and($migrationNames)->not->toBeEmpty()
        ->and($command->nestedCommand)->toBe([
            'command' => 'migrate',
            'arguments' => [
                '--force' => true,
                '--isolated' => 1,
                '--database' => 'control-plane',
                '--path' => $expectedPaths,
            ],
        ])
        ->and($state->nestedMigrationPaths)->toBe($expectedPaths)
        ->and($state->sessionConfigurationBindings)->toBe([
            '750ms',
            '5s',
            $identity->migration_application_name,
        ])
        ->and($state->sessionIdentityChecks)->toBeGreaterThan(0)
        ->and(array_values(array_unique($state->pdoSessionIdentifiers)))->toBe([
            spl_object_id($state->pdo),
        ])
        ->and($state->advisoryLockAcquisitions)->toBe([
            ['coolify-control-plane-expand'],
            [$identity->migration_attempt_lock_identity],
        ])
        ->and($state->advisoryLockReleases)->toBe([
            [$identity->migration_attempt_lock_identity],
            ['coolify-control-plane-expand'],
        ])
        ->and($state->outerTransactionCalls)->toBe(1)
        ->and($state->transactionAttempts)->toBe([1])
        ->and($state->nestedMigrationTransactionLevel)->toBe(1)
        ->and($state->legacyRowCountReads)->toBe([
            ['application_settings' => 7, 'application_deployment_queues' => 11],
            ['application_settings' => 7, 'application_deployment_queues' => 11],
        ])
        ->and(array_slice($state->ledgerRows, count(controlPlaneBaselineLedgerRows($migrationNames))))->toBe($expectedLedgerAppend)
        ->and($command->lines)->toContain('control-plane-migration-application-name='.$identity->migration_application_name)
        ->and($command->lines)->toContain('control-plane-migration-attempt-lock='.$identity->migration_attempt_lock_identity)
        ->and($command->lines)->toContain('control-plane-schema-attestation=passed')
        ->and($command->errors)->toBe([]);
});

it('fails closed when another schema migration holds the shared PostgreSQL advisory lock', function () {
    $migrationNames = controlPlaneMigrationNames();
    $identity = configureControlPlaneMigrationEnvironment($migrationNames);
    $state = controlPlaneMigrationHarness($identity, $migrationNames);
    $state->advisoryLockAvailable = false;
    $command = controlPlaneMigrationCommand($state);

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($command->nestedCommand)->toBeNull()
        ->and($state->advisoryLockAcquisitions)->toBe([['coolify-control-plane-expand']])
        ->and($state->advisoryLockReleases)->toBe([])
        ->and($command->errors)->toBe([
            'Another schema migration holds the PostgreSQL advisory lock.',
        ]);
});

it('rejects live migration identity values that do not exactly derive from the operation and attempt', function () {
    $migrationNames = controlPlaneMigrationNames();
    $identity = configureControlPlaneMigrationEnvironment($migrationNames);
    putenv('CONTROL_PLANE_MIGRATION_ATTEMPT_LOCK_IDENTITY=coolify-control-plane-expand-attempt:wrong');
    $state = controlPlaneMigrationHarness($identity, $migrationNames);
    $command = controlPlaneMigrationCommand($state);

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($command->nestedCommand)->toBeNull()
        ->and($state->advisoryLockAcquisitions)->toBe([])
        ->and($command->errors)->toBe([
            'Control-plane live migration operation or attempt identity does not match its exact derived values.',
        ]);
});

it('releases the global lock when the exact attempt-scoped advisory lock is held', function () {
    $migrationNames = controlPlaneMigrationNames();
    $identity = configureControlPlaneMigrationEnvironment($migrationNames);
    $state = controlPlaneMigrationHarness($identity, $migrationNames);
    $state->advisoryLockAvailability = [true, false];
    $command = controlPlaneMigrationCommand($state);

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($command->nestedCommand)->toBeNull()
        ->and($state->advisoryLockAcquisitions)->toBe([
            ['coolify-control-plane-expand'],
            [$identity->migration_attempt_lock_identity],
        ])
        ->and($state->advisoryLockReleases)->toBe([
            ['coolify-control-plane-expand'],
        ])
        ->and($command->errors)->toBe([
            'Another control-plane migration attempt holds the PostgreSQL advisory lock.',
        ]);
});

it('releases the shared advisory lock when the nested migration command fails', function () {
    $migrationNames = controlPlaneMigrationNames();
    $identity = configureControlPlaneMigrationEnvironment($migrationNames);
    $state = controlPlaneMigrationHarness($identity, $migrationNames);
    $command = controlPlaneMigrationCommand($state, Command::FAILURE);

    expect(fn () => $command->handle())
        ->toThrow(RuntimeException::class, 'Authorized control-plane migration command failed with exit code 1.')
        ->and($command->nestedCommand)->not->toBeNull()
        ->and($state->advisoryLockAcquisitions)->toBe([
            ['coolify-control-plane-expand'],
            [$identity->migration_attempt_lock_identity],
        ])
        ->and($state->advisoryLockReleases)->toBe([
            [$identity->migration_attempt_lock_identity],
            ['coolify-control-plane-expand'],
        ]);
});

it('rejects a PostgreSQL session change before the shared advisory lock', function () {
    $migrationNames = controlPlaneMigrationNames();
    $identity = configureControlPlaneMigrationEnvironment($migrationNames);
    $state = controlPlaneMigrationHarness($identity, $migrationNames);
    $state->alternatePdo = new PDO('sqlite::memory:');
    $state->switchPdoOnCall = 3;
    $command = controlPlaneMigrationCommand($state);

    expect(fn () => $command->handle())
        ->toThrow(RuntimeException::class, 'Schema migration changed PostgreSQL sessions before the global advisory lock.')
        ->and($state->advisoryLockAcquisitions)->toBe([]);
});

it('rejects a nested migration that does not append the exact authorized migration ledger batch', function () {
    $migrationNames = controlPlaneMigrationNames();
    $identity = configureControlPlaneMigrationEnvironment($migrationNames);
    prepareControlPlaneMigrationSchema($migrationNames);
    $state = controlPlaneMigrationHarness($identity, $migrationNames);
    $command = controlPlaneMigrationCommand($state, afterNestedMigration: function (object $state): void {
        $state->ledgerRows[array_key_last($state->ledgerRows)]['batch'] = 3;
    });

    expect(fn () => $command->handle())
        ->toThrow(RuntimeException::class, 'Control-plane migrations did not append the exact ordered authorized migration ledger batch.')
        ->and($state->advisoryLockReleases)->toBe([
            [$identity->migration_attempt_lock_identity],
            ['coolify-control-plane-expand'],
        ]);
});

it('rejects a nested migration that changes a legacy table row count', function () {
    $migrationNames = controlPlaneMigrationNames();
    $identity = configureControlPlaneMigrationEnvironment($migrationNames);
    prepareControlPlaneMigrationSchema($migrationNames);
    $state = controlPlaneMigrationHarness($identity, $migrationNames);
    $state->legacyRowCounts = [
        ['application_settings' => 7, 'application_deployment_queues' => 11],
        ['application_settings' => 8, 'application_deployment_queues' => 11],
    ];
    $command = controlPlaneMigrationCommand($state);

    expect(fn () => $command->handle())
        ->toThrow(RuntimeException::class, 'Control-plane expand changed a legacy table row count.')
        ->and($state->legacyRowCountReads)->toBe([
            ['application_settings' => 7, 'application_deployment_queues' => 11],
            ['application_settings' => 8, 'application_deployment_queues' => 11],
        ]);
});

it('requires exactly one explicit live or rehearsal mode', function (string $liveMode, string $rehearsalMode) {
    $migrationNames = controlPlaneMigrationNames();
    configureControlPlaneMigrationEnvironment($migrationNames);
    putenv("CONTROL_PLANE_LIVE_EXPAND_MIGRATION={$liveMode}");
    putenv("CONTROL_PLANE_MIGRATION_REHEARSAL={$rehearsalMode}");
    $command = controlPlaneMigrationCommand((object) []);

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($command->nestedCommand)->toBeNull()
        ->and($command->errors)->toBe([
            'Control-plane expand requires exactly one live or rehearsal mode.',
        ]);
})->with([
    'neither mode' => ['false', 'false'],
    'both modes' => ['true', 'true'],
]);

it('rejects a rehearsal that resolves to the live PostgreSQL system identifier', function () {
    $migrationNames = controlPlaneMigrationNames();
    $identity = configureControlPlaneMigrationEnvironment($migrationNames, rehearsal: true);
    putenv("CONTROL_PLANE_LIVE_DATABASE_SYSTEM_IDENTIFIER={$identity->system_identifier}");
    $state = controlPlaneMigrationHarness($identity, $migrationNames);
    $command = controlPlaneMigrationCommand($state);

    expect($command->handle())->toBe(Command::FAILURE)
        ->and($command->nestedCommand)->toBeNull()
        ->and($command->errors)->toBe(['Control-plane database identity attestation failed.']);
});

it('returns success when migration is disabled', function () {
    config()->set('constants.migration.is_migration_enabled', false);
    $command = new class extends Migration
    {
        public function info($string, $verbosity = null) {}

        public function option($key = null): mixed
        {
            return false;
        }
    };

    expect($command->handle())->toBe(Command::SUCCESS);
});

it('returns failure when the Laravel migration isolation mutex is held', function () {
    $lock = Cache::store()->getStore()->lock('framework'.DIRECTORY_SEPARATOR.'command-migrate', 60);

    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('migrate', ['--force' => true, '--isolated' => 1])
            ->expectsOutputToContain('already running')
            ->assertExitCode(Command::FAILURE);
    } finally {
        $lock->release();
    }
});
