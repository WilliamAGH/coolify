<?php

namespace App\Console\Commands;

use App\Exceptions\ControlPlaneMutationLockedException;
use App\Support\ControlPlaneMode;
use Illuminate\Console\Command;

class Seeder extends Command
{
    protected $signature = 'start:seeder';

    protected $description = 'Start Seeder';

    public function handle(): int
    {
        if (! ControlPlaneMode::startupWorkAllowed()) {
            $this->info('Control plane startup work is disabled: seeding skipped.');

            return self::SUCCESS;
        }

        if (config('constants.seeder.is_seeder_enabled')) {
            $this->info('Seeder is enabled on this server.');

            try {
                return $this->call('db:seed', ['--class' => 'ProductionSeeder', '--force' => true]);
            } catch (ControlPlaneMutationLockedException) {
                $this->info('Control plane startup work is disabled: seeding skipped.');

                return self::SUCCESS;
            }
        }

        $this->info('Seeder is disabled on this server.');

        return self::SUCCESS;
    }
}
