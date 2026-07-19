<?php

namespace App\Console\Commands;

use App\Support\ControlPlaneMigrationInventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class Migration extends Command
{
    protected $signature = 'start:migration';

    protected $description = 'Start Migration';

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

    public function handle(): int
    {
        if (! config('constants.migration.is_migration_enabled')) {
            $this->info('Migration is disabled on this server.');

            return self::SUCCESS;
        }

        $this->info('Migration is enabled on this server.');
        if (! $this->waitForDatabase()) {
            return self::FAILURE;
        }

        $exitCode = $this->runMigrations();
        if ($exitCode !== self::SUCCESS) {
            $this->error("Database migrations failed with exit code {$exitCode}; startup remains blocked.");
        }

        return $exitCode;
    }

    protected function waitForDatabase(): bool
    {
        $attempts = $this->databaseReadyAttempts();
        $retrySeconds = $this->databaseReadyRetrySeconds();

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $this->line("Checking database readiness (attempt {$attempt}/{$attempts}).");
            if ($this->databaseIsReady()) {
                $this->info("Database is ready after {$attempt} attempt(s).");

                return true;
            }

            if ($attempt < $attempts) {
                $this->warn("Database is unavailable; retrying in {$retrySeconds} second(s).");
                $this->waitForNextDatabaseAttempt($retrySeconds);
            }
        }

        $this->error("Database did not become ready after {$attempts} attempt(s); startup remains blocked.");

        return false;
    }

    protected function databaseIsReady(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (Throwable) {
            DB::purge();

            return false;
        }
    }

    protected function databaseReadyAttempts(): int
    {
        return 30;
    }

    protected function databaseReadyRetrySeconds(): int
    {
        return 2;
    }

    protected function waitForNextDatabaseAttempt(int $seconds): void
    {
        sleep($seconds);
    }

    protected function runMigrations(): int
    {
        return $this->call('migrate', ['--force' => true, '--isolated' => true]);
    }
}
