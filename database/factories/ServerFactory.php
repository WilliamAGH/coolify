<?php

namespace Database\Factories;

use App\Models\PrivateKey;
use App\Models\Team;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Same reason as ApplicationFactory: a faker person name can carry an
            // apostrophe, which the product's own name validation refuses.
            'name' => ValidationPatterns::toName(fake()->unique()->name()),
            'ip' => fake()->unique()->ipv4(),
            'user' => 'root',
            'team_id' => Team::factory(),
            // The evaluated team also owns the related key.
            'private_key_id' => fn (array $attributes) => PrivateKey::factory()
                ->create(['team_id' => $attributes['team_id']])
                ->getKey(),
        ];
    }
}
