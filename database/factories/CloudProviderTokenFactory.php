<?php

namespace Database\Factories;

use App\Models\CloudProviderToken;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CloudProviderToken>
 */
class CloudProviderTokenFactory extends Factory
{
    protected $model = CloudProviderToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'provider' => fake()->randomElement(['hetzner', 'digitalocean']),
            'token' => fake()->sha256(),
            'name' => fake()->unique()->words(3, true),
        ];
    }
}
