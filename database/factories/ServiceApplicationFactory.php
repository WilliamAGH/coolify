<?php

namespace Database\Factories;

use App\Models\Service;
use App\Models\ServiceApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceApplication>
 */
class ServiceApplicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'service_id' => Service::factory(),
            'name' => fake()->unique()->slug(2),
            'image' => 'nginx:alpine',
        ];
    }
}
