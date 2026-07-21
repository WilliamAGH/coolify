<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->name(),
            'ip' => fake()->unique()->ipv4(),
            'user' => 'root',
            'private_key_id' => 1,
        ];
    }
}
