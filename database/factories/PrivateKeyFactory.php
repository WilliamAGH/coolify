<?php

namespace Database\Factories;

use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PrivateKey>
 */
class PrivateKeyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'private_key' => generateSSHKey('ed25519')['private'],
            'is_git_related' => false,
            'team_id' => Team::factory(),
        ];
    }
}
