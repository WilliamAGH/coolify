<?php

use App\Console\Commands\Migration;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;

function databaseMigrationReadinessCommand(
    array $readiness,
    int $migrationExitCode = 0,
): Migration {
    return new class($readiness, $migrationExitCode) extends Migration
    {
        public int $migrationRuns = 0;

        public int $waits = 0;

        /** @param list<bool> $readiness */
        public function __construct(
            private array $readiness,
            private readonly int $migrationExitCode,
        ) {
            parent::__construct();
        }

        protected function databaseIsReady(): bool
        {
            return array_shift($this->readiness) ?? false;
        }

        protected function databaseReadyAttempts(): int
        {
            return 3;
        }

        protected function databaseReadyRetrySeconds(): int
        {
            return 0;
        }

        protected function waitForNextDatabaseAttempt(int $seconds): void
        {
            $this->waits++;
        }

        protected function runMigrations(): int
        {
            $this->migrationRuns++;

            return $this->migrationExitCode;
        }
    };
}

function executeDatabaseMigrationReadinessCommand(Migration $command): CommandTester
{
    $command->setLaravel(app());
    $tester = new CommandTester($command);
    $tester->execute([]);

    return $tester;
}

it('bounds the production database readiness window to less than one minute', function (): void {
    $command = new class extends Migration
    {
        /** @return array{attempts: int, retry_seconds: int} */
        public function readinessContract(): array
        {
            return [
                'attempts' => $this->databaseReadyAttempts(),
                'retry_seconds' => $this->databaseReadyRetrySeconds(),
            ];
        }
    };
    $contract = $command->readinessContract();

    expect($contract)->toBe(['attempts' => 30, 'retry_seconds' => 2])
        ->and(($contract['attempts'] - 1) * $contract['retry_seconds'])->toBeLessThanOrEqual(60);
});

it('purges a failed default connection so the next readiness attempt reconnects', function (): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('getPdo')->once()->andThrow(new RuntimeException('database unavailable'));
    DB::shouldReceive('connection')->once()->andReturn($connection);
    DB::shouldReceive('purge')->once();
    $command = new class extends Migration
    {
        public function probeDatabase(): bool
        {
            return $this->databaseIsReady();
        }
    };

    expect($command->probeDatabase())->toBeFalse();
});

it('reconnects when PostgreSQL becomes reachable after application startup', function (): void {
    if (config('database.default') !== 'pgsql') {
        expect(config('database.default'))->not->toBe('pgsql');

        return;
    }

    $originalPort = config('database.connections.pgsql.port');
    config([
        'constants.migration.is_migration_enabled' => true,
        'database.connections.pgsql.port' => 1,
    ]);
    DB::purge('pgsql');
    $command = new class($originalPort) extends Migration
    {
        public int $waits = 0;

        public function __construct(private readonly mixed $readyPort)
        {
            parent::__construct();
        }

        protected function databaseReadyAttempts(): int
        {
            return 3;
        }

        protected function databaseReadyRetrySeconds(): int
        {
            return 0;
        }

        protected function waitForNextDatabaseAttempt(int $seconds): void
        {
            $this->waits++;
            config(['database.connections.pgsql.port' => $this->readyPort]);
            DB::purge('pgsql');
        }

        protected function runMigrations(): int
        {
            return self::SUCCESS;
        }
    };

    try {
        $tester = executeDatabaseMigrationReadinessCommand($command);

        expect($tester->getStatusCode())->toBe(0)
            ->and($command->waits)->toBe(1)
            ->and($tester->getDisplay())->toContain(
                'Database is unavailable; retrying in 0 second(s).',
                'Database is ready after 2 attempt(s).',
            );
    } finally {
        config(['database.connections.pgsql.port' => $originalPort]);
        DB::purge('pgsql');
    }
});

it('waits for Postgres to start after the application before running migrations', function (): void {
    config(['constants.migration.is_migration_enabled' => true]);
    $command = databaseMigrationReadinessCommand([false, false, true]);

    $tester = executeDatabaseMigrationReadinessCommand($command);

    expect($tester->getStatusCode())->toBe(0)
        ->and($command->waits)->toBe(2)
        ->and($command->migrationRuns)->toBe(1)
        ->and($tester->getDisplay())->toContain(
            'Checking database readiness (attempt 1/3).',
            'Database is unavailable; retrying in 0 second(s).',
            'Database is ready after 3 attempt(s).',
        );
});

it('fails the startup gate after the bounded readiness window', function (): void {
    config(['constants.migration.is_migration_enabled' => true]);
    $command = databaseMigrationReadinessCommand([false, false, false]);

    $tester = executeDatabaseMigrationReadinessCommand($command);

    expect($tester->getStatusCode())->toBe(1)
        ->and($command->waits)->toBe(2)
        ->and($command->migrationRuns)->toBe(0)
        ->and($tester->getDisplay())->toContain(
            'Checking database readiness (attempt 3/3).',
            'Database did not become ready after 3 attempt(s); startup remains blocked.',
        );
});

it('propagates migration failures after the database becomes ready', function (): void {
    config(['constants.migration.is_migration_enabled' => true]);
    $command = databaseMigrationReadinessCommand([true], migrationExitCode: 42);

    $tester = executeDatabaseMigrationReadinessCommand($command);

    expect($tester->getStatusCode())->toBe(42)
        ->and($command->migrationRuns)->toBe(1)
        ->and($tester->getDisplay())->toContain(
            'Database is ready after 1 attempt(s).',
            'Database migrations failed with exit code 42; startup remains blocked.',
        );
});

it('skips readiness and migration work when migrations are disabled', function (): void {
    config(['constants.migration.is_migration_enabled' => false]);
    $command = databaseMigrationReadinessCommand([true]);

    $tester = executeDatabaseMigrationReadinessCommand($command);

    expect($tester->getStatusCode())->toBe(0)
        ->and($command->waits)->toBe(0)
        ->and($command->migrationRuns)->toBe(0)
        ->and($tester->getDisplay())->toContain('Migration is disabled on this server.');
});
