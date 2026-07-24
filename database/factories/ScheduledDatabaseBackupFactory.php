<?php

namespace Database\Factories;

use App\Models\StandalonePostgresql;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class ScheduledDatabaseBackupFactory extends Factory
{
    public function definition(): array
    {
        return [
            'enabled' => true,
            'save_s3' => false,
            'frequency' => '0 0 * * *',
            'database_id' => StandalonePostgresql::factory(),
            'database_type' => StandalonePostgresql::class,
            'team_id' => Team::factory(),
        ];
    }
}
