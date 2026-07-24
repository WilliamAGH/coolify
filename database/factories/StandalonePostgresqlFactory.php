<?php

namespace Database\Factories;

use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Factories\Factory;

class StandalonePostgresqlFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'image' => 'postgres:15-alpine',
            'postgres_user' => 'postgres',
            'postgres_password' => 'password',
            'postgres_db' => 'postgres',
            'environment_id' => 1,
            'destination_id' => StandaloneDocker::factory(),
            'destination_type' => (new StandaloneDocker)->getMorphClass(),
        ];
    }
}
